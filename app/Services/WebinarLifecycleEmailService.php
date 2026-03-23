<?php

namespace App\Services;

use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Support\Carbon;

class WebinarLifecycleEmailService
{
    public function dispatchDueEmails(): array
    {
        $now = Carbon::now();
        $remindersSent = 0;
        $followUpsSent = 0;

        $webinars = Webinar::query()
            ->where('is_published', true)
            ->where('schedule_mode', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->get();

        $resendService = app(ResendService::class);

        foreach ($webinars as $webinar) {
            $scheduleAt = $webinar->scheduled_at?->copy();
            $endAt = $webinar->scheduledEndAt();

            if ($scheduleAt === null || $endAt === null) {
                continue;
            }

            $sendReminder = (bool) data_get($webinar->email_settings, 'send_reminder', true);
            $sendFollowUp = (bool) data_get($webinar->email_settings, 'send_follow_up', true);

            if ($sendReminder && $now->greaterThanOrEqualTo($scheduleAt->copy()->subHour()) && $now->lessThan($endAt)) {
                $remindersSent += $this->sendReminderEmails($webinar, $resendService, $now);
            }

            if ($sendFollowUp && $now->greaterThanOrEqualTo($endAt)) {
                $followUpsSent += $this->sendFollowUpEmails($webinar, $resendService, $now);
            }
        }

        return [
            'reminders_sent' => $remindersSent,
            'follow_ups_sent' => $followUpsSent,
        ];
    }

    private function sendReminderEmails(Webinar $webinar, ResendService $resendService, Carbon $now): int
    {
        $sent = 0;
        $registrants = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->where('is_subscribed', true)
            ->whereNull('reminder_sent_at')
            ->get();

        foreach ($registrants as $registrant) {
            $didSend = $resendService->sendWebinarEmail(
                $webinar,
                $registrant,
                'Reminder: '.$webinar->prefixedTitleLine(),
                'Your webinar starts soon. Click below to join when you are ready.'
            );

            if ($didSend) {
                $registrant->forceFill([
                    'reminder_sent_at' => $now,
                ])->save();
                $sent++;
            }
        }

        return $sent;
    }

    private function sendFollowUpEmails(Webinar $webinar, ResendService $resendService, Carbon $now): int
    {
        $sent = 0;
        $registrants = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->where('is_subscribed', true)
            ->whereNull('follow_up_sent_at')
            ->get();

        foreach ($registrants as $registrant) {
            $didSend = $resendService->sendWebinarEmail(
                $webinar,
                $registrant,
                'Follow-up: '.$webinar->prefixedTitleLine(),
                'Thanks for attending. Reach out to the host if you need additional resources or replay details.'
            );

            if ($didSend) {
                $registrant->forceFill([
                    'follow_up_sent_at' => $now,
                ])->save();
                $sent++;
            }
        }

        return $sent;
    }
}
