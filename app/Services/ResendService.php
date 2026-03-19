<?php

namespace App\Services;

use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResendService
{
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
        $unsubscribeLink = route('webinar.unsubscribe', ['token' => $registrant->access_token]);
        $from = $this->resolveDynamicFrom($configuredFrom, $webinar->host_name);

        $webinarDescription = trim((string) ($webinar->description ?? ''));
        $descriptionHtml = $webinarDescription !== ''
            ? '<p style="margin: 0 0 12px 0; color: #4b5563; font-size: 14px; line-height: 1.55;">'.e($webinarDescription).'</p>'
            : '';

        $html = "
            <div style=\"background:#f3f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#111827;\">
                <div style=\"max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;\">
                    <div style=\"background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:18px 22px;\">
                        <p style=\"margin:0;color:#bfdbfe;font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:700;\">On-Demand Webinar</p>
                        <h1 style=\"margin:8px 0 0 0;color:#ffffff;font-size:24px;line-height:1.25;\">".e($webinar->title)."</h1>
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

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->post('https://api.resend.com/emails', [
                    'from' => $from,
                    'to' => [$registrant->email],
                    'subject' => $subject,
                    'html' => $html,
                ]);

            if ($response->failed()) {
                Log::warning('Resend API request failed.', [
                    'webinar_id' => $webinar->id,
                    'registrant_id' => $registrant->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
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
