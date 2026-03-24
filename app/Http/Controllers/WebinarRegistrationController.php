<?php

namespace App\Http\Controllers;

use App\Jobs\SendWebinarEmailsBatchJob;
use App\Http\Requests\Webinar\StoreWebinarRegistrantRequest;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebinarRegistrationController extends Controller
{
    public function show(Webinar $webinar): Response
    {
        abort_unless($webinar->is_published, 404);
        abort_if($webinar->hasEnded(), 404);

        return Inertia::render('public/Register', [
            'webinar' => [
                'id' => $webinar->id,
                'title' => $webinar->title,
                'host_name' => $webinar->host_name,
                'description' => $webinar->description,
                'thumbnail_path' => $webinar->thumbnail_path,
                'uuid' => $webinar->uuid,
                'registration_settings' => $webinar->registration_settings ?? [
                    'buttons' => [
                        [
                            'label' => 'Join Webinar Now',
                            'enabled' => true,
                            'is_primary' => true,
                            'urgency_mode' => 'none',
                            'urgency_minutes' => null,
                        ],
                        [
                            'label' => 'Secure My Seat',
                            'enabled' => false,
                            'is_primary' => false,
                            'urgency_mode' => 'minutes',
                            'urgency_minutes' => 15,
                        ],
                        [
                            'label' => 'Join Live Session',
                            'enabled' => false,
                            'is_primary' => false,
                            'urgency_mode' => 'live',
                            'urgency_minutes' => null,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function store(StoreWebinarRegistrantRequest $request, Webinar $webinar): RedirectResponse
    {
        abort_unless($webinar->is_published, 404);
        if ($webinar->hasEnded()) {
            return back()->withErrors([
                'email' => 'This webinar has ended. Please contact the host for a replay or next session.',
            ]);
        }

        $validated = $request->validated();
        $sendConfirmation = (bool) data_get($webinar->email_settings, 'send_confirmation', true);

        $registrant = WebinarRegistrant::firstOrNew([
            'webinar_id' => $webinar->id,
            'email' => $validated['email'],
        ]);

        $isNewRegistrant = !$registrant->exists;
        $registrant->name = $validated['name'];
        $registrant->registered_at = $registrant->registered_at ?? Carbon::now();
        $registrant->is_subscribed = true;
        $registrant->access_token = $registrant->access_token ?: Str::random(40);
        $registrant->save();

        if ($isNewRegistrant && $sendConfirmation) {
            SendWebinarEmailsBatchJob::dispatch(
                $webinar->id,
                [$registrant->id],
                $webinar->prefixedTitleLine(),
                'Thanks for registering. Use the button below to join the webinar.'
            )->onQueue('emails');

            Log::info('webinar_email_batch.dispatch', [
                'source' => 'registration_store',
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'batch_size' => 1,
                'delay_seconds' => 0,
                'queue' => 'emails',
                'subject' => $webinar->prefixedTitleLine(),
            ]);
        }

        return redirect()
            ->route('webinar.room', ['token' => $registrant->access_token])
            ->with('success', 'Registration complete. Welcome to the webinar.');
    }

    public function accessFromJoinLink(StoreWebinarRegistrantRequest $request, Webinar $webinar): RedirectResponse
    {
        abort_unless($webinar->is_published, 404);
        if ($webinar->hasEnded()) {
            return back()->withErrors([
                'email' => 'This webinar has ended. Please contact the host for more information.',
            ]);
        }

        $validated = $request->validated();
        $sendConfirmation = (bool) data_get($webinar->email_settings, 'send_confirmation', true);

        $registrant = WebinarRegistrant::firstOrNew([
            'webinar_id' => $webinar->id,
            'email' => $validated['email'],
        ]);

        $isNewRegistrant = !$registrant->exists;
        $registrant->name = $validated['name'];
        $registrant->registered_at = $registrant->registered_at ?? Carbon::now();
        $registrant->is_subscribed = true;
        $registrant->access_token = $registrant->access_token ?: Str::random(40);
        $registrant->save();

        if ($isNewRegistrant && $sendConfirmation) {
            SendWebinarEmailsBatchJob::dispatch(
                $webinar->id,
                [$registrant->id],
                $webinar->prefixedTitleLine(),
                'Thanks for registering. Use the button below to join the webinar.'
            )->onQueue('emails');

            Log::info('webinar_email_batch.dispatch', [
                'source' => 'registration_access_from_join_link',
                'webinar_id' => $webinar->id,
                'registrant_id' => $registrant->id,
                'batch_size' => 1,
                'delay_seconds' => 0,
                'queue' => 'emails',
                'subject' => $webinar->prefixedTitleLine(),
            ]);
        }

        return redirect()->route('webinar.room', ['token' => $registrant->access_token]);
    }
}
