<?php

use App\Services\WebinarLifecycleEmailService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('webinars:send-lifecycle-emails', function (WebinarLifecycleEmailService $service) {
    $result = $service->dispatchDueEmails();
    $this->info("Reminders sent: {$result['reminders_sent']}, follow-ups sent: {$result['follow_ups_sent']}");
})->purpose('Dispatch due reminder emails and segmented profit follow-up emails for scheduled webinars');

Schedule::command('webinars:send-lifecycle-emails')->everyMinute()->withoutOverlapping();

Artisan::command('email:test-elastic {to : Recipient email address} {--subject=Elastic Email test : Email subject}', function () {
    $apiKey = (string) config('services.elastic.key', '');
    $configuredFrom = (string) config('services.elastic.from', config('mail.from.address', 'hello@example.com'));

    if ($apiKey === '') {
        $this->error('ELASTICEMAIL_API_KEY is not set in .env');

        return 1;
    }

    $to = trim((string) $this->argument('to'));
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        $this->error("Invalid email address: {$to}");

        return 1;
    }

    $subject = (string) $this->option('subject');
    $payload = [
        'Recipients' => [
            ['Email' => $to],
        ],
        'Content' => [
            'Body' => [
                [
                    'ContentType' => 'HTML',
                    'Content' => '<p>This is a test email from <strong>'.e(config('app.name')).'</strong> via Elastic Email.</p><p>Sent at '.now()->toDateTimeString().'</p>',
                    'Charset' => 'utf-8',
                ],
            ],
            'From' => $configuredFrom,
            'Subject' => $subject,
        ],
    ];

    $channelName = trim((string) config('services.elastic.channel', ''));
    if ($channelName !== '') {
        $payload['Options'] = ['ChannelName' => $channelName];
    }

    $this->info('Sending test email via Elastic Email...');
    $this->line("From: {$configuredFrom}");
    $this->line("To: {$to}");

    $response = Http::withHeaders(['X-ElasticEmail-ApiKey' => $apiKey])
        ->acceptJson()
        ->asJson()
        ->timeout(30)
        ->connectTimeout(10)
        ->post('https://api.elasticemail.com/v4/emails', $payload);

    if ($response->failed()) {
        $this->error("Elastic Email API failed (HTTP {$response->status()})");
        $this->line($response->body());

        return 1;
    }

    $body = $response->json();
    $transactionId = data_get($body, 'TransactionID');
    $messageId = data_get($body, 'MessageID');

    if (! $transactionId && ! $messageId) {
        $this->error('Elastic Email returned an unexpected response.');
        $this->line(json_encode($body, JSON_PRETTY_PRINT));

        return 1;
    }

    $this->info('Email accepted by Elastic Email.');
    if ($transactionId) {
        $this->line("Transaction ID: {$transactionId}");
    }
    if ($messageId) {
        $this->line("Message ID: {$messageId}");
    }

    return 0;
})->purpose('Send a test email through Elastic Email using configured API credentials');

Artisan::command('email:test-zeptomail {to : Recipient email address} {--subject=ZeptoMail test : Email subject}', function () {
    $apiKey = (string) config('services.zeptomail.key', '');
    $configuredFrom = (string) config('services.zeptomail.from', config('mail.from.address', 'hello@example.com'));

    if ($apiKey === '') {
        $this->error('ZEPTOMAIL_API_KEY is not set in .env');

        return 1;
    }

    $to = trim((string) $this->argument('to'));
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        $this->error("Invalid email address: {$to}");

        return 1;
    }

    $fromAddress = $configuredFrom;
    $fromName = '';
    if (preg_match('/^(?<name>.*)\s<(?<email>[^>]+)>$/', $configuredFrom, $matches) === 1) {
        $fromAddress = trim((string) ($matches['email'] ?? $configuredFrom));
        $fromName = trim((string) ($matches['name'] ?? ''), '"\'');
    }

    $subject = (string) $this->option('subject');
    $payload = [
        'from' => [
            'address' => $fromAddress,
            'name' => $fromName !== '' ? $fromName : (string) config('app.name', 'Laravel'),
        ],
        'to' => [
            [
                'email_address' => [
                    'address' => $to,
                ],
            ],
        ],
        'subject' => $subject,
        'htmlbody' => '<p>This is a test email from <strong>'.e(config('app.name')).'</strong> via ZeptoMail.</p><p>Sent at '.now()->toDateTimeString().'</p>',
    ];

    $this->info('Sending test email via ZeptoMail...');
    $this->line("From: {$configuredFrom}");
    $this->line("To: {$to}");

    $response = Http::withHeaders([
        'Authorization' => 'Zoho-enczapikey '.$apiKey,
    ])
        ->acceptJson()
        ->asJson()
        ->timeout(30)
        ->connectTimeout(10)
        ->post('https://api.zeptomail.com/v1.1/email', $payload);

    if ($response->failed() || data_get($response->json(), 'error') !== null) {
        $this->error("ZeptoMail API failed (HTTP {$response->status()})");
        $this->line($response->body());

        return 1;
    }

    $requestId = data_get($response->json(), 'request_id');
    if (! $requestId) {
        $this->error('ZeptoMail returned an unexpected response.');
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT));

        return 1;
    }

    $this->info('Email accepted by ZeptoMail.');
    $this->line("Request ID: {$requestId}");

    return 0;
})->purpose('Send a test email through ZeptoMail using configured API credentials');
