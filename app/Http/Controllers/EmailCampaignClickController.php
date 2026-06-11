<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaignClick;
use App\Models\EmailCampaignRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmailCampaignClickController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $recipient = EmailCampaignRecipient::query()
            ->with('campaign')
            ->where('access_token', $token)
            ->firstOrFail();

        $campaign = $recipient->campaign;
        if (! $campaign) {
            abort(404);
        }

        $destinationUrl = trim((string) $campaign->cta_url);
        if ($destinationUrl === '' || ! filter_var($destinationUrl, FILTER_VALIDATE_URL)) {
            abort(404);
        }

        $now = Carbon::now();
        EmailCampaignClick::query()->create([
            'campaign_id' => $campaign->id,
            'recipient_id' => $recipient->id,
            'destination_url' => $destinationUrl,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'clicked_at' => $now,
        ]);

        $timestamp = $now->toDateTimeString();
        EmailCampaignRecipient::query()
            ->where('id', $recipient->id)
            ->update([
                'click_count' => DB::raw('click_count + 1'),
                'last_clicked_at' => $now,
                'first_clicked_at' => DB::raw("COALESCE(first_clicked_at, '{$timestamp}')"),
            ]);

        Log::info('email_campaign.click.tracked', [
            'campaign_id' => $campaign->id,
            'recipient_id' => $recipient->id,
            'email' => $recipient->email,
            'destination_url' => $destinationUrl,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->away($destinationUrl);
    }
}
