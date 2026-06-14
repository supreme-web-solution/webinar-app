<?php

namespace App\Jobs;

use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use App\Services\ResendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SendWebinarEmailsBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LIFECYCLE_SENT_COLUMNS = [
        'reminder_sent_at',
        'follow_up_sent_at',
        'follow_up_lt_50_sent_at',
        'follow_up_gte_50_sent_at',
        'follow_up_completed_no_click_sent_at',
    ];

    /**
     * @param array<int, int> $registrantIds
     */
    public function __construct(
        private readonly int $webinarId,
        private readonly array $registrantIds,
        private readonly string $subject,
        private readonly string $intro,
        private readonly ?string $markSentColumn = null,
        private readonly bool $forceResend = false,
    ) {
    }

    public function handle(ResendService $resendService): void
    {
        if ($this->registrantIds === []) {
            Log::warning('webinar_email_batch_job.skipped_empty_batch', [
                'webinar_id' => $this->webinarId,
                'queue_job_id' => $this->job?->getJobId(),
            ]);

            return;
        }

        Log::info('webinar_email_batch_job.started', [
            'webinar_id' => $this->webinarId,
            'registrant_ids_count' => count($this->registrantIds),
            'mark_sent_column' => $this->markSentColumn,
            'force_resend' => $this->forceResend,
            'queue_job_id' => $this->job?->getJobId(),
            'queue' => $this->job?->getQueue(),
        ]);

        $webinar = Webinar::query()->find($this->webinarId);
        if (!$webinar) {
            Log::warning('webinar_email_batch_job.skipped_missing_webinar', [
                'webinar_id' => $this->webinarId,
                'queue_job_id' => $this->job?->getJobId(),
            ]);

            return;
        }

        $registrants = WebinarRegistrant::query()
            ->where('webinar_id', $this->webinarId)
            ->where('is_subscribed', true)
            ->whereIn('id', $this->registrantIds)
            ->get();

        if ($registrants->isEmpty()) {
            Log::warning('webinar_email_batch_job.skipped_no_subscribed_registrants', [
                'webinar_id' => $this->webinarId,
                'requested_registrant_ids_count' => count($this->registrantIds),
                'queue_job_id' => $this->job?->getJobId(),
            ]);

            return;
        }

        if (
            ! $this->forceResend
            && in_array($this->markSentColumn, self::LIFECYCLE_SENT_COLUMNS, true)
        ) {
            $registrants = $registrants->filter(
                fn (WebinarRegistrant $registrant): bool => $registrant->{$this->markSentColumn} === null
            )->values();

            if ($registrants->isEmpty()) {
                Log::info('webinar_email_batch_job.skipped_already_sent', [
                    'webinar_id' => $this->webinarId,
                    'mark_sent_column' => $this->markSentColumn,
                    'requested_registrant_ids_count' => count($this->registrantIds),
                    'queue_job_id' => $this->job?->getJobId(),
                ]);

                return;
            }
        }

        $result = $resendService->sendWebinarEmailBatch(
            $webinar,
            $registrants,
            $this->subject,
            $this->intro
        );

        $terminalRegistrantIds = array_values(array_unique([
            ...$result['sent_registrant_ids'],
            ...$result['skipped_registrant_ids'],
        ]));

        if (
            in_array($this->markSentColumn, self::LIFECYCLE_SENT_COLUMNS, true)
            && $terminalRegistrantIds !== []
        ) {
            WebinarRegistrant::query()
                ->whereIn('id', $terminalRegistrantIds)
                ->update([
                    $this->markSentColumn => Carbon::now(),
                ]);
        }

        $sentCount = count($result['sent_registrant_ids']);
        $skippedCount = count($result['skipped_registrant_ids']);
        $failedCount = max(0, $result['attempted'] - $sentCount - $skippedCount);

        if ($sentCount === 0 && $skippedCount === 0) {
            Log::warning('webinar_email_batch_job.no_emails_sent', [
                'webinar_id' => $this->webinarId,
                'subject' => $this->subject,
                'attempted' => $result['attempted'],
                'queue_job_id' => $this->job?->getJobId(),
            ]);

            return;
        }

        Log::info('webinar_email_batch_job.completed', [
            'webinar_id' => $this->webinarId,
            'subject' => $this->subject,
            'mark_sent_column' => $this->markSentColumn,
            'attempted' => $result['attempted'],
            'sent_count' => $sentCount,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
            'queue_job_id' => $this->job?->getJobId(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('webinar_email_batch_job.failed', [
            'webinar_id' => $this->webinarId,
            'registrant_ids_count' => count($this->registrantIds),
            'mark_sent_column' => $this->markSentColumn,
            'subject' => $this->subject,
            'queue_job_id' => $this->job?->getJobId(),
            'error' => $exception->getMessage(),
        ]);
    }
}
