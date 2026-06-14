<?php

namespace App\Services;

use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignUnsubscribe;
use App\Models\EmailUnsubscribe;
use App\Models\WebinarRegistrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EmailSuppressionService
{
    private const REASON = 'permanent-delivery-failure';

    /**
     * Unsubscribe registrants after Postmark suppression or invalid email so future
     * sends (including Notify All with forceResend) skip them.
     *
     * @param array<int, int> $registrantIds
     */
    public function suppressWebinarRegistrants(array $registrantIds): void
    {
        if ($registrantIds === []) {
            return;
        }

        $registrants = WebinarRegistrant::query()
            ->with('webinar:id,user_id')
            ->whereIn('id', $registrantIds)
            ->get(['id', 'webinar_id', 'email', 'access_token']);

        if ($registrants->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $processedEmailCreators = [];

        foreach ($registrants as $registrant) {
            $creatorUserId = $registrant->webinar?->user_id;
            if ($creatorUserId === null) {
                continue;
            }

            $cacheKey = strtolower($registrant->email).'|'.$creatorUserId;
            if (! isset($processedEmailCreators[$cacheKey])) {
                WebinarRegistrant::query()
                    ->where('email', $registrant->email)
                    ->whereHas('webinar', fn ($q) => $q->where('user_id', $creatorUserId))
                    ->update(['is_subscribed' => false]);

                $processedEmailCreators[$cacheKey] = true;
            }

            EmailUnsubscribe::updateOrCreate(
                ['registrant_id' => $registrant->id],
                [
                    'webinar_id' => $registrant->webinar_id,
                    'email' => $registrant->email,
                    'token' => hash('sha256', $registrant->access_token),
                    'unsubscribed_at' => $now,
                    'reason' => self::REASON,
                ],
            );
        }

        Log::info('email_suppression.webinar_registrants', [
            'registrant_ids_count' => count($registrantIds),
            'reason' => self::REASON,
        ]);
    }

    /**
     * @param array<int, int> $recipientIds
     */
    public function suppressCampaignRecipients(array $recipientIds): void
    {
        if ($recipientIds === []) {
            return;
        }

        $recipients = EmailCampaignRecipient::query()
            ->with('campaign:id,user_id')
            ->whereIn('id', $recipientIds)
            ->get(['id', 'campaign_id', 'email', 'access_token']);

        if ($recipients->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $processedEmailCreators = [];

        foreach ($recipients as $recipient) {
            $creatorUserId = $recipient->campaign?->user_id;
            if ($creatorUserId === null) {
                continue;
            }

            $cacheKey = strtolower($recipient->email).'|'.$creatorUserId;
            if (! isset($processedEmailCreators[$cacheKey])) {
                EmailCampaignRecipient::query()
                    ->where('email', $recipient->email)
                    ->whereHas('campaign', fn ($q) => $q->where('user_id', $creatorUserId))
                    ->update(['is_subscribed' => false]);

                $processedEmailCreators[$cacheKey] = true;
            }

            EmailCampaignUnsubscribe::updateOrCreate(
                ['recipient_id' => $recipient->id],
                [
                    'campaign_id' => $recipient->campaign_id,
                    'email' => $recipient->email,
                    'token' => hash('sha256', $recipient->access_token),
                    'unsubscribed_at' => $now,
                    'reason' => self::REASON,
                ],
            );
        }

        Log::info('email_suppression.campaign_recipients', [
            'recipient_ids_count' => count($recipientIds),
            'reason' => self::REASON,
        ]);
    }
}
