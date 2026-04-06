<?php

use App\Services\WebinarLifecycleEmailService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('webinars:send-lifecycle-emails', function (WebinarLifecycleEmailService $service) {
    $result = $service->dispatchDueEmails();
    $this->info("Reminders sent: {$result['reminders_sent']}, follow-ups sent: {$result['follow_ups_sent']}");
})->purpose('Dispatch due reminder emails and segmented profit follow-up emails for scheduled webinars');

Schedule::command('webinars:send-lifecycle-emails')->everyMinute();
