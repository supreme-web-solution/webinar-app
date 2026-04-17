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
     * @return array{sent_registrant_ids: array<int, int>, attempted: int}
     */
    public function sendWebinarEmailBatch(Webinar $webinar, iterable $registrants, string $subject, string $intro): array
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
                'attempted' => 0,
            ];
        }

        $primary = $this->resolvePrimaryProvider($webinar);
        $fallback = $this->resolveFallbackProvider();

        $result = $this->sendBatchUsingProvider($primary, $webinar, $list, $subject, $intro);
        $attempted = count($list);

        if ($fallback !== null && $fallback !== $primary && count($result['sent_registrant_ids']) < $attempted) {
            $sentLookup = array_flip($result['sent_registrant_ids']);
            $remaining = array_values(array_filter(
                $list,
                fn (WebinarRegistrant $registrant): bool => ! isset($sentLookup[$registrant->id])
            ));

            if ($remaining !== []) {
                Log::warning('webinar_email.provider.batch.fallback', [
                    'webinar_id' => $webinar->id,
                    'failed_provider' => $primary,
                    'fallback_provider' => $fallback,
                    'remaining' => count($remaining),
                ]);

                $fallbackResult = $this->sendBatchUsingProvider($fallback, $webinar, $remaining, $subject, $intro);
                $result['sent_registrant_ids'] = array_values(array_unique([
                    ...$result['sent_registrant_ids'],
                    ...$fallbackResult['sent_registrant_ids'],
                ]));
            }
        }

        return [
            'sent_registrant_ids' => $result['sent_registrant_ids'],
            'attempted' => $attempted,
        ];
    }

    public function sendWebinarEmail(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro): bool
    {
        $primary = $this->resolvePrimaryProvider($webinar);
        $fallback = $this->resolveFallbackProvider();

        $sent = $this->sendSingleUsingProvider($primary, $webinar, $registrant, $subject, $intro);
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

            return $this->sendSingleUsingProvider($fallback, $webinar, $registrant, $subject, $intro);
        }

        return false;
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, attempted: int}
     */
    private function sendBatchUsingProvider(
        string $provider,
        Webinar $webinar,
        array $registrants,
        string $subject,
        string $intro
    ): array {
        return match ($provider) {
            'ses_smtp', 'smtp' => $this->sendBatchViaSmtp($webinar, $registrants, $subject, $intro),
            default => $this->sendBatchViaResend($webinar, $registrants, $subject, $intro),
        };
    }

    private function sendSingleUsingProvider(
        string $provider,
        Webinar $webinar,
        WebinarRegistrant $registrant,
        string $subject,
        string $intro
    ): bool {
        return match ($provider) {
            'ses_smtp', 'smtp' => $this->sendSingleViaSmtp($webinar, $registrant, $subject, $intro),
            default => $this->sendSingleViaResend($webinar, $registrant, $subject, $intro),
        };
    }

    /**
     * @param  array<int, WebinarRegistrant>  $registrants
     * @return array{sent_registrant_ids: array<int, int>, attempted: int}
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
                'attempted' => count($emails),
            ];
        }

        return [
            'sent_registrant_ids' => $registrantIds,
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
     * @return array{sent_registrant_ids: array<int, int>, attempted: int}
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

        $provider = strtolower(trim((string) config('services.email.primary', 'resend')));

        return in_array($provider, ['resend', 'ses_smtp', 'smtp'], true) ? $provider : 'resend';
    }

    private function resolveFallbackProvider(): ?string
    {
        $provider = strtolower(trim((string) config('services.email.fallback', 'ses_smtp')));
        if ($provider === '' || $provider === 'none') {
            return null;
        }

        return in_array($provider, ['resend', 'ses_smtp', 'smtp'], true) ? $provider : null;
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
