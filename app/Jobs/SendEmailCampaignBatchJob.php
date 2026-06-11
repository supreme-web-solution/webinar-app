<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\EmailCampaignDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendEmailCampaignBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int, int> $recipientIds
     */
    public function __construct(
        private readonly int $campaignId,
        private readonly array $recipientIds,
    ) {
    }

    public function handle(EmailCampaignDeliveryService $deliveryService): void
    {
        if ($this->recipientIds === []) {
            Log::warning('email_campaign_batch_job.skipped_empty_batch', [
                'campaign_id' => $this->campaignId,
                'queue_job_id' => $this->job?->getJobId(),
            ]);

            return;
        }

        Log::info('email_campaign_batch_job.started', [
            'campaign_id' => $this->campaignId,
            'recipient_ids_count' => count($this->recipientIds),
            'queue_job_id' => $this->job?->getJobId(),
            'queue' => $this->job?->getQueue(),
        ]);

        $campaign = EmailCampaign::query()
            ->with('user')
            ->find($this->campaignId);

        if (! $campaign) {
            Log::warning('email_campaign_batch_job.skipped_missing_campaign', [
                'campaign_id' => $this->campaignId,
                'queue_job_id' => $this->job?->getJobId(),
            ]);

            return;
        }

        $recipients = EmailCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('is_subscribed', true)
            ->whereIn('id', $this->recipientIds)
            ->get();

        if ($recipients->isEmpty()) {
            Log::warning('email_campaign_batch_job.skipped_no_recipients', [
                'campaign_id' => $this->campaignId,
                'requested_recipient_ids_count' => count($this->recipientIds),
                'queue_job_id' => $this->job?->getJobId(),
            ]);

            return;
        }

        $result = $deliveryService->sendBatch($campaign, $recipients);

        if ($result['sent_recipient_ids'] === []) {
            Log::warning('email_campaign_batch_job.no_emails_sent', [
                'campaign_id' => $campaign->id,
                'subject' => $campaign->prefixedTitleLine(),
                'attempted' => $result['attempted'],
                'queue_job_id' => $this->job?->getJobId(),
            ]);

            return;
        }

        $now = Carbon::now();
        $timestamp = $now->toDateTimeString();
        EmailCampaignRecipient::query()
            ->whereIn('id', $result['sent_recipient_ids'])
            ->update([
                'send_count' => DB::raw('send_count + 1'),
                'last_sent_at' => $now,
                'first_sent_at' => DB::raw("COALESCE(first_sent_at, '{$timestamp}')"),
            ]);

        Log::info('email_campaign_batch_job.completed', [
            'campaign_id' => $campaign->id,
            'subject' => $campaign->prefixedTitleLine(),
            'attempted' => $result['attempted'],
            'sent_count' => count($result['sent_recipient_ids']),
            'queue_job_id' => $this->job?->getJobId(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('email_campaign_batch_job.failed', [
            'campaign_id' => $this->campaignId,
            'recipient_ids_count' => count($this->recipientIds),
            'queue_job_id' => $this->job?->getJobId(),
            'error' => $exception->getMessage(),
        ]);
    }
}
