<?php

namespace App\Services;

use App\Jobs\SendWebinarEmailsBatchJob;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

        foreach ($webinars as $webinar) {
            $scheduleAt = $webinar->scheduled_at?->copy();
            $endAt = $webinar->scheduledEndAt();

            if ($scheduleAt === null || $endAt === null) {
                continue;
            }

            $sendReminder = (bool) data_get($webinar->email_settings, 'send_reminder', true);
            $sendFollowUp = (bool) data_get($webinar->email_settings, 'send_follow_up', true);

            if ($sendReminder && $now->greaterThanOrEqualTo($scheduleAt->copy()->subHour()) && $now->lessThan($endAt)) {
                $remindersSent += $this->queueReminderEmails($webinar);
            }

            if ($sendFollowUp && $now->greaterThanOrEqualTo($endAt)) {
                $followUpsSent += $this->queueFollowUpEmails($webinar);
            }
        }

        return [
            'reminders_sent' => $remindersSent,
            'follow_ups_sent' => $followUpsSent,
        ];
    }

    private function queueReminderEmails(Webinar $webinar): int
    {
        $registrantIds = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->where('is_subscribed', true)
            ->whereNull('reminder_sent_at')
            ->pluck('id');

        return $this->dispatchBatches(
            $webinar,
            $registrantIds,
            'Reminder: '.$webinar->prefixedTitleLine(),
            'The webinar is starting soon. Click below to join the webinar.',
            'reminder_sent_at'
        );
    }

    private function queueFollowUpEmails(Webinar $webinar): int
    {
        $registrantIds = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->where('is_subscribed', true)
            ->whereNull('follow_up_sent_at')
            ->pluck('id');

        return $this->dispatchBatches(
            $webinar,
            $registrantIds,
            'Follow-up: '.$webinar->prefixedTitleLine(),
            'Thanks for attending. Reach out to the host if you need additional resources or the replay details.',
            'follow_up_sent_at'
        );
    }

    /**
     * @param Collection<int, int> $registrantIds
     */
    private function dispatchBatches(
        Webinar $webinar,
        Collection $registrantIds,
        string $subject,
        string $intro,
        string $markSentColumn
    ): int {
        $chunks = $registrantIds
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->chunk(100);

        foreach ($chunks as $index => $chunk) {
            $delaySeconds = (int) $index * 5;
            SendWebinarEmailsBatchJob::dispatch(
                $webinar->id,
                $chunk->all(),
                $subject,
                $intro,
                $markSentColumn
            )
                ->onQueue('emails')
                ->delay(now()->addSeconds($delaySeconds));

            Log::info('webinar_email_batch.dispatch', [
                'source' => 'lifecycle_service',
                'webinar_id' => $webinar->id,
                'batch_index' => $index,
                'batch_size' => $chunk->count(),
                'delay_seconds' => $delaySeconds,
                'mark_sent_column' => $markSentColumn,
                'queue' => 'emails',
                'subject' => $subject,
            ]);
        }

        return $chunks->sum(fn ($chunk) => $chunk->count());
    }
}
