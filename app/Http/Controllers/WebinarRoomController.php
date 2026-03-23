<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use App\Models\WebinarView;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class WebinarRoomController extends Controller
{
    public function showPublic(Webinar $webinar): Response
    {
        abort_unless($webinar->is_published, 404);

        $webinar->load([
            'offers' => fn ($query) => $query->where('is_active', true)->orderBy('trigger_second'),
            'scheduledMessages' => fn ($query) => $query->where('is_active', true)->orderBy('trigger_second'),
        ]);

        if ($webinar->hasEnded()) {
            return Inertia::render('public/WebinarRoom', [
                'webinar' => $this->buildWebinarPayload($webinar),
                'registrant' => [
                    'name' => '',
                    'email' => '',
                ],
                'chatToken' => null,
                'accessRequired' => false,
                'accessUrl' => null,
                'view' => null,
                'roomEnded' => true,
                'endedMessage' => 'This webinar has ended. Please contact the host for replay options.',
            ]);
        }

        return Inertia::render('public/WebinarRoom', [
            'webinar' => $this->buildWebinarPayload($webinar),
            'registrant' => [
                'name' => '',
                'email' => '',
            ],
            'chatToken' => null,
            'accessRequired' => true,
            'accessUrl' => route('webinar.room.access', ['webinar' => $webinar->uuid]),
            'view' => null,
            'roomEnded' => false,
            'endedMessage' => null,
        ]);
    }

    public function show(string $token): Response
    {
        $registrant = WebinarRegistrant::query()
            ->with([
                'webinar.offers' => fn ($query) => $query->where('is_active', true)->orderBy('trigger_second'),
                'webinar.scheduledMessages' => fn ($query) => $query->where('is_active', true)->orderBy('trigger_second'),
            ])
            ->where('access_token', $token)
            ->firstOrFail();

        $webinar = $registrant->webinar;

        if ($webinar->hasEnded()) {
            return Inertia::render('public/WebinarRoom', [
                'webinar' => $this->buildWebinarPayload($webinar),
                'registrant' => [
                    'name' => $registrant->name,
                    'email' => $registrant->email,
                ],
                'chatToken' => null,
                'accessRequired' => false,
                'accessUrl' => null,
                'view' => null,
                'roomEnded' => true,
                'endedMessage' => 'This webinar has ended. Please contact the host for replay options.',
            ]);
        }

        $registrant->update([
            'last_joined_at' => Carbon::now(),
        ]);

        $view = WebinarView::create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'joined_at' => Carbon::now(),
            'session_started_at' => Carbon::now(),
            'timeline_offset_seconds' => 0,
            'watch_duration_seconds' => 0,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        AnalyticsEvent::create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'view_id' => $view->id,
            'event_type' => 'webinar_joined',
            'event_data' => [
                'source' => 'public_room',
            ],
            'occurred_at' => Carbon::now(),
        ]);

        return Inertia::render('public/WebinarRoom', [
            'webinar' => $this->buildWebinarPayload($webinar),
            'registrant' => [
                'name' => $registrant->name,
                'email' => $registrant->email,
            ],
            'chatToken' => $token,
            'accessRequired' => false,
            'accessUrl' => null,
            'view' => [
                'id' => $view->id,
                'joined_at' => $view->joined_at?->toIso8601String(),
                'session_started_at' => $view->session_started_at?->toIso8601String(),
            ],
            'roomEnded' => false,
            'endedMessage' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWebinarPayload(Webinar $webinar): array
    {
        return [
            'id' => $webinar->id,
            'title' => $webinar->title,
            'host_name' => $webinar->host_name,
            'description' => $webinar->description,
            'video_source' => $webinar->video_source,
            'video_url' => $webinar->video_url,
            'video_duration_seconds' => $webinar->video_duration_seconds,
            'min_viewers' => $webinar->min_viewers,
            'max_viewers' => $webinar->max_viewers,
            'offers' => $webinar->offers->map(fn ($offer) => [
                'id' => $offer->id,
                'title' => $offer->title,
                'description' => $offer->description,
                'trigger_second' => $offer->trigger_second,
                'button_text' => $offer->button_text,
                'button_url' => $offer->button_url,
                'display_mode' => $offer->display_mode,
            ]),
            'scheduled_messages' => $webinar->scheduledMessages->map(fn ($message) => [
                'id' => $message->id,
                'trigger_second' => $message->trigger_second,
                'sender_name' => $message->sender_name,
                'message' => $message->message,
            ]),
        ];
    }
}
