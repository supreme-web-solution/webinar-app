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

    /**
     * @param array<int, int> $registrantIds
     */
    public function __construct(
        private readonly int $webinarId,
        private readonly array $registrantIds,
        private readonly string $subject,
        private readonly string $intro,
        private readonly ?string $markSentColumn = null,
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

        $result = $resendService->sendWebinarEmailBatch(
            $webinar,
            $registrants,
            $this->subject,
            $this->intro
        );

        if (
            in_array($this->markSentColumn, [
                'reminder_sent_at',
                'follow_up_sent_at',
                'follow_up_lt_50_sent_at',
                'follow_up_gte_50_sent_at',
                'follow_up_completed_no_click_sent_at',
            ], true)
            && $result['sent_registrant_ids'] !== []
        ) {
            $updated = WebinarRegistrant::query()
                ->whereIn('id', $result['sent_registrant_ids'])
                ->update([
                    $this->markSentColumn => Carbon::now(),
                ]);
        }
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
