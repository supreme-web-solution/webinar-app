<?php

namespace App\Services;

use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResendService
{
    /**
     * @return array{sent_registrant_ids: array<int, int>, attempted: int}
     */
    public function sendWebinarEmailBatch(Webinar $webinar, iterable $registrants, string $subject, string $intro): array
    {
        $apiKey = (string) config('services.resend.key');
        $configuredFrom = (string) config('services.resend.from', 'onboarding@resend.dev');

        if ($apiKey === '') {
            Log::warning('RESEND_API_KEY not configured. Skipping webinar batch email send.', [
                'webinar_id' => $webinar->id,
            ]);

            return [
                'sent_registrant_ids' => [],
                'attempted' => 0,
            ];
        }

        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);
        $emails = [];
        $registrantIds = [];

        foreach ($registrants as $registrant) {
            if (!$registrant instanceof WebinarRegistrant) {
                continue;
            }

            $registrantIds[] = $registrant->id;
            $emails[] = [
                'from' => $from,
                'to' => [$registrant->email],
                'subject' => $subject,
                'html' => $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
            ];
        }

        if ($emails === []) {
            return [
                'sent_registrant_ids' => [],
                'attempted' => 0,
            ];
        }

        $response = $this->postWithRateLimitRetry($apiKey, 'emails/batch', $emails);
        if (!$response || $response->failed()) {
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

    public function sendWebinarEmail(Webinar $webinar, WebinarRegistrant $registrant, string $subject, string $intro): bool
    {
        $apiKey = (string) config('services.resend.key');
        $configuredFrom = (string) config('services.resend.from', 'onboarding@resend.dev');

        if ($apiKey === '') {
            Log::warning('RESEND_API_KEY not configured. Skipping webinar email send.', [
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
            ]);

            return false;
        }

        $joinLink = route('webinar.room', ['token' => $registrant->access_token]);
        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);

        try {
            $response = $this->postWithRateLimitRetry($apiKey, 'emails', [
                    'from' => $from,
                    'to' => [$registrant->email],
                    'subject' => $subject,
                    'html' => $this->buildWebinarEmailHtml($webinar, $registrant, $intro),
                ]);

            if (!$response || $response->failed()) {
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

    private function buildWebinarEmailHtml(Webinar $webinar, WebinarRegistrant $registrant, string $intro): string
    {
        $joinLink = route('webinar.room', ['token' => $registrant->access_token]);
        $unsubscribeLink = route('webinar.unsubscribe', ['token' => $registrant->access_token]);
        $webinarDescription = trim((string) ($webinar->description ?? ''));
        $descriptionHtml = $webinarDescription !== ''
            ? '<p style="margin: 0 0 12px 0; color: #4b5563; font-size: 14px; line-height: 1.55;">'.e($webinarDescription).'</p>'
            : '';
        $prefixedTitle = e($webinar->prefixedTitleLine());

        return "
            <div style=\"background:#f3f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#111827;\">
                <div style=\"max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;\">
                    <div style=\"background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:18px 22px;\">
                        <p style=\"margin:0;color:#bfdbfe;font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:700;\">On-Demand Webinar</p>
                        <h1 style=\"margin:8px 0 0 0;color:#ffffff;font-size:24px;line-height:1.25;\">{$prefixedTitle}</h1>
                    </div>

                    <div style=\"padding:22px;\">
                        <p style=\"margin:0 0 6px 0;font-size:14px;color:#6b7280;\">Hosted by <strong style=\"color:#111827;\">".e($webinar->host_name)."</strong></p>
                        {$descriptionHtml}

                        <p style=\"margin:0 0 16px 0;color:#374151;font-size:15px;line-height:1.6;\">".e($intro)."</p>

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
