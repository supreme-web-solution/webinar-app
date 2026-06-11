<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignUnsubscribe;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class EmailCampaignUnsubscribeController extends Controller
{
    public function __invoke(string $token): Response
    {
        $recipient = EmailCampaignRecipient::query()
            ->with('campaign:id,title,user_id')
            ->where('access_token', $token)
            ->firstOrFail();

        $creatorUserId = $recipient->campaign->user_id;
        $email = $recipient->email;

        $recipient->update([
            'is_subscribed' => false,
        ]);

        EmailCampaignRecipient::query()
            ->where('email', $email)
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $creatorUserId))
            ->update([
                'is_subscribed' => false,
            ]);

        EmailCampaignUnsubscribe::updateOrCreate(
            ['recipient_id' => $recipient->id],
            [
                'campaign_id' => $recipient->campaign_id,
                'email' => $recipient->email,
                'token' => hash('sha256', $recipient->access_token),
                'unsubscribed_at' => Carbon::now(),
                'reason' => 'one-click-unsubscribe',
            ],
        );

        return Inertia::render('public/UnsubscribeResult', [
            'webinarTitle' => null,
            'campaignTitle' => $recipient->campaign?->title,
            'email' => $recipient->email,
        ]);
    }
}
