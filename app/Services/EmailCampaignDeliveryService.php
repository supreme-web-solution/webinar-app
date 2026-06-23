<?php

namespace App\Services;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailCampaignDeliveryService
{
    /**
     * @return array{sent_recipient_ids: array<int, int>, skipped_recipient_ids: array<int, int>, attempted: int}
     */
    public function sendBatch(EmailCampaign $campaign, iterable $recipients): array
    {
        $list = [];
        foreach ($recipients as $recipient) {
            if ($recipient instanceof EmailCampaignRecipient) {
                $list[] = $recipient;
            }
        }

        if ($list === []) {
            Log::warning('email_campaign.provider.batch.skipped_empty_recipients', [
                'campaign_id' => $campaign->id,
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => 0,
            ];
        }

        /** @var User|null $owner */
        $owner = $campaign->relationLoaded('user') ? $campaign->user : $campaign->user()->first();
        if (! $owner) {
            Log::warning('email_campaign.provider.batch.skipped_missing_owner', [
                'campaign_id' => $campaign->id,
                'recipient_count' => count($list),
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => count($list),
            ];
        }

        $skippedRecipientIds = [];
        $deliverable = [];

        foreach ($list as $recipient) {
            if (! $this->isDeliverableEmail($recipient->email)) {
                $skippedRecipientIds[] = $recipient->id;
                Log::warning('email_campaign.recipient.skipped_invalid_email', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                ]);

                continue;
            }

            $deliverable[] = $recipient;
        }

        $attempted = count($list);
        $primary = $this->resolvePrimaryProvider($owner);
        $fallback = $this->resolveFallbackProvider();

        Log::info('email_campaign.provider.batch.dispatching', [
            'campaign_id' => $campaign->id,
            'subject' => $campaign->prefixedTitleLine(),
            'provider' => $primary,
            'fallback_provider' => $fallback,
            'recipient_count' => count($deliverable),
            'skipped_invalid_count' => count($skippedRecipientIds),
        ]);

        $result = [
            'sent_recipient_ids' => [],
            'skipped_recipient_ids' => $skippedRecipientIds,
            'attempted' => $attempted,
        ];

        if ($deliverable !== []) {
            $providerResult = $this->sendBatchUsingProvider($primary, $campaign, $owner, $deliverable);
            $result['sent_recipient_ids'] = $providerResult['sent_recipient_ids'];
            $result['skipped_recipient_ids'] = array_values(array_unique([
                ...$result['skipped_recipient_ids'],
                ...$providerResult['skipped_recipient_ids'],
            ]));

            $terminalLookup = array_flip([
                ...$result['sent_recipient_ids'],
                ...$result['skipped_recipient_ids'],
            ]);
            $remaining = array_values(array_filter(
                $deliverable,
                fn (EmailCampaignRecipient $recipient): bool => ! isset($terminalLookup[$recipient->id])
            ));

            if ($fallback !== null && $fallback !== $primary && $remaining !== []) {
                Log::warning('email_campaign.provider.batch.fallback', [
                    'campaign_id' => $campaign->id,
                    'failed_provider' => $primary,
                    'fallback_provider' => $fallback,
                    'remaining' => count($remaining),
                ]);

                $fallbackResult = $this->sendBatchUsingProvider($fallback, $campaign, $owner, $remaining);
                $result['sent_recipient_ids'] = array_values(array_unique([
                    ...$result['sent_recipient_ids'],
                    ...$fallbackResult['sent_recipient_ids'],
                ]));
                $result['skipped_recipient_ids'] = array_values(array_unique([
                    ...$result['skipped_recipient_ids'],
                    ...$fallbackResult['skipped_recipient_ids'],
                ]));
            }
        }

        $sentCount = count($result['sent_recipient_ids']);
        $skippedCount = count($result['skipped_recipient_ids']);

        Log::info('email_campaign.provider.batch.completed', [
            'campaign_id' => $campaign->id,
            'subject' => $campaign->prefixedTitleLine(),
            'provider' => $primary,
            'attempted' => $attempted,
            'sent_count' => $sentCount,
            'skipped_count' => $skippedCount,
            'failed_count' => max(0, $attempted - $sentCount - $skippedCount),
        ]);

        return $result;
    }

    /**
     * @param  array<int, EmailCampaignRecipient>  $recipients
     * @return array{sent_recipient_ids: array<int, int>, skipped_recipient_ids: array<int, int>, attempted: int}
     */
    private function sendBatchUsingProvider(string $provider, EmailCampaign $campaign, User $owner, array $recipients): array
    {
        return match ($provider) {
            'postmark' => $this->sendBatchViaPostmark($campaign, $recipients),
            'elastic', 'elasticemail' => $this->sendBatchViaElasticEmail($campaign, $recipients),
            'zeptomail' => $this->sendBatchViaZeptoMail($campaign, $recipients),
            'sendgrid' => $this->sendBatchViaSendGrid($campaign, $recipients),
            'ses_smtp', 'smtp' => $this->sendBatchViaSmtp($campaign, $owner, $recipients),
            default => $this->sendBatchViaResend($campaign, $recipients),
        };
    }

    /**
     * @param  array<int, EmailCampaignRecipient>  $recipients
     * @return array{sent_recipient_ids: array<int, int>, skipped_recipient_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaResend(EmailCampaign $campaign, array $recipients): array
    {
        $apiKey = (string) config('services.resend.key');
        $configuredFrom = (string) config('services.resend.from', 'onboarding@resend.dev');

        if ($apiKey === '') {
            Log::warning('RESEND_API_KEY not configured. Skipping campaign emails.', [
                'campaign_id' => $campaign->id,
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => count($recipients),
            ];
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $campaign->sender_name);
        $emails = [];
        $recipientIds = [];

        foreach ($recipients as $recipient) {
            $recipientIds[] = $recipient->id;
            $emails[] = [
                'from' => $from,
                'to' => [$recipient->email],
                'subject' => $campaign->prefixedTitleLine(),
                'html' => $this->buildCampaignEmailHtml($campaign, $recipient),
            ];
        }

        try {
            $response = $this->postWithRateLimitRetry($apiKey, 'emails/batch', $emails);
            if (! $response || $response->failed()) {
                Log::warning('Resend campaign batch API request failed.', [
                    'campaign_id' => $campaign->id,
                    'attempted' => count($emails),
                    'status' => $response?->status(),
                    'body' => $response?->body(),
                ]);

                return [
                    'sent_recipient_ids' => [],
                    'skipped_recipient_ids' => [],
                    'attempted' => count($emails),
                ];
            }
        } catch (\Throwable $exception) {
            Log::error('Resend campaign batch API exception.', [
                'campaign_id' => $campaign->id,
                'attempted' => count($emails),
                'message' => $exception->getMessage(),
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => count($emails),
            ];
        }

        Log::info('email_campaign.provider.resend.batch.sent', [
            'campaign_id' => $campaign->id,
            'attempted' => count($emails),
            'sent_count' => count($recipientIds),
        ]);

        return [
            'sent_recipient_ids' => $recipientIds,
            'skipped_recipient_ids' => [],
            'attempted' => count($emails),
        ];
    }

    /**
     * @param  array<int, EmailCampaignRecipient>  $recipients
     * @return array{sent_recipient_ids: array<int, int>, skipped_recipient_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaPostmark(EmailCampaign $campaign, array $recipients): array
    {
        $apiKey = $this->postmarkApiKey();
        $configuredFrom = (string) config('services.postmark.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('POSTMARK_API_KEY not configured. Skipping campaign emails.', [
                'campaign_id' => $campaign->id,
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => count($recipients),
            ];
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $campaign->sender_name);
        $messageStreamId = trim((string) config('services.postmark.message_stream_id', ''));
        $subject = $campaign->prefixedTitleLine();
        $messages = [];
        $recipientIds = [];
        $recipientById = [];
        $userId = (int) ($campaign->user_id ?? $campaign->user?->id ?? 0);

        foreach ($recipients as $recipient) {
            $recipientIds[] = $recipient->id;
            $recipientById[$recipient->id] = $recipient;
            $message = [
                'From' => $from,
                'To' => $recipient->email,
                'Subject' => $subject,
                'HtmlBody' => $this->buildCampaignEmailHtml($campaign, $recipient),
                'Metadata' => $this->postmarkCampaignMetadata($campaign, $recipient),
            ];

            if ($messageStreamId !== '') {
                $message['MessageStream'] = $messageStreamId;
            }

            $messages[] = $message;
        }

        try {
            $response = $this->postToPostmarkWithRateLimitRetry($apiKey, 'email/batch', $messages);
            if (! $response || $response->failed()) {
                Log::warning('Postmark campaign batch API request failed.', [
                    'campaign_id' => $campaign->id,
                    'attempted' => count($messages),
                    'status' => $response?->status(),
                    'body' => $response?->body(),
                ]);

                return [
                    'sent_recipient_ids' => [],
                    'skipped_recipient_ids' => [],
                    'attempted' => count($messages),
                ];
            }
        } catch (\Throwable $exception) {
            Log::error('Postmark campaign batch API exception.', [
                'campaign_id' => $campaign->id,
                'attempted' => count($messages),
                'message' => $exception->getMessage(),
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => count($messages),
            ];
        }

        $body = $response->json();
        if (! is_array($body) || ! array_is_list($body)) {
            Log::warning('Postmark campaign batch API response was not a result list.', [
                'campaign_id' => $campaign->id,
                'attempted' => count($messages),
                'body' => $response->body(),
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => count($messages),
            ];
        }

        $sentIds = [];
        $skippedIds = [];
        $acceptedRecords = [];
        $suppressedRecords = [];

        foreach ($body as $index => $result) {
            $errorCode = (int) data_get($result, 'ErrorCode', 0);
            if ($errorCode === 0 && isset($recipientIds[$index])) {
                $recipientId = $recipientIds[$index];
                $sentIds[] = $recipientId;
                $recipient = $recipientById[$recipientId] ?? null;
                $messageId = trim((string) data_get($result, 'MessageID', ''));

                if ($messageId !== '' && $recipient instanceof EmailCampaignRecipient && $userId > 0) {
                    $acceptedRecords[] = [
                        'message_id' => $messageId,
                        'email' => $recipient->email,
                        'user_id' => $userId,
                        'source_type' => 'campaign_recipient',
                        'campaign_id' => $campaign->id,
                        'recipient_id' => $recipient->id,
                        'email_type' => 'campaign',
                        'subject' => $subject,
                    ];
                }

                continue;
            }

            $recipientId = $recipientIds[$index] ?? null;

            if ($recipientId !== null && $this->isPermanentPostmarkRecipientError($errorCode)) {
                $skippedIds[] = $recipientId;
                $recipient = $recipientById[$recipientId] ?? null;

                if ($recipient instanceof EmailCampaignRecipient && $userId > 0) {
                    $suppressedRecords[] = [
                        'email' => $recipient->email,
                        'user_id' => $userId,
                        'source_type' => 'campaign_recipient',
                        'campaign_id' => $campaign->id,
                        'recipient_id' => $recipient->id,
                        'email_type' => 'campaign',
                        'subject' => $subject,
                    ];
                }

                Log::warning('email_campaign.recipient.skipped_permanent_failure', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipientId,
                    'error_code' => $errorCode,
                    'message' => data_get($result, 'Message'),
                ]);

                continue;
            }

            Log::warning('Postmark campaign recipient failed.', [
                'campaign_id' => $campaign->id,
                'recipient_id' => $recipientId,
                'error_code' => $errorCode,
                'message' => data_get($result, 'Message'),
            ]);
        }

        Log::info('email_campaign.provider.postmark.batch.sent', [
            'campaign_id' => $campaign->id,
            'attempted' => count($messages),
            'sent_count' => count($sentIds),
            'skipped_count' => count($skippedIds),
        ]);

        $tracking = app(PostmarkDeliveryTrackingService::class);
        $tracking->recordAccepted($acceptedRecords);
        $tracking->recordSuppressedAtApi($suppressedRecords);

        return [
            'sent_recipient_ids' => $sentIds,
            'skipped_recipient_ids' => $skippedIds,
            'attempted' => count($messages),
        ];
    }

    /**
     * @param  array<int, EmailCampaignRecipient>  $recipients
     * @return array{sent_recipient_ids: array<int, int>, skipped_recipient_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaElasticEmail(EmailCampaign $campaign, array $recipients): array
    {
        $apiKey = $this->elasticApiKey();
        $configuredFrom = (string) config('services.elastic.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('ELASTICEMAIL_API_KEY not configured. Skipping campaign emails.', [
                'campaign_id' => $campaign->id,
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => count($recipients),
            ];
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $campaign->sender_name);
        $subject = $campaign->prefixedTitleLine();
        $sentIds = [];

        foreach ($recipients as $recipient) {
            $payload = $this->buildElasticEmailPayload(
                $from,
                $recipient->email,
                $subject,
                $this->buildCampaignEmailHtml($campaign, $recipient),
            );

            try {
                $response = $this->postToElasticEmailWithRateLimitRetry($apiKey, 'emails', $payload);

                if ($this->elasticEmailResponseSucceeded($response)) {
                    $sentIds[] = $recipient->id;
                } else {
                    Log::warning('Elastic Email campaign recipient failed.', [
                        'campaign_id' => $campaign->id,
                        'recipient_id' => $recipient->id,
                        'status' => $response?->status(),
                        'body' => $response?->body(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::error('Elastic Email campaign API exception.', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($sentIds !== []) {
            Log::info('email_campaign.provider.elastic.batch.sent', [
                'campaign_id' => $campaign->id,
                'attempted' => count($recipients),
                'sent_count' => count($sentIds),
            ]);
        }

        return [
            'sent_recipient_ids' => $sentIds,
            'skipped_recipient_ids' => [],
            'attempted' => count($recipients),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildElasticEmailPayload(string $from, string $to, string $subject, string $html): array
    {
        $payload = [
            'Recipients' => [
                ['Email' => $to],
            ],
            'Content' => [
                'Body' => [
                    [
                        'ContentType' => 'HTML',
                        'Content' => $html,
                        'Charset' => 'utf-8',
                    ],
                ],
                'From' => $from,
                'Subject' => $subject,
            ],
        ];

        $channelName = trim((string) config('services.elastic.channel', ''));
        if ($channelName !== '') {
            $payload['Options'] = ['ChannelName' => $channelName];
        }

        return $payload;
    }

    private function elasticEmailResponseSucceeded(?Response $response): bool
    {
        if (! $response || $response->failed()) {
            return false;
        }

        $body = $response->json();
        if (! is_array($body)) {
            return false;
        }

        $transactionId = trim((string) data_get($body, 'TransactionID', ''));
        $messageId = trim((string) data_get($body, 'MessageID', ''));

        return $transactionId !== '' || $messageId !== '';
    }

    private function elasticApiKey(): string
    {
        return (string) config('services.elastic.key', '');
    }

    /**
     * @param  array<int, EmailCampaignRecipient>  $recipients
     * @return array{sent_recipient_ids: array<int, int>, skipped_recipient_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaZeptoMail(EmailCampaign $campaign, array $recipients): array
    {
        $apiKey = $this->zeptoMailApiKey();
        $configuredFrom = (string) config('services.zeptomail.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('ZEPTOMAIL_API_KEY not configured. Skipping campaign emails.', [
                'campaign_id' => $campaign->id,
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => count($recipients),
            ];
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $campaign->sender_name);
        $subject = $campaign->prefixedTitleLine();
        $sentIds = [];

        foreach ($recipients as $recipient) {
            $payload = $this->buildZeptoMailPayload(
                $from,
                $recipient->email,
                $recipient->name,
                $subject,
                $this->buildCampaignEmailHtml($campaign, $recipient),
            );

            try {
                $response = $this->postToZeptoMailWithRateLimitRetry($apiKey, $payload);

                if ($this->zeptoMailResponseSucceeded($response)) {
                    $sentIds[] = $recipient->id;
                } else {
                    Log::warning('ZeptoMail campaign recipient failed.', [
                        'campaign_id' => $campaign->id,
                        'recipient_id' => $recipient->id,
                        'status' => $response?->status(),
                        'body' => $response?->body(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::error('ZeptoMail campaign API exception.', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($sentIds !== []) {
            Log::info('email_campaign.provider.zeptomail.batch.sent', [
                'campaign_id' => $campaign->id,
                'attempted' => count($recipients),
                'sent_count' => count($sentIds),
            ]);
        }

        return [
            'sent_recipient_ids' => $sentIds,
            'skipped_recipient_ids' => [],
            'attempted' => count($recipients),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildZeptoMailPayload(string $from, string $to, ?string $toName, string $subject, string $html): array
    {
        $recipient = [
            'email_address' => [
                'address' => $to,
            ],
        ];

        $recipientName = trim((string) $toName);
        if ($recipientName !== '') {
            $recipient['email_address']['name'] = $recipientName;
        }

        return [
            'from' => [
                'address' => $this->extractEmailAddress($from),
                'name' => $this->extractDisplayName($from) ?: 'OnPage CV',
            ],
            'to' => [$recipient],
            'subject' => $subject,
            'htmlbody' => $html,
        ];
    }

    private function zeptoMailResponseSucceeded(?Response $response): bool
    {
        if (! $response || $response->failed()) {
            return false;
        }

        $body = $response->json();
        if (! is_array($body)) {
            return false;
        }

        if (data_get($body, 'error') !== null) {
            return false;
        }

        $requestId = trim((string) data_get($body, 'request_id', ''));
        $data = data_get($body, 'data');

        return $requestId !== '' || (is_array($data) && $data !== []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postToZeptoMailWithRateLimitRetry(string $apiKey, array $payload, int $attempt = 0): ?Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Zoho-enczapikey '.$apiKey,
        ])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->post('https://api.zeptomail.com/v1.1/email', $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        if ($attempt >= 3) {
            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToZeptoMailWithRateLimitRetry($apiKey, $payload, $attempt + 1);
    }

    private function zeptoMailApiKey(): string
    {
        return (string) config('services.zeptomail.key', '');
    }

    /**
     * @param  array<int, EmailCampaignRecipient>  $recipients
     * @return array{sent_recipient_ids: array<int, int>, skipped_recipient_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaSendGrid(EmailCampaign $campaign, array $recipients): array
    {
        $apiKey = $this->sendGridApiKey();
        $configuredFrom = (string) config('services.sendgrid.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('SENDGRID_API_KEY not configured. Skipping campaign emails.', [
                'campaign_id' => $campaign->id,
            ]);

            return [
                'sent_recipient_ids' => [],
                'skipped_recipient_ids' => [],
                'attempted' => count($recipients),
            ];
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $campaign->sender_name);
        $subject = $campaign->prefixedTitleLine();
        $sentIds = [];

        foreach ($recipients as $recipient) {
            $payload = $this->buildSendGridPayload(
                $from,
                $recipient->email,
                $recipient->name,
                $subject,
                $this->buildCampaignEmailHtml($campaign, $recipient),
                $this->sendGridCampaignMetadata($campaign, $recipient),
            );

            try {
                $response = $this->postToSendGridWithRateLimitRetry($apiKey, $payload);

                if ($this->sendGridResponseSucceeded($response)) {
                    $sentIds[] = $recipient->id;
                } else {
                    Log::warning('SendGrid campaign recipient failed.', [
                        'campaign_id' => $campaign->id,
                        'recipient_id' => $recipient->id,
                        'status' => $response?->status(),
                        'body' => $response?->body(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::error('SendGrid campaign API exception.', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($sentIds !== []) {
            Log::info('email_campaign.provider.sendgrid.batch.sent', [
                'campaign_id' => $campaign->id,
                'attempted' => count($recipients),
                'sent_count' => count($sentIds),
            ]);
        }

        return [
            'sent_recipient_ids' => $sentIds,
            'skipped_recipient_ids' => [],
            'attempted' => count($recipients),
        ];
    }

    /**
     * @param  array<string, string>  $customArgs
     * @return array<string, mixed>
     */
    private function buildSendGridPayload(string $from, string $to, ?string $toName, string $subject, string $html, array $customArgs = []): array
    {
        $toRecipient = ['email' => $to];
        $recipientName = trim((string) $toName);
        if ($recipientName !== '') {
            $toRecipient['name'] = $recipientName;
        }

        $personalization = [
            'to' => [$toRecipient],
            'subject' => $subject,
        ];

        if ($customArgs !== []) {
            $personalization['custom_args'] = $customArgs;
        }

        return [
            'personalizations' => [$personalization],
            'from' => [
                'email' => $this->extractEmailAddress($from),
                'name' => $this->extractDisplayName($from) ?: 'OnPage CV',
            ],
            'content' => [
                [
                    'type' => 'text/html',
                    'value' => $html,
                ],
            ],
        ];
    }

    private function sendGridResponseSucceeded(?Response $response): bool
    {
        return $response !== null && $response->status() === 202;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postToSendGridWithRateLimitRetry(string $apiKey, array $payload, int $attempt = 0): ?Response
    {
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->post('https://api.sendgrid.com/v3/mail/send', $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        if ($attempt >= 3) {
            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToSendGridWithRateLimitRetry($apiKey, $payload, $attempt + 1);
    }

    private function sendGridApiKey(): string
    {
        return (string) config('services.sendgrid.key', '');
    }

    /**
     * @return array<string, string>
     */
    private function sendGridCampaignMetadata(EmailCampaign $campaign, EmailCampaignRecipient $recipient): array
    {
        return [
            'source' => 'campaign',
            'campaign_id' => (string) $campaign->id,
            'recipient_id' => (string) $recipient->id,
            'email_type' => 'campaign',
        ];
    }

    /**
     * @param  array<int, EmailCampaignRecipient>  $recipients
     * @return array{sent_recipient_ids: array<int, int>, skipped_recipient_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaSmtp(EmailCampaign $campaign, User $owner, array $recipients): array
    {
        $smtpConfig = $this->resolveSmtpTransportConfigFromUser($owner);
        $usingUserSmtp = $smtpConfig !== null;
        $mailer = (string) config('services.email.ses_smtp_mailer', 'ses');
        $fromAddress = $usingUserSmtp
            ? (string) ($smtpConfig['from_address'] ?? config('mail.from.address'))
            : (string) config('services.email.ses_smtp_from_address', config('mail.from.address'));
        $fromName = $usingUserSmtp
            ? (string) ($smtpConfig['from_name'] ?? config('mail.from.name'))
            : (string) config('services.email.ses_smtp_from_name', config('mail.from.name'));
        $dynamicFromName = trim($campaign->sender_name) !== ''
            ? trim($campaign->sender_name).' via '.$fromName
            : $fromName;

        $sentIds = [];
        foreach ($recipients as $recipient) {
            try {
                $mailSender = $usingUserSmtp
                    ? app('mail.manager')->build($smtpConfig['transport'])
                    : Mail::mailer($mailer);

                $mailSender->send([], [], function ($message) use (
                    $recipient,
                    $campaign,
                    $fromAddress,
                    $dynamicFromName
                ): void {
                    $message->to($recipient->email);
                    $message->subject($campaign->prefixedTitleLine());
                    $message->from($fromAddress, $dynamicFromName);
                    $message->html($this->buildCampaignEmailHtml($campaign, $recipient));
                });

                $sentIds[] = $recipient->id;
            } catch (\Throwable $exception) {
                Log::error('smtp.campaign.single.failed', [
                    'mailer' => $usingUserSmtp ? 'user_smtp' : $mailer,
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        Log::info('email_campaign.provider.smtp.batch.sent', [
            'campaign_id' => $campaign->id,
            'mailer' => $usingUserSmtp ? 'user_smtp' : $mailer,
            'attempted' => count($recipients),
            'sent_count' => count($sentIds),
        ]);

        return [
            'sent_recipient_ids' => $sentIds,
            'skipped_recipient_ids' => [],
            'attempted' => count($recipients),
        ];
    }

    private function isDeliverableEmail(string $email): bool
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isPermanentPostmarkRecipientError(int $errorCode): bool
    {
        return in_array($errorCode, [300, 406], true);
    }

    /**
     * @return array<string, string>
     */
    private function postmarkCampaignMetadata(EmailCampaign $campaign, EmailCampaignRecipient $recipient): array
    {
        return [
            'source' => 'campaign',
            'campaign_id' => (string) $campaign->id,
            'recipient_id' => (string) $recipient->id,
            'email_type' => 'campaign',
        ];
    }

    private function resolvePrimaryProvider(User $owner): string
    {
        if ($this->resolveSmtpTransportConfigFromUser($owner) !== null) {
            return 'smtp';
        }

        $provider = strtolower(trim((string) config('services.email.primary', 'postmark')));

        return in_array($provider, ['resend', 'postmark', 'elastic', 'elasticemail', 'zeptomail', 'sendgrid', 'ses_smtp', 'smtp'], true)
            ? $this->normalizeEmailProvider($provider)
            : 'postmark';
    }

    private function resolveFallbackProvider(): ?string
    {
        $provider = strtolower(trim((string) config('services.email.fallback', 'resend')));
        if ($provider === '' || $provider === 'none') {
            return null;
        }

        return in_array($provider, ['resend', 'postmark', 'elastic', 'elasticemail', 'zeptomail', 'sendgrid', 'ses_smtp', 'smtp'], true)
            ? $this->normalizeEmailProvider($provider)
            : null;
    }

    private function normalizeEmailProvider(string $provider): string
    {
        return in_array($provider, ['elastic', 'elasticemail'], true) ? 'elastic' : $provider;
    }

    /**
     * @return array{transport: array<string, mixed>, from_address: string, from_name: string}|null
     */
    private function resolveSmtpTransportConfigFromUser(User $owner): ?array
    {
        if (! $owner->smtp_enabled) {
            return null;
        }

        $host = trim((string) ($owner->smtp_host ?? ''));
        $port = (int) ($owner->smtp_port ?? 0);
        $fromAddress = trim((string) ($owner->smtp_from_address ?? ''));
        $fromName = trim((string) ($owner->smtp_from_name ?? ''));

        if ($host === '' || $port <= 0 || $fromAddress === '' || $fromName === '') {
            return null;
        }

        $encryption = trim((string) ($owner->smtp_encryption ?? 'tls'));
        $encryption = $encryption === 'none' ? '' : $encryption;

        return [
            'transport' => [
                'transport' => 'smtp',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption === '' ? null : $encryption,
                'username' => $owner->smtp_username ?: null,
                'password' => $owner->smtp_password ?: null,
                'timeout' => null,
            ],
            'from_address' => $fromAddress,
            'from_name' => $fromName,
        ];
    }

    private function buildCampaignEmailHtml(EmailCampaign $campaign, EmailCampaignRecipient $recipient): string
    {
        $trackLink = route('email.campaign.click', ['token' => $recipient->access_token]);
        $unsubscribeLink = route('email.campaign.unsubscribe', ['token' => $recipient->access_token]);
        $bodyHtml = $this->formatBodyForEmail((string) ($campaign->body ?? ''));
        $title = e($campaign->prefixedTitleLine());
        $ctaLabel = e(trim((string) $campaign->cta_label) !== '' ? (string) $campaign->cta_label : 'Open Link');

        return "
            <div style=\"background:#f3f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#111827;\">
                <div style=\"max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;\">
                    <div style=\"background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:18px 22px;\">
                        <h1 style=\"margin:0;color:#ffffff;font-size:24px;line-height:1.25;\">{$title}</h1>
                    </div>

                    <div style=\"padding:22px;\">
                        <p style=\"margin:0 0 6px 0;font-size:14px;color:#6b7280;\">From <strong style=\"color:#111827;\">".e($campaign->sender_name)."</strong></p>
                        <div style=\"margin:0 0 16px 0;color:#374151;font-size:15px;line-height:1.6;\">{$bodyHtml}</div>

                        <p style=\"margin:0 0 12px 0;font-size:14px;color:#4b5563;line-height:1.5;\">Click the button below to access your link.</p>

                        <div style=\"margin:0 0 8px 0;\">
                            <a href=\"{$trackLink}\" style=\"display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700;font-size:14px;\">{$ctaLabel}</a>
                        </div>
                    </div>

                    <div style=\"border-top:1px solid #e5e7eb;padding:14px 22px;background:#fafafa;\">
                        <p style=\"margin:0 0 8px 0;font-size:12px;color:#6b7280;\">You can unsubscribe below.</p>
                        <a href=\"{$unsubscribeLink}\" style=\"display:inline-block;border:1px solid #d1d5db;color:#374151;text-decoration:none;padding:8px 12px;border-radius:8px;font-size:12px;\">Unsubscribe me</a>
                    </div>
                </div>
            </div>
        ";
    }

    private function formatBodyForEmail(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '<p style="margin:0;">Thank you for being part of this campaign. Please use the button below.</p>';
        }

        if (! str_contains($trimmed, '<')) {
            return nl2br(e($trimmed));
        }

        return $trimmed;
    }

    private function resolveDynamicFrom(string $configuredFrom, string $senderName): string
    {
        $email = $this->extractEmailAddress($configuredFrom);
        $baseName = $this->extractDisplayName($configuredFrom) ?: 'OnPage CV';
        $sender = trim($senderName) !== '' ? trim($senderName) : 'Sender';
        $dynamicName = "{$sender} via {$baseName}";

        return "{$dynamicName} <{$email}>";
    }

    private function extractEmailAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($from);
    }

    private function extractDisplayName(string $from): string
    {
        if (preg_match('/^\s*"?([^<"]+)"?\s*</', $from, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    private function postmarkApiKey(): string
    {
        $apiKey = (string) config('services.postmark.key', '');

        return $apiKey !== '' ? $apiKey : (string) config('services.postmark.token', '');
    }

    private function postWithRateLimitRetry(string $apiKey, string $endpoint, array $payload, int $attempt = 0): ?Response
    {
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post("https://api.resend.com/{$endpoint}", $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        if ($attempt >= 3) {
            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postWithRateLimitRetry($apiKey, $endpoint, $payload, $attempt + 1);
    }

    private function postToPostmarkWithRateLimitRetry(string $apiKey, string $endpoint, array $payload, int $attempt = 0): ?Response
    {
        $response = Http::withHeaders(['X-Postmark-Server-Token' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->post("https://api.postmarkapp.com/{$endpoint}", $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        if ($attempt >= 3) {
            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToPostmarkWithRateLimitRetry($apiKey, $endpoint, $payload, $attempt + 1);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postToElasticEmailWithRateLimitRetry(string $apiKey, string $endpoint, array $payload, int $attempt = 0): ?Response
    {
        $response = Http::withHeaders(['X-ElasticEmail-ApiKey' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->post("https://api.elasticemail.com/v4/{$endpoint}", $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        if ($attempt >= 3) {
            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToElasticEmailWithRateLimitRetry($apiKey, $endpoint, $payload, $attempt + 1);
    }
}
