<?php

namespace App\Mail;

use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WebinarAiNeedsAttentionMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly Webinar $webinar,
        public readonly WebinarRegistrant $registrant,
        public readonly string $attendeeQuestion,
        public readonly string $aiReply,
        public readonly string $attentionReason,
        public readonly ?string $senderAddress = null,
        public readonly ?string $senderName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->resolveSenderAddress(),
            subject: 'A webinar user needs your attention',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function resolveSenderAddress(): Address
    {
        $explicitAddress = trim((string) ($this->senderAddress ?? ''));
        $explicitName = trim((string) ($this->senderName ?? ''));
        if ($explicitAddress !== '') {
            return new Address($explicitAddress, $explicitName !== '' ? $explicitName : null);
        }

        $fallbackAddress = trim((string) config('mail.from.address', 'hello@example.com'));
        $fallbackName = trim((string) config('mail.from.name', config('app.name', 'Laravel')));
        $primaryProvider = strtolower(trim((string) config('services.email.primary', '')));
        $rawFrom = trim((string) match ($primaryProvider) {
            'postmark' => config('services.postmark.from', ''),
            'resend' => config('services.resend.from', ''),
            'elastic', 'elasticemail' => config('services.elastic.from', ''),
            'zeptomail' => config('services.zeptomail.from', ''),
            'ses_smtp', 'smtp' => config('services.email.ses_smtp_from_address', ''),
            default => '',
        });

        if ($rawFrom === '') {
            return new Address($fallbackAddress, $fallbackName);
        }

        if (preg_match('/^(?<name>.*)\s<(?<email>[^>]+)>$/', $rawFrom, $matches) === 1) {
            $email = trim((string) ($matches['email'] ?? ''));
            $name = trim((string) ($matches['name'] ?? ''));

            if ($email !== '') {
                return new Address($email, $name !== '' ? trim($name, '"\'') : $fallbackName);
            }
        }

        return new Address($rawFrom, $fallbackName);
    }

    private function buildHtml(): string
    {
        $webinarTitle = e((string) $this->webinar->title);
        $hostName = e((string) $this->webinar->host_name);
        $attendeeName = e((string) $this->registrant->name);
        $attendeeEmail = e((string) $this->registrant->email);
        $question = nl2br(e(trim($this->attendeeQuestion)));
        $reason = e(trim($this->attentionReason));
        $chatLink = route('admin.webinars.chat.show', ['webinar' => $this->webinar->id]);

        return "
            <div style=\"background:#f8fafc;padding:20px;font-family:Arial,Helvetica,sans-serif;color:#0f172a;\">
                <div style=\"max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;\">
                    <h1 style=\"margin:0 0 12px 0;font-size:22px;\">User needs your attention</h1>
                    <p style=\"margin:0 0 16px 0;font-size:14px;color:#334155;\">
                        Your webinar AI assistant could not answer with enough confidence and flagged this chat message for a human response.
                    </p>

                    <div style=\"margin:0 0 16px 0;padding:12px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:10px;\">
                        <p style=\"margin:0 0 6px 0;font-size:13px;\"><strong>Webinar:</strong> {$webinarTitle}</p>
                        <p style=\"margin:0 0 6px 0;font-size:13px;\"><strong>Host:</strong> {$hostName}</p>
                        <p style=\"margin:0 0 6px 0;font-size:13px;\"><strong>Attendee:</strong> {$attendeeName} ({$attendeeEmail})</p>
                        <p style=\"margin:0;font-size:13px;\"><strong>Reason:</strong> {$reason}</p>
                    </div>

                    <h2 style=\"margin:0 0 8px 0;font-size:15px;\">Attendee question</h2>
                    <div style=\"margin:0 0 16px 0;padding:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;font-size:14px;line-height:1.55;\">{$question}</div>

                    <a href=\"{$chatLink}\" style=\"display:inline-block;padding:10px 14px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:8px;font-size:13px;font-weight:700;\">Open Webinar Chat</a>
                </div>
            </div>
        ";
    }
}
