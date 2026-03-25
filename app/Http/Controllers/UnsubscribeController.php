<?php

namespace App\Http\Controllers;

use App\Models\EmailUnsubscribe;
use App\Models\WebinarRegistrant;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class UnsubscribeController extends Controller
{
    public function __invoke(string $token): Response
    {
        $registrant = WebinarRegistrant::query()
            ->with('webinar:id,title')
            ->where('access_token', $token)
            ->firstOrFail();

        // Global unsubscribe per creator account:
        // once a registrant unsubscribes, prevent them from joining/receiving emails
        // for all webinars created by the same user.
        $creatorUserId = $registrant->webinar->user_id;
        $email = $registrant->email;

        $registrant->update([
            'is_subscribed' => false,
        ]);

        WebinarRegistrant::query()
            ->where('email', $email)
            ->whereHas('webinar', fn ($q) => $q->where('user_id', $creatorUserId))
            ->update([
                'is_subscribed' => false,
            ]);

        EmailUnsubscribe::updateOrCreate(
            ['registrant_id' => $registrant->id],
            [
                'webinar_id' => $registrant->webinar_id,
                'email' => $registrant->email,
                'token' => hash('sha256', $registrant->access_token),
                'unsubscribed_at' => Carbon::now(),
                'reason' => 'one-click-unsubscribe',
            ],
        );

        return Inertia::render('public/UnsubscribeResult', [
            'webinarTitle' => $registrant->webinar?->title,
            'email' => $registrant->email,
        ]);
    }
}
