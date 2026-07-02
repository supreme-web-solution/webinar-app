<?php

namespace App\Services;

use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ResendService
{
    /**
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    public function sendWebinarEmailBatch(Webinar $webinar, iterable $registrants, string $subject, string $intro, ?string $emailType = null): array
    {
        $list = [];
        foreach ($registrants as $registrant) {
            if ($registrant instanceof WebinarRegistrant) {
                $list[] = $registrant;
            }
        }

        if ($list === []) {
            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => 0,
            ];
        }

        $skippedRegistrantIds = [];
        $deliverable = [];

        foreach ($list as $registrant) {
            if (! $this->isDeliverableEmail($registrant->email)) {
                $skippedRegistrantIds[] = $registrant->id;
                Log::warning('webinar_email.recipient.skipped_invalid_email', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                ]);

                continue;
            }

            $deliverable[] = $registrant;
        }

        $attempted = count($list);
        $primary = $this->resolvePrimaryProvider($webinar);
        $fallback = $this->resolveFallbackProvider();

        $result = [
            'sent_registrant_ids' => [],
            'skipped_registrant_ids' => $skippedRegistrantIds,
            'attempted' => $attempted,
        ];

        if ($deliverable !== []) {
            $providerResult = $this->sendBatchUsingProvider($primary, $webinar, $deliverable, $subject, $intro, $emailType);
            $result['sent_registrant_ids'] = $providerResult['sent_registrant_ids'];
            $result['skipped_registrant_ids'] = array_values(array_unique([
                ...$result['skipped_registrant_ids'],
                ...$providerResult['skipped_registrant_ids'],
            ]));

            $terminalLookup = array_flip([
                ...$result['sent_registrant_ids'],
                ...$result['skipped_registrant_ids'],
            ]);
            $remaining = array_values(array_filter(
                $deliverable,
                fn (WebinarRegistrant $registrant): bool => ! isset($terminalLookup[$registrant->id])
            ));

            if ($fallback !== null && $fallback !== $primary && $remaining !== []) {
                Log::warning('webinar_email.provider.batch.fallback', [
                    'webinar_id' => $webinar->id,
                    'failed_provider' => $primary,
                    'fallback_provider' => $fallback,
                    'remaining' => count($remaining),
                ]);

                $fallbackResult = $this->sendBatchUsingProvider($fallback, $webinar, $remaining, $subject, $intro, $emailType);
                $result['sent_registrant_ids'] = array_values(array_unique([
                    ...$result['sent_registrant_ids'],
                    ...$fallbackResult['sent_registrant_ids'],
                ]));
                $result['skipped_registrant_ids'] = array_values(array_unique([
                    ...$result['skipped_registrant_ids'],
                    ...$fallbackResult['skipped_registrant_ids'],
                ]));
            }
        }

        $sentCount = count($result['sent_registrant_ids']);
        $skippedCount = count($result['skipped_registrant_ids']);

        Log::info('webinar_email.provider.batch.completed', [
            'webinar_id' => $webinar->id,
            'subject' => $subject,
            'provider' => $primary,
            'fallback_provider' => ($fallback !== null && $fallback !== $primary) ? $fallback : null,
            'attempted' => $attempted,
            'sent_count' => $sentCount,
            'skipped_count' => $skippedCount,
            'failed_count' => max(0, $attempted - $sentCount - $skippedCount),
        ]);

        return $result;
    }

    public function sendWebinarEmail(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro, ?string $emailType = 'confirmation'): bool
    {
        $primary = $this->resolvePrimaryProvider($webinar);
        $fallback = $this->resolveFallbackProvider();

        $sent = $this->sendSingleUsingProvider($primary, $webinar, $registrant, $subject, $intro, $emailType);
        if ($sent) {
            return true;
        }

        if ($fallback !== null && $fallback !== $primary) {
            Log::warning('webinar_email.provider.single.fallback', [
                'failed_provider' => $primary,
                'fallback_provider' => $fallback,
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return $this->sendSingleUsingProvider($fallback, $webinar, $registrant, $subject, $intro, $emailType);
        }

        return false;
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchUsingProvider(
        string $provider,
        Webinar $webinar,
        array $registrants,
        string $subject,
        string $intro,
        ?string $emailType = null,
    ): array {
        return match ($provider) {
            'postmark' => $this->sendBatchViaPostmark($webinar, $registrants, $subject, $intro, $emailType),
            'elastic', 'elasticemail' => $this->sendBatchViaElasticEmail($webinar, $registrants, $subject, $intro, $emailType),
            'zeptomail' => $this->sendBatchViaZeptoMail($webinar, $registrants, $subject, $intro, $emailType),
            'sendgrid' => $this->sendBatchViaSendGrid($webinar, $registrants, $subject, $intro, $emailType),
            'brevo' => $this->sendBatchViaBrevo($webinar, $registrants, $subject, $intro, $emailType),
            'lettermint' => $this->sendBatchViaLettermint($webinar, $registrants, $subject, $intro, $emailType),
            'sweego' => $this->sendBatchViaSweego($webinar, $registrants, $subject, $intro, $emailType),
            'scaleway' => $this->sendBatchViaScaleway($webinar, $registrants, $subject, $intro, $emailType),
            'ses_smtp', 'smtp' => $this->sendBatchViaSmtp($webinar, $registrants, $subject, $intro),
            default => $this->sendBatchViaResend($webinar, $registrants, $subject, $intro),
        };
    }

    private function sendSingleUsingProvider(
        string $provider,
        Webinar $webinar,
        WebinarRegistrant $registrant,
        string $subject,
        string $intro,
        ?string $emailType = null,
    ): bool {
        return match ($provider) {
            'postmark' => $this->sendSingleViaPostmark($webinar, $registrant, $subject, $intro, $emailType),
            'elastic', 'elasticemail' => $this->sendSingleViaElasticEmail($webinar, $registrant, $subject, $intro, $emailType),
            'zeptomail' => $this->sendSingleViaZeptoMail($webinar, $registrant, $subject, $intro, $emailType),
            'sendgrid' => $this->sendSingleViaSendGrid($webinar, $registrant, $subject, $intro, $emailType),
            'brevo' => $this->sendSingleViaBrevo($webinar, $registrant, $subject, $intro, $emailType),
            'lettermint' => $this->sendSingleViaLettermint($webinar, $registrant, $subject, $intro, $emailType),
            'sweego' => $this->sendSingleViaSweego($webinar, $registrant, $subject, $intro, $emailType),
            'scaleway' => $this->sendSingleViaScaleway($webinar, $registrant, $subject, $intro, $emailType),
            'ses_smtp', 'smtp' => $this->sendSingleViaSmtp($webinar, $registrant, $subject, $intro),
            default => $this->sendSingleViaResend($webinar, $registrant, $subject, $intro),
        };
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaResend(Webinar $webinar, array $registrants, string $subject, string $intro): array
    {
        $apiKey = (string) config('services.resend.key');
        $configuredFrom = (string) config('services.resend.from', 'onboarding@resend.dev');

        if ($apiKey === '') {
            Log::warning('RESEND_API_KEY not configured. Skipping Resend batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($registrants),
            ];
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $emails = [];
        $registrantIds = [];

        foreach ($registrants as $registrant) {
            $registrantIds[] = $registrant->id;
            $emails[] = [
                'from' => $from,
                'to' => [$registrant->email],
                'subject' => $subject,
                'html' => $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
            ];
        }

        try {
            $response = $this->postWithRateLimitRetry($apiKey, 'emails/batch', $emails);
            if (! $response || $response->failed()) {
                Log::warning('Resend batch API request failed.', [
                    'webinar_id' => $webinar->id,
                    'attempted' => count($emails),
                    'status' => $response?->status(),
                    'body' => $response?->body(),
                ]);

                return [
                    'sent_registrant_ids' => [],
                    'skipped_registrant_ids' => [],
                    'attempted' => count($emails),
                ];
            }
        } catch (\Throwable $exception) {
            Log::error('Resend batch API exception.', [
                'webinar_id' => $webinar->id,
                'attempted' => count($emails),
                'message' => $exception->getMessage(),
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($emails),
            ];
        }

        return [
            'sent_registrant_ids' => $registrantIds,
            'skipped_registrant_ids' => [],
            'attempted' => count($emails),
        ];
    }

    private function sendSingleViaResend(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro): bool
    {
        $apiKey = (string) config('services.resend.key');
        $configuredFrom = (string) config('services.resend.from', 'onboarding@resend.dev');

        if ($apiKey === '') {
            Log::warning('RESEND_API_KEY not configured. Skipping Resend single email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        try {
            $response = $this->postWithRateLimitRetry($apiKey, 'emails', [
                'from' => $from,
                'to' => [$registrant->email],
                'subject' => $subject,
                'html' => $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
            ]);

            if (! $response || $response->failed()) {
                Log::warning('Resend API request failed.', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'status' => $response?->status(),
                    'body' => $response?->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::error('Resend API exception.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaPostmark(Webinar $webinar, array $registrants, string $subject, string $intro, ?string $emailType = null): array
    {
        $apiKey = $this->postmarkApiKey();
        $configuredFrom = (string) config('services.postmark.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('POSTMARK_API_KEY not configured. Skipping Postmark batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($registrants),
            ];
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $messageStreamId = trim((string) config('services.postmark.message_stream_id', ''));
        $messages = [];
        $registrantIds = [];
        $registrantById = [];

        foreach ($registrants as $registrant) {
            $registrantIds[] = $registrant->id;
            $registrantById[$registrant->id] = $registrant;

            $message = [
                'From' => $from,
                'To' => $registrant->email,
                'Subject' => $subject,
                'HtmlBody' => $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
                'Metadata' => $this->postmarkWebinarMetadata($webinar, $registrant, $emailType),
            ];

            if ($messageStreamId !== '') {
                $message['MessageStream'] = $messageStreamId;
            }

            $messages[] = $message;
        }

        try {
            $response = $this->postToPostmarkWithRateLimitRetry($apiKey, 'email/batch', $messages);
            if (! $response || $response->failed()) {
                Log::warning('Postmark batch API request failed.', [
                    'webinar_id' => $webinar->id,
                    'attempted' => count($messages),
                    'status' => $response?->status(),
                    'body' => $response?->body(),
                ]);

                return [
                    'sent_registrant_ids' => [],
                    'skipped_registrant_ids' => [],
                    'attempted' => count($messages),
                ];
            }
        } catch (\Throwable $exception) {
            Log::error('Postmark batch API exception.', [
                'webinar_id' => $webinar->id,
                'attempted' => count($messages),
                'message' => $exception->getMessage(),
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($messages),
            ];
        }

        $body = $response->json();
        if (! is_array($body) || ! array_is_list($body)) {
            Log::warning('Postmark batch API response was not a result list.', [
                'webinar_id' => $webinar->id,
                'attempted' => count($messages),
                'body' => $response->body(),
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($messages),
            ];
        }

        $sentIds = [];
        $skippedIds = [];
        $acceptedRecords = [];
        $suppressedRecords = [];
        $userId = (int) $webinar->user_id;

        foreach ($body as $index => $result) {
            $errorCode = (int) data_get($result, 'ErrorCode', 0);
            if ($errorCode === 0 && isset($registrantIds[$index])) {
                $registrantId = $registrantIds[$index];
                $sentIds[] = $registrantId;
                $registrant = $registrantById[$registrantId] ?? null;
                $messageId = trim((string) data_get($result, 'MessageID', ''));

                if ($messageId !== '' && $registrant instanceof WebinarRegistrant) {
                    $acceptedRecords[] = [
                        'message_id' => $messageId,
                        'email' => $registrant->email,
                        'user_id' => $userId,
                        'source_type' => 'webinar_registrant',
                        'webinar_id' => $webinar->id,
                        'registrant_id' => $registrant->id,
                        'email_type' => $emailType,
                        'subject' => $subject,
                    ];
                }

                continue;
            }

            $registrantId = $registrantIds[$index] ?? null;

            if ($registrantId !== null && $this->isPermanentPostmarkRecipientError($errorCode)) {
                $skippedIds[] = $registrantId;
                $registrant = $registrantById[$registrantId] ?? null;

                if ($registrant instanceof WebinarRegistrant) {
                    $suppressedRecords[] = [
                        'email' => $registrant->email,
                        'user_id' => $userId,
                        'source_type' => 'webinar_registrant',
                        'webinar_id' => $webinar->id,
                        'registrant_id' => $registrant->id,
                        'email_type' => $emailType,
                        'subject' => $subject,
                    ];
                }

                Log::warning('webinar_email.recipient.skipped_permanent_failure', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrantId,
                    'error_code' => $errorCode,
                    'message' => data_get($result, 'Message'),
                ]);

                continue;
            }

            Log::warning('Postmark batch recipient failed.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrantId,
                'error_code' => $errorCode,
                'message' => data_get($result, 'Message'),
            ]);
        }

        if ($sentIds !== []) {
            Log::info('webinar_email.provider.postmark.batch.sent', [
                'webinar_id' => $webinar->id,
                'attempted' => count($messages),
                'sent_count' => count($sentIds),
                'skipped_count' => count($skippedIds),
            ]);
        }

        $tracking = app(PostmarkDeliveryTrackingService::class);
        $tracking->recordAccepted($acceptedRecords);
        $tracking->recordSuppressedAtApi($suppressedRecords);

        return [
            'sent_registrant_ids' => $sentIds,
            'skipped_registrant_ids' => $skippedIds,
            'attempted' => count($messages),
        ];
    }

    private function sendSingleViaPostmark(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro, ?string $emailType = null): bool
    {
        $apiKey = $this->postmarkApiKey();
        $configuredFrom = (string) config('services.postmark.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('POSTMARK_API_KEY not configured. Skipping Postmark single email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $payload = [
            'From' => $this->resolveDynamicFrom($configuredFrom, $webinar->host_name),
            'To' => $registrant->email,
            'Subject' => $subject,
            'HtmlBody' => $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
            'Metadata' => $this->postmarkWebinarMetadata($webinar, $registrant, $emailType),
        ];

        $messageStreamId = trim((string) config('services.postmark.message_stream_id', ''));
        if ($messageStreamId !== '') {
            $payload['MessageStream'] = $messageStreamId;
        }

        try {
            $response = $this->postToPostmarkWithRateLimitRetry($apiKey, 'email', $payload);

            if (! $response || $response->failed() || (int) data_get($response->json(), 'ErrorCode', 0) !== 0) {
                Log::warning('Postmark API request failed.', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'status' => $response?->status(),
                    'body' => $response?->body(),
                ]);

                return false;
            }

            $messageId = trim((string) data_get($response->json(), 'MessageID', ''));
            if ($messageId !== '') {
                app(PostmarkDeliveryTrackingService::class)->recordAccepted([[
                    'message_id' => $messageId,
                    'email' => $registrant->email,
                    'user_id' => (int) $webinar->user_id,
                    'source_type' => 'webinar_registrant',
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'email_type' => $emailType,
                    'subject' => $subject,
                ]]);
            }

            return true;
        } catch (\Throwable $exception) {
            Log::error('Postmark API exception.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaElasticEmail(Webinar $webinar, array $registrants, string $subject, string $intro, ?string $emailType = null): array
    {
        $apiKey = $this->elasticApiKey();
        $configuredFrom = (string) config('services.elastic.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('ELASTICEMAIL_API_KEY not configured. Skipping Elastic Email batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($registrants),
            ];
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $sentIds = [];
        $skippedIds = [];

        foreach ($registrants as $registrant) {
            if ($this->sendSingleViaElasticEmail($webinar, $registrant, $subject, $intro, $emailType)) {
                $sentIds[] = $registrant->id;
            }
        }

        if ($sentIds !== []) {
            Log::info('webinar_email.provider.elastic.batch.sent', [
                'webinar_id' => $webinar->id,
                'attempted' => count($registrants),
                'sent_count' => count($sentIds),
                'skipped_count' => count($skippedIds),
            ]);
        }

        return [
            'sent_registrant_ids' => $sentIds,
            'skipped_registrant_ids' => $skippedIds,
            'attempted' => count($registrants),
        ];
    }

    private function sendSingleViaElasticEmail(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro, ?string $emailType = null): bool
    {
        $apiKey = $this->elasticApiKey();
        $configuredFrom = (string) config('services.elastic.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('ELASTICEMAIL_API_KEY not configured. Skipping Elastic Email single email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $payload = $this->buildElasticEmailPayload(
            $from,
            $registrant->email,
            $subject,
            $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
        );

        try {
            $response = $this->postToElasticEmailWithRateLimitRetry($apiKey, 'emails', $payload);

            if ($this->elasticEmailResponseSucceeded($response)) {
                Log::debug('webinar_email.provider.elastic.single.sent', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'email_type' => $emailType,
                    'transaction_id' => data_get($response?->json(), 'TransactionID'),
                    'message_id' => data_get($response?->json(), 'MessageID'),
                ]);

                return true;
            }

            Log::warning('Elastic Email API request failed.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::error('Elastic Email API exception.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postToElasticEmailWithRateLimitRetry(string $apiKey, string $endpoint, array $payload, int $attempt = 0): ?Response
    {
        Log::debug('elastic.http.request', [
            'endpoint' => $endpoint,
            'attempt' => $attempt + 1,
        ]);

        $response = Http::withHeaders(['X-ElasticEmail-ApiKey' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->post("https://api.elasticemail.com/v4/{$endpoint}", $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        Log::warning('elastic.http.rate_limited', [
            'endpoint' => $endpoint,
            'attempt' => $attempt + 1,
            'retry_after' => $response->header('retry-after'),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($attempt >= 3) {
            Log::error('elastic.http.rate_limit_give_up', [
                'endpoint' => $endpoint,
                'attempts' => $attempt + 1,
            ]);

            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToElasticEmailWithRateLimitRetry($apiKey, $endpoint, $payload, $attempt + 1);
    }

    private function elasticApiKey(): string
    {
        return (string) config('services.elastic.key', '');
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaZeptoMail(Webinar $webinar, array $registrants, string $subject, string $intro, ?string $emailType = null): array
    {
        $apiKey = $this->zeptoMailApiKey();
        $configuredFrom = (string) config('services.zeptomail.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('ZEPTOMAIL_API_KEY not configured. Skipping ZeptoMail batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($registrants),
            ];
        }

        $sentIds = [];
        $skippedIds = [];

        foreach ($registrants as $registrant) {
            if ($this->sendSingleViaZeptoMail($webinar, $registrant, $subject, $intro, $emailType)) {
                $sentIds[] = $registrant->id;
            }
        }

        if ($sentIds !== []) {
            Log::info('webinar_email.provider.zeptomail.batch.sent', [
                'webinar_id' => $webinar->id,
                'attempted' => count($registrants),
                'sent_count' => count($sentIds),
                'skipped_count' => count($skippedIds),
            ]);
        }

        return [
            'sent_registrant_ids' => $sentIds,
            'skipped_registrant_ids' => $skippedIds,
            'attempted' => count($registrants),
        ];
    }

    private function sendSingleViaZeptoMail(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro, ?string $emailType = null): bool
    {
        $apiKey = $this->zeptoMailApiKey();
        $configuredFrom = (string) config('services.zeptomail.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('ZEPTOMAIL_API_KEY not configured. Skipping ZeptoMail single email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $payload = $this->buildZeptoMailPayload(
            $from,
            $registrant->email,
            $registrant->name,
            $subject,
            $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
        );

        try {
            $response = $this->postToZeptoMailWithRateLimitRetry($apiKey, $payload);

            if ($this->zeptoMailResponseSucceeded($response)) {
                Log::debug('webinar_email.provider.zeptomail.single.sent', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'email_type' => $emailType,
                    'request_id' => data_get($response?->json(), 'request_id'),
                ]);

                return true;
            }

            Log::warning('ZeptoMail API request failed.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::error('ZeptoMail API exception.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
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
        Log::debug('zeptomail.http.request', [
            'attempt' => $attempt + 1,
        ]);

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

        Log::warning('zeptomail.http.rate_limited', [
            'attempt' => $attempt + 1,
            'retry_after' => $response->header('retry-after'),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($attempt >= 3) {
            Log::error('zeptomail.http.rate_limit_give_up', [
                'attempts' => $attempt + 1,
            ]);

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
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaSendGrid(Webinar $webinar, array $registrants, string $subject, string $intro, ?string $emailType = null): array
    {
        $apiKey = $this->sendGridApiKey();
        $configuredFrom = (string) config('services.sendgrid.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('SENDGRID_API_KEY not configured. Skipping SendGrid batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($registrants),
            ];
        }

        $sentIds = [];
        $skippedIds = [];

        foreach ($registrants as $registrant) {
            if ($this->sendSingleViaSendGrid($webinar, $registrant, $subject, $intro, $emailType)) {
                $sentIds[] = $registrant->id;
            }
        }

        if ($sentIds !== []) {
            Log::info('webinar_email.provider.sendgrid.batch.sent', [
                'webinar_id' => $webinar->id,
                'attempted' => count($registrants),
                'sent_count' => count($sentIds),
                'skipped_count' => count($skippedIds),
            ]);
        }

        return [
            'sent_registrant_ids' => $sentIds,
            'skipped_registrant_ids' => $skippedIds,
            'attempted' => count($registrants),
        ];
    }

    private function sendSingleViaSendGrid(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro, ?string $emailType = null): bool
    {
        $apiKey = $this->sendGridApiKey();
        $configuredFrom = (string) config('services.sendgrid.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('SENDGRID_API_KEY not configured. Skipping SendGrid single email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $payload = $this->buildSendGridPayload(
            $from,
            $registrant->email,
            $registrant->name,
            $subject,
            $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
            $this->sendGridWebinarMetadata($webinar, $registrant, $emailType),
        );

        try {
            $response = $this->postToSendGridWithRateLimitRetry($apiKey, $payload);

            if ($this->sendGridResponseSucceeded($response)) {
                Log::debug('webinar_email.provider.sendgrid.single.sent', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'email_type' => $emailType,
                    'message_id' => $response?->header('X-Message-Id'),
                ]);

                return true;
            }

            Log::warning('SendGrid API request failed.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::error('SendGrid API exception.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
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
        Log::debug('sendgrid.http.request', [
            'attempt' => $attempt + 1,
        ]);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->post('https://api.sendgrid.com/v3/mail/send', $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        Log::warning('sendgrid.http.rate_limited', [
            'attempt' => $attempt + 1,
            'retry_after' => $response->header('retry-after'),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($attempt >= 3) {
            Log::error('sendgrid.http.rate_limit_give_up', [
                'attempts' => $attempt + 1,
            ]);

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
    private function sendGridWebinarMetadata(Webinar $webinar, WebinarRegistrant $registrant, ?string $emailType): array
    {
        $metadata = [
            'source' => 'webinar',
            'webinar_id' => (string) $webinar->id,
            'registrant_id' => (string) $registrant->id,
        ];

        if ($emailType !== null && $emailType !== '') {
            $metadata['email_type'] = $emailType;
        }

        return $metadata;
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaBrevo(Webinar $webinar, array $registrants, string $subject, string $intro, ?string $emailType = null): array
    {
        $apiKey = $this->brevoApiKey();

        if ($apiKey === '') {
            Log::warning('BREVO_API_KEY not configured. Skipping Brevo batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($registrants),
            ];
        }

        $sentIds = [];
        $skippedIds = [];

        foreach ($registrants as $registrant) {
            if ($this->sendSingleViaBrevo($webinar, $registrant, $subject, $intro, $emailType)) {
                $sentIds[] = $registrant->id;
            }
        }

        if ($sentIds !== []) {
            Log::info('webinar_email.provider.brevo.batch.sent', [
                'webinar_id' => $webinar->id,
                'attempted' => count($registrants),
                'sent_count' => count($sentIds),
                'skipped_count' => count($skippedIds),
            ]);
        }

        return [
            'sent_registrant_ids' => $sentIds,
            'skipped_registrant_ids' => $skippedIds,
            'attempted' => count($registrants),
        ];
    }

    private function sendSingleViaBrevo(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro, ?string $emailType = null): bool
    {
        $apiKey = $this->brevoApiKey();
        $configuredFrom = (string) config('services.brevo.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('BREVO_API_KEY not configured. Skipping Brevo single email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $payload = $this->buildBrevoPayload(
            $from,
            $registrant->email,
            $registrant->name,
            $subject,
            $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
            $this->brevoWebinarMetadata($webinar, $registrant, $emailType),
        );

        try {
            $response = $this->postToBrevoWithRateLimitRetry($apiKey, $payload);

            if ($this->brevoResponseSucceeded($response)) {
                Log::debug('webinar_email.provider.brevo.single.sent', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'email_type' => $emailType,
                    'message_id' => data_get($response?->json(), 'messageId'),
                ]);

                return true;
            }

            Log::warning('Brevo API request failed.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::error('Brevo API exception.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    private function buildBrevoPayload(string $from, string $to, ?string $toName, string $subject, string $html, array $metadata = []): array
    {
        $toRecipient = ['email' => $to];
        $recipientName = trim((string) $toName);
        if ($recipientName !== '') {
            $toRecipient['name'] = $recipientName;
        }

        $payload = [
            'sender' => [
                'email' => $this->extractEmailAddress($from),
                'name' => $this->extractDisplayName($from) ?: 'OnPage CV',
            ],
            'to' => [$toRecipient],
            'subject' => $subject,
            'htmlContent' => $html,
        ];

        if ($metadata !== []) {
            $payload['headers'] = $metadata;
        }

        return $payload;
    }

    private function brevoResponseSucceeded(?Response $response): bool
    {
        return $response !== null && $response->status() === 201;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postToBrevoWithRateLimitRetry(string $apiKey, array $payload, int $attempt = 0): ?Response
    {
        Log::debug('brevo.http.request', [
            'attempt' => $attempt + 1,
        ]);

        $response = Http::withHeaders(['api-key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        Log::warning('brevo.http.rate_limited', [
            'attempt' => $attempt + 1,
            'retry_after' => $response->header('retry-after'),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($attempt >= 3) {
            Log::error('brevo.http.rate_limit_give_up', [
                'attempts' => $attempt + 1,
            ]);

            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToBrevoWithRateLimitRetry($apiKey, $payload, $attempt + 1);
    }

    private function brevoApiKey(): string
    {
        return (string) config('services.brevo.key', '');
    }

    /**
     * @return array<string, string>
     */
    private function brevoWebinarMetadata(Webinar $webinar, WebinarRegistrant $registrant, ?string $emailType): array
    {
        $metadata = [
            'X-Source' => 'webinar',
            'X-Webinar-Id' => (string) $webinar->id,
            'X-Registrant-Id' => (string) $registrant->id,
        ];

        if ($emailType !== null && $emailType !== '') {
            $metadata['X-Email-Type'] = $emailType;
        }

        return $metadata;
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaLettermint(Webinar $webinar, array $registrants, string $subject, string $intro, ?string $emailType = null): array
    {
        $apiKey = $this->lettermintApiKey();

        if ($apiKey === '') {
            Log::warning('LETTERMINT_API_KEY not configured. Skipping Lettermint batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($registrants),
            ];
        }

        $sentIds = [];
        $skippedIds = [];

        foreach ($registrants as $registrant) {
            if ($this->sendSingleViaLettermint($webinar, $registrant, $subject, $intro, $emailType)) {
                $sentIds[] = $registrant->id;
            }
        }

        if ($sentIds !== []) {
            Log::info('webinar_email.provider.lettermint.batch.sent', [
                'webinar_id' => $webinar->id,
                'attempted' => count($registrants),
                'sent_count' => count($sentIds),
                'skipped_count' => count($skippedIds),
            ]);
        }

        return [
            'sent_registrant_ids' => $sentIds,
            'skipped_registrant_ids' => $skippedIds,
            'attempted' => count($registrants),
        ];
    }

    private function sendSingleViaLettermint(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro, ?string $emailType = null): bool
    {
        $apiKey = $this->lettermintApiKey();
        $configuredFrom = (string) config('services.lettermint.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('LETTERMINT_API_KEY not configured. Skipping Lettermint single email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $payload = $this->buildLettermintPayload(
            $from,
            $registrant->email,
            $subject,
            $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
            $this->lettermintWebinarMetadata($webinar, $registrant, $emailType),
        );

        try {
            $response = $this->postToLettermintWithRateLimitRetry($apiKey, $payload);

            if ($this->lettermintResponseSucceeded($response)) {
                Log::debug('webinar_email.provider.lettermint.single.sent', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'email_type' => $emailType,
                    'message_id' => data_get($response?->json(), 'message_id'),
                ]);

                return true;
            }

            Log::warning('Lettermint API request failed.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::error('Lettermint API exception.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    private function buildLettermintPayload(string $from, string $to, string $subject, string $html, array $metadata = []): array
    {
        $payload = [
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
        ];

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        return $payload;
    }

    private function lettermintResponseSucceeded(?Response $response): bool
    {
        return $response !== null && $response->status() === 202;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postToLettermintWithRateLimitRetry(string $apiKey, array $payload, int $attempt = 0): ?Response
    {
        Log::debug('lettermint.http.request', [
            'attempt' => $attempt + 1,
        ]);

        $response = Http::withHeaders(['x-lettermint-token' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->post('https://api.lettermint.co/v1/send', $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        Log::warning('lettermint.http.rate_limited', [
            'attempt' => $attempt + 1,
            'retry_after' => $response->header('retry-after'),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($attempt >= 3) {
            Log::error('lettermint.http.rate_limit_give_up', [
                'attempts' => $attempt + 1,
            ]);

            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToLettermintWithRateLimitRetry($apiKey, $payload, $attempt + 1);
    }

    private function lettermintApiKey(): string
    {
        return (string) config('services.lettermint.key', '');
    }

    /**
     * @return array<string, string>
     */
    private function lettermintWebinarMetadata(Webinar $webinar, WebinarRegistrant $registrant, ?string $emailType): array
    {
        $metadata = [
            'source' => 'webinar',
            'webinar_id' => (string) $webinar->id,
            'registrant_id' => (string) $registrant->id,
        ];

        if ($emailType !== null && $emailType !== '') {
            $metadata['email_type'] = $emailType;
        }

        return $metadata;
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaSweego(Webinar $webinar, array $registrants, string $subject, string $intro, ?string $emailType = null): array
    {
        $apiKey = $this->sweegoApiKey();

        if ($apiKey === '') {
            Log::warning('SWEEGO_API_KEY not configured. Skipping Sweego batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($registrants),
            ];
        }

        $sentIds = [];
        $skippedIds = [];

        foreach ($registrants as $registrant) {
            if ($this->sendSingleViaSweego($webinar, $registrant, $subject, $intro, $emailType)) {
                $sentIds[] = $registrant->id;
            }
        }

        if ($sentIds !== []) {
            Log::info('webinar_email.provider.sweego.batch.sent', [
                'webinar_id' => $webinar->id,
                'attempted' => count($registrants),
                'sent_count' => count($sentIds),
                'skipped_count' => count($skippedIds),
            ]);
        }

        return [
            'sent_registrant_ids' => $sentIds,
            'skipped_registrant_ids' => $skippedIds,
            'attempted' => count($registrants),
        ];
    }

    private function sendSingleViaSweego(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro, ?string $emailType = null): bool
    {
        $apiKey = $this->sweegoApiKey();
        $configuredFrom = (string) config('services.sweego.from', config('mail.from.address', 'hello@example.com'));

        if ($apiKey === '') {
            Log::warning('SWEEGO_API_KEY not configured. Skipping Sweego single email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $payload = $this->buildSweegoPayload(
            $from,
            $registrant->email,
            $registrant->name,
            $subject,
            $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
            $this->sweegoWebinarMetadata($webinar, $registrant, $emailType),
        );

        try {
            $response = $this->postToSweegoWithRateLimitRetry($apiKey, $payload);

            if ($this->sweegoResponseSucceeded($response)) {
                Log::debug('webinar_email.provider.sweego.single.sent', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'email_type' => $emailType,
                    'message_id' => data_get($response?->json(), 'message-id'),
                ]);

                return true;
            }

            Log::warning('Sweego API request failed.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::error('Sweego API exception.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function buildSweegoPayload(string $from, string $to, ?string $toName, string $subject, string $html, array $headers = []): array
    {
        $recipient = ['email' => $to];
        $recipientName = trim((string) $toName);
        if ($recipientName !== '') {
            $recipient['name'] = $recipientName;
        }

        $payload = [
            'channel' => 'email',
            'provider' => 'sweego',
            'recipients' => [$recipient],
            'from' => [
                'email' => $this->extractEmailAddress($from),
                'name' => $this->extractDisplayName($from) ?: 'OnPage CV',
            ],
            'subject' => $subject,
            'message-html' => $html,
        ];

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        return $payload;
    }

    private function sweegoResponseSucceeded(?Response $response): bool
    {
        return $response !== null && $response->successful();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postToSweegoWithRateLimitRetry(string $apiKey, array $payload, int $attempt = 0): ?Response
    {
        Log::debug('sweego.http.request', [
            'attempt' => $attempt + 1,
        ]);

        $response = Http::withHeaders(['Api-Key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->post('https://api.sweego.io/send', $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        Log::warning('sweego.http.rate_limited', [
            'attempt' => $attempt + 1,
            'retry_after' => $response->header('retry-after'),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($attempt >= 3) {
            Log::error('sweego.http.rate_limit_give_up', [
                'attempts' => $attempt + 1,
            ]);

            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToSweegoWithRateLimitRetry($apiKey, $payload, $attempt + 1);
    }

    private function sweegoApiKey(): string
    {
        return (string) config('services.sweego.key', '');
    }

    /**
     * @return array<string, string>
     */
    private function sweegoWebinarMetadata(Webinar $webinar, WebinarRegistrant $registrant, ?string $emailType): array
    {
        $metadata = [
            'X-Source' => 'webinar',
            'X-Webinar-Id' => (string) $webinar->id,
            'X-Registrant-Id' => (string) $registrant->id,
        ];

        if ($emailType !== null && $emailType !== '') {
            $metadata['X-Email-Type'] = $emailType;
        }

        return $metadata;
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaScaleway(Webinar $webinar, array $registrants, string $subject, string $intro, ?string $emailType = null): array
    {
        if (! $this->scalewayConfigured()) {
            Log::warning('Scaleway TEM not configured. Skipping Scaleway batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'skipped_registrant_ids' => [],
                'attempted' => count($registrants),
            ];
        }

        $sentIds = [];
        $skippedIds = [];

        foreach ($registrants as $registrant) {
            if ($this->sendSingleViaScaleway($webinar, $registrant, $subject, $intro, $emailType)) {
                $sentIds[] = $registrant->id;
            }
        }

        if ($sentIds !== []) {
            Log::info('webinar_email.provider.scaleway.batch.sent', [
                'webinar_id' => $webinar->id,
                'attempted' => count($registrants),
                'sent_count' => count($sentIds),
                'skipped_count' => count($skippedIds),
            ]);
        }

        return [
            'sent_registrant_ids' => $sentIds,
            'skipped_registrant_ids' => $skippedIds,
            'attempted' => count($registrants),
        ];
    }

    private function sendSingleViaScaleway(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro, ?string $emailType = null): bool
    {
        if (! $this->scalewayConfigured()) {
            Log::warning('Scaleway TEM not configured. Skipping Scaleway single email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $configuredFrom = (string) config('services.scaleway.from', config('mail.from.address', 'hello@example.com'));
        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $payload = $this->buildScalewayPayload(
            $from,
            $registrant->email,
            $registrant->name,
            $subject,
            $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
            $this->scalewayWebinarMetadata($webinar, $registrant, $emailType),
        );

        try {
            $response = $this->postToScalewayWithRateLimitRetry($payload);

            if ($this->scalewayResponseSucceeded($response)) {
                Log::debug('webinar_email.provider.scaleway.single.sent', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'email_type' => $emailType,
                    'email_id' => data_get($response?->json(), 'emails.0.id'),
                ]);

                return true;
            }

            Log::warning('Scaleway TEM API request failed.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::error('Scaleway TEM API exception.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    private function buildScalewayPayload(string $from, string $to, ?string $toName, string $subject, string $html, array $metadata = []): array
    {
        $toRecipient = ['email' => $to];
        $recipientName = trim((string) $toName);
        if ($recipientName !== '') {
            $toRecipient['name'] = $recipientName;
        }

        $payload = [
            'from' => [
                'email' => $this->extractEmailAddress($from),
                'name' => $this->extractDisplayName($from) ?: 'OnPage CV',
            ],
            'to' => [$toRecipient],
            'subject' => $subject,
            'html' => $html,
            'project_id' => (string) config('services.scaleway.project_id', ''),
        ];

        if ($metadata !== []) {
            $payload['additional_headers'] = collect($metadata)
                ->map(fn (string $value, string $key): array => ['key' => $key, 'value' => $value])
                ->values()
                ->all();
        }

        return $payload;
    }

    private function scalewayResponseSucceeded(?Response $response): bool
    {
        return $response !== null && $response->successful();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postToScalewayWithRateLimitRetry(array $payload, int $attempt = 0): ?Response
    {
        $apiKey = (string) config('services.scaleway.key', '');
        $region = (string) config('services.scaleway.region', 'fr-par');

        Log::debug('scaleway.http.request', [
            'attempt' => $attempt + 1,
            'region' => $region,
        ]);

        $response = Http::withHeaders(['X-Auth-Token' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->post("https://api.scaleway.com/transactional-email/v1alpha1/regions/{$region}/emails", $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        Log::warning('scaleway.http.rate_limited', [
            'attempt' => $attempt + 1,
            'retry_after' => $response->header('retry-after'),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($attempt >= 3) {
            Log::error('scaleway.http.rate_limit_give_up', [
                'attempts' => $attempt + 1,
            ]);

            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToScalewayWithRateLimitRetry($payload, $attempt + 1);
    }

    private function scalewayConfigured(): bool
    {
        return trim((string) config('services.scaleway.key', '')) !== ''
            && trim((string) config('services.scaleway.project_id', '')) !== '';
    }

    /**
     * @return array<string, string>
     */
    private function scalewayWebinarMetadata(Webinar $webinar, WebinarRegistrant $registrant, ?string $emailType): array
    {
        $metadata = [
            'X-Source' => 'webinar',
            'X-Webinar-Id' => (string) $webinar->id,
            'X-Registrant-Id' => (string) $registrant->id,
        ];

        if ($emailType !== null && $emailType !== '') {
            $metadata['X-Email-Type'] = $emailType;
        }

        return $metadata;
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, skipped_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchViaSmtp(Webinar $webinar, array $registrants, string $subject, string $intro): array
    {
        $sentIds = [];
        foreach ($registrants as $registrant) {
            if ($this->sendSingleViaSmtp($webinar, $registrant, $subject, $intro)) {
                $sentIds[] = $registrant->id;
            }
        }

        return [
            'sent_registrant_ids' => $sentIds,
            'skipped_registrant_ids' => [],
            'attempted' => count($registrants),
        ];
    }

    private function sendSingleViaSmtp(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro): bool
    {
        $smtpConfig = $this->resolveSmtpTransportConfig($webinar);
        $usingUserSmtp = $smtpConfig !== null;
        $mailer = (string) config('services.email.ses_smtp_mailer', 'ses');
        $fromAddress = $usingUserSmtp
            ? (string) ($smtpConfig['from_address'] ?? config('mail.from.address'))
            : (string) config('services.email.ses_smtp_from_address', config('mail.from.address'));
        $fromName = $usingUserSmtp
            ? (string) ($smtpConfig['from_name'] ?? config('mail.from.name'))
            : (string) config('services.email.ses_smtp_from_name', config('mail.from.name'));
        $dynamicFromName = trim($webinar->host_name) !== '' ? trim($webinar->host_name).' via '.$fromName : $fromName;
        $html = $this->buildWebinarEmailHtml($webinar, $registrant, $intro);

        try {
            $mailSender = $usingUserSmtp
                ? app('mail.manager')->build($smtpConfig['transport'])
                : Mail::mailer($mailer);

            $mailSender->send([], [], function ($message) use (
                $registrant,
                $subject,
                $fromAddress,
                $dynamicFromName,
                $html
            ): void {
                $message->to($registrant->email);
                $message->subject($subject);
                $message->from($fromAddress, $dynamicFromName);
                $message->html($html);
            });

            return true;
        } catch (\Throwable $exception) {
            Log::error('smtp.single.failed', [
                'mailer' => $usingUserSmtp ? 'user_smtp' : $mailer,
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function resolvePrimaryProvider(Webinar $webinar): string
    {
        if ($this->resolveSmtpTransportConfig($webinar) !== null) {
            return 'smtp';
        }

        $provider = strtolower(trim((string) config('services.email.primary', 'postmark')));

        return in_array($provider, ['resend', 'postmark', 'elastic', 'elasticemail', 'zeptomail', 'sendgrid', 'brevo', 'lettermint', 'sweego', 'scaleway', 'ses_smtp', 'smtp'], true)
            ? $this->normalizeEmailProvider($provider)
            : 'postmark';
    }

    private function resolveFallbackProvider(): ?string
    {
        $provider = strtolower(trim((string) config('services.email.fallback', 'resend')));
        if ($provider === '' || $provider === 'none') {
            return null;
        }

        return in_array($provider, ['resend', 'postmark', 'elastic', 'elasticemail', 'zeptomail', 'sendgrid', 'brevo', 'lettermint', 'sweego', 'scaleway', 'ses_smtp', 'smtp'], true)
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
    private function resolveSmtpTransportConfig(Webinar $webinar): ?array
    {
        $owner = $webinar->relationLoaded('user') ? $webinar->user : $webinar->user()->first();
        if (! $owner || ! $owner->smtp_enabled) {
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

    private function buildWebinarEmailHtml(Webinar $webinar, WebinarRegistrant $registrant, string $intro): string
    {
        $joinLink = route('webinar.room', ['token' => $registrant->access_token]);
        $unsubscribeLink = route('webinar.unsubscribe', ['token' => $registrant->access_token]);
        $webinarDescription = trim((string) ($webinar->description ?? ''));
        $descriptionHtml = $webinarDescription !== ''
            ? $this->formatDescriptionForEmail($webinarDescription)
            : '';
        $introHtml = $this->formatIntroForEmail($intro);
        $prefixedTitle = e($webinar->prefixedTitleLine());

        return "
            <div style=\"background:#f3f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#111827;\">
                <div style=\"max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;\">
                    <div style=\"background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:18px 22px;\">
                        <p style=\"margin:0;color:#bfdbfe;font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:700;\">Watch This Live Training</p>
                        <h1 style=\"margin:8px 0 0 0;color:#ffffff;font-size:24px;line-height:1.25;\">{$prefixedTitle}</h1>
                    </div>

                    <div style=\"padding:22px;\">
                        <p style=\"margin:0 0 6px 0;font-size:14px;color:#6b7280;\">Hosted by <strong style=\"color:#111827;\">".e($webinar->host_name)."</strong></p>
                        {$descriptionHtml}

                        <div style=\"margin:0 0 16px 0;color:#374151;font-size:15px;line-height:1.6;\">{$introHtml}</div>

                        <div style=\"margin:14px 0 18px 0;\">
                            <a href=\"{$joinLink}\" style=\"display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700;font-size:14px;\">Join Webinar</a>
                        </div>

                        <div style=\"border:1px dashed #cbd5e1;border-radius:10px;padding:10px 12px;background:#f8fafc;\">
                            <p style=\"margin:0 0 6px 0;font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.04em;\">Direct Link</p>
                            <p style=\"margin:0;font-size:13px;word-break:break-all;color:#334155;\">{$joinLink}</p>
                        </div>
                    </div>

                    <div style=\"border-top:1px solid #e5e7eb;padding:14px 22px;background:#fafafa;\">
                        <p style=\"margin:0 0 8px 0;font-size:12px;color:#6b7280;\">If you no longer want webinar emails, you can unsubscribe below.</p>
                        <a href=\"{$unsubscribeLink}\" style=\"display:inline-block;border:1px solid #d1d5db;color:#374151;text-decoration:none;padding:8px 12px;border-radius:8px;font-size:12px;\">Unsubscribe me</a>
                    </div>
                </div>
            </div>
        ";
    }

    private function formatDescriptionForEmail(string $description): string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $description));
        if ($normalized === '') {
            return '';
        }

        if (! str_contains($normalized, '<')) {
            return '<p style="margin: 0 0 12px 0; color: #4b5563; font-size: 14px; line-height: 1.55;">'
                .nl2br(e($normalized))
                .'</p>';
        }

        $html = $normalized;
        $html = str_ireplace('<p>', '<p style="margin: 0 0 12px 0;">', $html);
        $html = str_ireplace('<ul>', '<ul style="margin: 0 0 12px 20px; padding: 0;">', $html);
        $html = str_ireplace('<ol>', '<ol style="margin: 0 0 12px 20px; padding: 0;">', $html);
        $html = str_ireplace('<li>', '<li style="margin: 0 0 6px 0;">', $html);

        return '<div style="margin: 0 0 12px 0; color: #4b5563; font-size: 14px; line-height: 1.55;">'
            .$html
            .'</div>';
    }

    private function formatIntroForEmail(string $intro): string
    {
        $trimmed = trim($intro);
        if ($trimmed === '') {
            return '';
        }

        if ($this->introLooksLikeHtml($trimmed)) {
            return $this->sanitizeIntroHtml($trimmed);
        }

        $escaped = e($trimmed);
        $withLineBreaks = nl2br($escaped);

        return (string) preg_replace_callback(
            '/(https?:\/\/[^\s<]+)/i',
            static fn (array $matches): string => '<a href="'.$matches[1].'" style="color:#2563eb;text-decoration:underline;word-break:break-all;">'.$matches[1].'</a>',
            $withLineBreaks,
        );
    }

    private function introLooksLikeHtml(string $intro): bool
    {
        return (bool) preg_match('/<(p|div|br|ul|ol|li|strong|em|b|i|u|h[1-3]|a|blockquote)\b/i', $intro);
    }

    private function sanitizeIntroHtml(string $html): string
    {
        $allowedTags = '<p><br><strong><b><em><i><u><a><ul><ol><li><h1><h2><h3><blockquote>';
        $clean = strip_tags($html, $allowedTags);
        $clean = trim($clean);
        if ($clean === '') {
            return '';
        }

        $wrapped = '<?xml encoding="UTF-8"><div id="__intro_root">'.$clean.'</div>';

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (! $loaded) {
            return '';
        }

        $root = $dom->getElementById('__intro_root');
        if (! $root) {
            return '';
        }

        foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
            if ($el instanceof \DOMElement) {
                $this->sanitizeIntroDomElement($el);
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private function sanitizeIntroDomElement(\DOMElement $el): void
    {
        $tag = strtolower($el->tagName);
        $attrs = iterator_to_array($el->attributes);
        foreach ($attrs as $attr) {
            $name = strtolower($attr->nodeName);
            if (str_starts_with($name, 'on')) {
                $el->removeAttribute($attr->nodeName);

                continue;
            }
            if ($tag === 'a' && $name === 'href') {
                $href = trim($attr->nodeValue);
                if ($href === '' || ! preg_match('#\Ahttps?://#i', $href)) {
                    $el->removeAttribute('href');
                }

                continue;
            }
            $el->removeAttribute($attr->nodeName);
        }
    }

    private function postWithRateLimitRetry(string $apiKey, string $endpoint, array $payload, int $attempt = 0): ?Response
    {
        Log::debug('resend.http.request', [
            'endpoint' => $endpoint,
            'attempt' => $attempt + 1,
        ]);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post("https://api.resend.com/{$endpoint}", $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        Log::warning('resend.http.rate_limited', [
            'endpoint' => $endpoint,
            'attempt' => $attempt + 1,
            'retry_after' => $response->header('retry-after'),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($attempt >= 3) {
            Log::error('resend.http.rate_limit_give_up', [
                'endpoint' => $endpoint,
                'attempts' => $attempt + 1,
            ]);

            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postWithRateLimitRetry($apiKey, $endpoint, $payload, $attempt + 1);
    }

    private function postToPostmarkWithRateLimitRetry(string $apiKey, string $endpoint, array $payload, int $attempt = 0): ?Response
    {
        Log::debug('postmark.http.request', [
            'endpoint' => $endpoint,
            'attempt' => $attempt + 1,
        ]);

        $response = Http::withHeaders(['X-Postmark-Server-Token' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->post("https://api.postmarkapp.com/{$endpoint}", $payload);

        if ($response->status() !== 429) {
            return $response;
        }

        Log::warning('postmark.http.rate_limited', [
            'endpoint' => $endpoint,
            'attempt' => $attempt + 1,
            'retry_after' => $response->header('retry-after'),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($attempt >= 3) {
            Log::error('postmark.http.rate_limit_give_up', [
                'endpoint' => $endpoint,
                'attempts' => $attempt + 1,
            ]);

            return $response;
        }

        $retryAfter = max(1, min(30, (int) ($response->header('retry-after') ?? 1)));
        sleep($retryAfter);

        return $this->postToPostmarkWithRateLimitRetry($apiKey, $endpoint, $payload, $attempt + 1);
    }

    private function postmarkApiKey(): string
    {
        $apiKey = (string) config('services.postmark.key', '');

        return $apiKey !== '' ? $apiKey : (string) config('services.postmark.token', '');
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
    private function postmarkWebinarMetadata(Webinar $webinar, WebinarRegistrant $registrant, ?string $emailType): array
    {
        $metadata = [
            'source' => 'webinar',
            'webinar_id' => (string) $webinar->id,
            'registrant_id' => (string) $registrant->id,
        ];

        if ($emailType !== null && $emailType !== '') {
            $metadata['email_type'] = $emailType;
        }

        return $metadata;
    }

    private function resolveDynamicFrom(string $configuredFrom, string $hostName): string
    {
        $email = $this->extractEmailAddress($configuredFrom);
        $baseName = $this->extractDisplayName($configuredFrom) ?: 'OnPage CV';
        $host = trim($hostName) !== '' ? trim($hostName) : 'Host';
        $dynamicName = "{$host} via {$baseName}";

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
}
