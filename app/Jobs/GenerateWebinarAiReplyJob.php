<?php

namespace App\Jobs;

use App\Events\WebinarChatMessageSent;
use App\Mail\WebinarAiNeedsAttentionMail;
use App\Models\ChatMessage;
use App\Models\WebinarRegistrant;
use App\Services\AI\WebinarAiAssistantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateWebinarAiReplyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $registrantId,
        public readonly int $attendeeMessageId,
    ) {}

    public function handle(WebinarAiAssistantService $assistant): void
    {
        $registrant = WebinarRegistrant::query()->with(['webinar.user'])->find($this->registrantId);
        if (! $registrant || ! $registrant->webinar) {
            return;
        }

        $attendeeMessage = ChatMessage::query()->find($this->attendeeMessageId);
        if (! $attendeeMessage || $attendeeMessage->sender_type !== 'attendee') {
            return;
        }

        $alreadyReplied = ChatMessage::query()
            ->where('webinar_id', $registrant->webinar_id)
            ->where('registrant_id', $registrant->id)
            ->where('sender_type', 'system')
            ->where('is_automated', true)
            ->where('meta->reply_to_message_id', $attendeeMessage->id)
            ->exists();

        if ($alreadyReplied) {
            return;
        }

        $result = $assistant->maybeGenerateReply($registrant->webinar, $registrant, (string) $attendeeMessage->message);
        if (! $result || trim((string) ($result['answer'] ?? '')) === '') {
            return;
        }

        $needsHumanAttention = (bool) ($result['needs_human_attention'] ?? false);
        if ($needsHumanAttention) {
            $this->notifyWebinarOwnerNeedsAttention(
                $registrant,
                (string) $attendeeMessage->message,
                (string) ($result['answer'] ?? ''),
                (string) ($result['attention_reason'] ?? 'unknown'),
            );

            return;
        }

        $assistantName = trim((string) data_get($registrant->webinar->ai_settings, 'assistant_name', ''));
        $hostName = trim((string) ($registrant->webinar->host_name ?? ''));
        $normalizedAssistant = strtolower($assistantName);

        if ($assistantName === '' || in_array($normalizedAssistant, ['ai webinar helper', 'webinar ai helper'], true)) {
            $assistantName = $hostName !== '' ? $hostName : 'Webinar Host';
        }

        $reply = ChatMessage::create([
            'webinar_id' => $registrant->webinar_id,
            'registrant_id' => $registrant->id,
            'sender_type' => 'system',
            'sender_name' => $assistantName,
            'message' => $result['answer'],
            'is_automated' => true,
            'meta' => [
                'reply_to_message_id' => $attendeeMessage->id,
                'classification' => $result['classification'] ?? null,
                'sources' => $result['sources'] ?? [],
                'needs_human_attention' => false,
                'attention_reason' => $result['attention_reason'] ?? null,
            ],
            'sent_at' => Carbon::now(),
        ]);

        Cache::forget("webinar:chat:{$registrant->access_token}");
        broadcast(new WebinarChatMessageSent($registrant->access_token, $reply))->toOthers();
    }

    private function notifyWebinarOwnerNeedsAttention(
        WebinarRegistrant $registrant,
        string $attendeeQuestion,
        string $aiReply,
        string $attentionReason,
    ): void {
        $webinar = $registrant->webinar;
        if (! $webinar) {
            return;
        }

        $ownerEmail = trim((string) optional($webinar->user)->email);
        if ($ownerEmail === '') {
            Log::warning('webinar.ai.needs_attention.owner_missing_email', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return;
        }

        $userSmtpConfig = $this->resolveOwnerSmtpTransportConfig($webinar);
        if ($userSmtpConfig !== null) {
            try {
                app('mail.manager')
                    ->build($userSmtpConfig['transport'])
                    ->to($ownerEmail)
                    ->send(new WebinarAiNeedsAttentionMail(
                        webinar: $webinar,
                        registrant: $registrant,
                        attendeeQuestion: $attendeeQuestion,
                        aiReply: $aiReply,
                        attentionReason: $attentionReason,
                        senderAddress: (string) ($userSmtpConfig['from_address'] ?? ''),
                        senderName: (string) ($userSmtpConfig['from_name'] ?? ''),
                    ));

                return;
            } catch (\Throwable $e) {
                Log::warning('webinar.ai.needs_attention.mailer_failed', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'owner_email' => $ownerEmail,
                    'mailer' => 'user_smtp',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $mailers = $this->resolveAttentionMailers();
        $lastException = null;

        foreach ($mailers as $mailer) {
            try {
                [$senderAddress, $senderName] = $this->resolveProviderSender($mailer);

                Mail::mailer($mailer)->to($ownerEmail)->send(new WebinarAiNeedsAttentionMail(
                    webinar: $webinar,
                    registrant: $registrant,
                    attendeeQuestion: $attendeeQuestion,
                    aiReply: $aiReply,
                    attentionReason: $attentionReason,
                    senderAddress: $senderAddress,
                    senderName: $senderName,
                ));

                return;
            } catch (\Throwable $e) {
                $lastException = $e;

                Log::warning('webinar.ai.needs_attention.mailer_failed', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'owner_email' => $ownerEmail,
                    'mailer' => $mailer,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::error('webinar.ai.needs_attention.email_failed', [
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'owner_email' => $ownerEmail,
            'mailers_tried' => $mailers,
            'message' => $lastException?->getMessage() ?? 'No configured mailers available for needs-attention email.',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function resolveAttentionMailers(): array
    {
        $primary = strtolower(trim((string) config('services.email.primary', '')));
        $fallback = strtolower(trim((string) config('services.email.fallback', '')));
        $mailers = [];

        foreach ([$primary, $fallback] as $candidate) {
            if ($candidate === '' || $candidate === 'none') {
                continue;
            }

            if ($this->isKnownMailer($candidate)) {
                $mailers[] = $candidate;

                continue;
            }

            // Support provider aliases that can point to an actual mailer name.
            if ($candidate === 'ses_smtp') {
                $sesSmtpMailer = strtolower(trim((string) config('services.email.ses_smtp_mailer', 'ses')));
                if ($this->isKnownMailer($sesSmtpMailer)) {
                    $mailers[] = $sesSmtpMailer;
                }
            }
        }

        if ($mailers === []) {
            $mailDefault = strtolower(trim((string) config('mail.default', 'log')));
            if ($this->isKnownMailer($mailDefault)) {
                $mailers[] = $mailDefault;
            }
        }

        return array_values(array_unique($mailers));
    }

    private function isKnownMailer(string $mailer): bool
    {
        $configuredMailers = (array) config('mail.mailers', []);

        return array_key_exists($mailer, $configuredMailers);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveProviderSender(string $mailer): array
    {
        $rawFrom = match ($mailer) {
            'postmark' => (string) config('services.postmark.from', ''),
            'resend' => (string) config('services.resend.from', ''),
            (string) config('services.email.ses_smtp_mailer', 'ses') => (string) config('services.email.ses_smtp_from_address', ''),
            default => '',
        };

        $rawFrom = trim($rawFrom);
        if ($rawFrom === '') {
            return [null, null];
        }

        return [
            $this->extractEmailAddress($rawFrom),
            $this->extractDisplayName($rawFrom) ?: (string) config('mail.from.name'),
        ];
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

    /**
     * @return array{transport: array<string, mixed>, from_address: string, from_name: string}|null
     */
    private function resolveOwnerSmtpTransportConfig($webinar): ?array
    {
        $owner = $webinar->relationLoaded('user') ? $webinar->user : $webinar->user()->first();
        if (! $owner || ! $owner->smtp_enabled) {
            return null;
        }

        $host = trim((string) ($owner->smtp_host ?? ''));
        $port = (int) ($owner->smtp_port ?? 0);
        $username = trim((string) ($owner->smtp_username ?? ''));
        $fromAddress = trim((string) ($owner->smtp_from_address ?? ''));
        $fromName = trim((string) ($owner->smtp_from_name ?? ''));

        if ($host === '' || $port <= 0 || $username === '' || $fromAddress === '' || $fromName === '') {
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
                'username' => $username,
                'password' => $owner->smtp_password ?: null,
                'timeout' => null,
            ],
            'from_address' => $fromAddress,
            'from_name' => $fromName,
        ];
    }
}
