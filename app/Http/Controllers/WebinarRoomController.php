<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\Webinar;
use App\Models\WebinarOffer;
use App\Models\WebinarRegistrant;
use App\Models\WebinarView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WebinarRoomController extends Controller
{
    private const ACTIVE_VIEW_REUSE_SECONDS = 120;

    public function showPublic(Webinar $webinar): Response
    {
        abort_unless($webinar->is_published, 404);
        $payload = $this->cachedWebinarPayload($webinar->id);

        if (($payload['room_ended'] ?? false) === true) {
            return Inertia::render('public/WebinarRoom', [
                'webinar' => $payload,
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
            'webinar' => $payload,
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
            ->select([
                'id',
                'webinar_id',
                'name',
                'email',
                'access_token',
                'is_subscribed',
            ])
            ->where('access_token', $token)
            ->firstOrFail();

        $payload = $this->cachedWebinarPayload($registrant->webinar_id);

        // If the registrant has globally unsubscribed for this creator account,
        // block re-entry to the room as well as any further emails.
        if ($registrant->is_subscribed !== true) {
            return Inertia::render('public/UnsubscribeResult', [
                'webinarTitle' => (string) ($payload['title'] ?? 'Webinar'),
                'email' => $registrant->email,
            ]);
        }

        if (($payload['room_ended'] ?? false) === true) {
            return Inertia::render('public/WebinarRoom', [
                'webinar' => $payload,
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

        $now = Carbon::now();

        // Reuse only very recent active view rows to avoid duplicate refresh writes,
        // but still count genuinely new join sessions.
        $activeView = WebinarView::query()
            ->where('webinar_id', $registrant->webinar_id)
            ->where('registrant_id', $registrant->id)
            ->whereNull('left_at')
            ->latest('id')
            ->first();

        $sameClientAsActiveView = $activeView
            && (string) ($activeView->ip_address ?? '') === (string) request()->ip()
            && (string) ($activeView->user_agent ?? '') === (string) request()->userAgent();

        $activeViewIsRecent = $activeView
            && $activeView->joined_at
            && $activeView->joined_at->greaterThan($now->copy()->subSeconds(self::ACTIVE_VIEW_REUSE_SECONDS));

        $view = ($sameClientAsActiveView && $activeViewIsRecent)
            ? $activeView
            : null;

        $isNewView = false;

        if (! $view) {
            if ($activeView && $activeView->left_at === null) {
                $activeView->left_at = $now;
                $activeView->save();
            }

            $isNewView = true;
            $view = WebinarView::create([
                'webinar_id' => $registrant->webinar_id,
                'registrant_id' => $registrant->id,
                'joined_at' => $now,
                'session_started_at' => $now,
                'timeline_offset_seconds' => 0,
                'watch_duration_seconds' => 0,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }

        // Only write join analytics + "last_joined_at" once per active view.
        // This prevents repeated writes when the frontend reloads the room.
        if ($isNewView) {
            $registrant->update([
                'last_joined_at' => $now,
                'engagement_segment' => $registrant->engagement_segment ?: 'below_50',
                'engagement_segment_updated_at' => $registrant->engagement_segment_updated_at ?: $now,
            ]);

            AnalyticsEvent::create([
                'webinar_id' => $registrant->webinar_id,
                'registrant_id' => $registrant->id,
                'view_id' => $view->id,
                'event_type' => 'webinar_joined',
                'event_data' => [
                    'source' => 'public_room',
                ],
                'occurred_at' => $now,
            ]);
        }

        return Inertia::render('public/WebinarRoom', [
            'webinar' => $payload,
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

    public function trackOfferClick(Request $request, string $token, WebinarOffer $offer): JsonResponse
    {
        $registrant = WebinarRegistrant::query()
            ->where('access_token', $token)
            ->firstOrFail();

        abort_unless($registrant->webinar_id === $offer->webinar_id, 404);

        $validated = $request->validate([
            'source' => ['nullable', 'string', 'max:50'],
            'elapsed_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        AnalyticsEvent::create([
            'webinar_id' => $registrant->webinar_id,
            'registrant_id' => $registrant->id,
            'event_type' => 'offer_cta_clicked',
            'event_data' => [
                'offer_id' => $offer->id,
                'offer_title' => $offer->title,
                'button_text' => $offer->button_text,
                'button_url' => $offer->button_url,
                'source' => $validated['source'] ?? 'unknown',
                'elapsed_seconds' => $validated['elapsed_seconds'] ?? null,
            ],
            'occurred_at' => Carbon::now(),
        ]);

        $webinar = Webinar::query()
            ->select(['id', 'video_duration_seconds'])
            ->find($registrant->webinar_id);

        if ($webinar) {
            $this->applyRegistrantEngagement(
                $registrant,
                $webinar,
                max((int) $registrant->max_watch_duration_seconds, (int) ($validated['elapsed_seconds'] ?? 0)),
                (bool) $registrant->has_reached_50_percent,
                (bool) $registrant->has_watched_to_end,
                true,
            );
        }

        return response()->json([
            'tracked' => true,
        ]);
    }

    public function trackCtaClick(Request $request, string $token): JsonResponse
    {
        $registrant = WebinarRegistrant::query()
            ->where('access_token', $token)
            ->firstOrFail();

        $validated = $request->validate([
            'source' => ['nullable', 'string', 'max:50'],
            'url' => ['required', 'url', 'max:2048'],
            'elapsed_seconds' => ['nullable', 'integer', 'min:0'],
            'event_type' => ['nullable', 'string', 'in:webinar_cta_link_clicked,webinar_redirect_triggered'],
        ]);

        AnalyticsEvent::create([
            'webinar_id' => $registrant->webinar_id,
            'registrant_id' => $registrant->id,
            'event_type' => $validated['event_type'] ?? 'webinar_cta_link_clicked',
            'event_data' => [
                'source' => $validated['source'] ?? 'unknown',
                'url' => $validated['url'],
                'elapsed_seconds' => $validated['elapsed_seconds'] ?? null,
            ],
            'occurred_at' => Carbon::now(),
        ]);

        $webinar = Webinar::query()
            ->select(['id', 'video_duration_seconds'])
            ->find($registrant->webinar_id);

        if ($webinar) {
            $this->applyRegistrantEngagement(
                $registrant,
                $webinar,
                max((int) $registrant->max_watch_duration_seconds, (int) ($validated['elapsed_seconds'] ?? 0)),
                (bool) $registrant->has_reached_50_percent,
                (bool) $registrant->has_watched_to_end,
                true,
            );
        }

        return response()->json([
            'tracked' => true,
        ]);
    }

    public function trackWatchMilestone(Request $request, string $token): JsonResponse
    {
        $registrant = WebinarRegistrant::query()
            ->where('access_token', $token)
            ->firstOrFail();

        $webinar = Webinar::query()
            ->select(['id', 'video_duration_seconds'])
            ->findOrFail($registrant->webinar_id);

        $validated = $request->validate([
            'milestone' => ['required', 'string', 'in:watched_60_seconds,watched_50_percent,watched_to_end'],
            'watch_duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $view = WebinarView::query()
            ->where('registrant_id', $registrant->id)
            ->where('webinar_id', $registrant->webinar_id)
            ->whereNull('left_at')
            ->latest('id')
            ->first();

        if (! $view) {
            return response()->json([
                'tracked' => false,
                'reason' => 'no_active_view',
            ], 404);
        }

        $now = Carbon::now();

        return match ($validated['milestone']) {
            'watched_60_seconds' => (function () use ($view, $registrant, $webinar, $now) {
                $targetSeconds = 60;

                // Update duration so admin stats can use `watch_duration_seconds >= 60`.
                if ($view->watch_duration_seconds < $targetSeconds) {
                    $view->watch_duration_seconds = $targetSeconds;
                    $view->save();
                }

                $alreadyTracked = AnalyticsEvent::query()
                    ->where('view_id', $view->id)
                    ->where('event_type', 'webinar_watched_60_seconds')
                    ->exists();

                if (! $alreadyTracked) {
                    AnalyticsEvent::create([
                        'webinar_id' => $registrant->webinar_id,
                        'registrant_id' => $registrant->id,
                        'view_id' => $view->id,
                        'event_type' => 'webinar_watched_60_seconds',
                        'event_data' => [
                            'milestone_seconds' => $targetSeconds,
                            'watch_duration_seconds' => $targetSeconds,
                            'source' => 'public_room',
                        ],
                        'occurred_at' => $now,
                    ]);
                }

                $this->applyRegistrantEngagement(
                    $registrant,
                    $webinar,
                    max((int) $registrant->max_watch_duration_seconds, $targetSeconds),
                    (bool) $registrant->has_reached_50_percent,
                    (bool) $registrant->has_watched_to_end,
                    (bool) $registrant->has_offer_click,
                );

                return response()->json(['tracked' => true]);
            })(),

            'watched_50_percent' => (function () use ($view, $registrant, $webinar, $now) {
                $targetSeconds = $this->halfwayThresholdSeconds($webinar);

                if ($view->watch_duration_seconds < $targetSeconds) {
                    $view->watch_duration_seconds = $targetSeconds;
                    $view->save();
                }

                $alreadyTracked = AnalyticsEvent::query()
                    ->where('view_id', $view->id)
                    ->where('event_type', 'webinar_watched_50_percent')
                    ->exists();

                if (! $alreadyTracked) {
                    AnalyticsEvent::create([
                        'webinar_id' => $registrant->webinar_id,
                        'registrant_id' => $registrant->id,
                        'view_id' => $view->id,
                        'event_type' => 'webinar_watched_50_percent',
                        'event_data' => [
                            'milestone_seconds' => $targetSeconds,
                            'watch_duration_seconds' => $targetSeconds,
                            'source' => 'public_room',
                        ],
                        'occurred_at' => $now,
                    ]);
                }

                $this->applyRegistrantEngagement(
                    $registrant,
                    $webinar,
                    max((int) $registrant->max_watch_duration_seconds, $targetSeconds),
                    true,
                    (bool) $registrant->has_watched_to_end,
                    (bool) $registrant->has_offer_click,
                );

                return response()->json(['tracked' => true]);
            })(),

            'watched_to_end' => (function () use ($view, $registrant, $validated, $webinar, $now) {
                // Only finalize once.
                if ($view->left_at === null) {
                    $duration = $validated['watch_duration_seconds'] ?? null;
                    if ($duration === null || $duration <= 0) {
                        $duration = $view->session_started_at?->diffInSeconds($now) ?? 0;
                    }

                    if ($duration > 0 && $view->watch_duration_seconds < $duration) {
                        $view->watch_duration_seconds = $duration;
                    }

                    $view->left_at = $now;
                    $view->save();
                }

                $alreadyTracked = AnalyticsEvent::query()
                    ->where('view_id', $view->id)
                    ->where('event_type', 'webinar_watched_to_end')
                    ->exists();

                if (! $alreadyTracked) {
                    AnalyticsEvent::create([
                        'webinar_id' => $registrant->webinar_id,
                        'registrant_id' => $registrant->id,
                        'view_id' => $view->id,
                        'event_type' => 'webinar_watched_to_end',
                        'event_data' => [
                            'watch_duration_seconds' => $view->watch_duration_seconds,
                            'source' => 'public_room',
                        ],
                        'occurred_at' => $now,
                    ]);
                }

                $this->applyRegistrantEngagement(
                    $registrant,
                    $webinar,
                    max((int) $registrant->max_watch_duration_seconds, (int) $view->watch_duration_seconds),
                    true,
                    true,
                    (bool) $registrant->has_offer_click,
                );

                return response()->json(['tracked' => true]);
            })(),
        };
    }

    private function halfwayThresholdSeconds(Webinar $webinar): int
    {
        $durationSeconds = max(1, (int) ($webinar->video_duration_seconds ?? 5400));

        return max(1, (int) floor($durationSeconds * 0.5) + 1);
    }

    private function resolveEngagementSegment(bool $hasReached50Percent, bool $hasWatchedToEnd, bool $hasOfferClick): string
    {
        if ($hasWatchedToEnd && ! $hasOfferClick) {
            return 'completed_no_click';
        }

        if ($hasWatchedToEnd && $hasOfferClick) {
            return 'completed_clicked';
        }

        if ($hasReached50Percent) {
            return 'above_50';
        }

        return 'below_50';
    }

    private function applyRegistrantEngagement(
        WebinarRegistrant $registrant,
        Webinar $webinar,
        int $maxWatchSeconds,
        bool $hasReached50Percent,
        bool $hasWatchedToEnd,
        bool $hasOfferClick
    ): void {
        $resolvedReached50 = $hasReached50Percent || $maxWatchSeconds >= $this->halfwayThresholdSeconds($webinar);
        $segment = $this->resolveEngagementSegment($resolvedReached50, $hasWatchedToEnd, $hasOfferClick);

        $registrant->forceFill([
            'max_watch_duration_seconds' => max((int) $registrant->max_watch_duration_seconds, $maxWatchSeconds),
            'has_reached_50_percent' => $resolvedReached50,
            'has_watched_to_end' => $hasWatchedToEnd,
            'has_offer_click' => $hasOfferClick,
            'engagement_segment' => $segment,
            'engagement_segment_updated_at' => Carbon::now(),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedWebinarPayload(int $webinarId): array
    {
        return Cache::remember(
            "webinar:payload:{$webinarId}",
            now()->addSeconds(20),
            function () use ($webinarId): array {
                $webinar = Webinar::query()
                    ->with([
                        'offers' => fn ($query) => $query->where('is_active', true)->orderBy('trigger_second'),
                        'scheduledMessages' => fn ($query) => $query->where('is_active', true)->orderBy('trigger_second'),
                    ])
                    ->findOrFail($webinarId);

                return $this->buildWebinarPayload($webinar);
            }
        );
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
            'room_ended' => $webinar->hasEnded(),
            'playback_settings' => [
                'show_fake_viewers' => (bool) data_get($webinar->playback_settings, 'show_fake_viewers', true),
                'redirect_enabled' => (bool) data_get($webinar->playback_settings, 'redirect_enabled', false),
                'redirect_url' => (string) data_get($webinar->playback_settings, 'redirect_url', ''),
                'exit_popup_enabled' => (bool) data_get($webinar->playback_settings, 'exit_popup_enabled', false),
                'exit_popup_heading' => (string) data_get($webinar->playback_settings, 'exit_popup_heading', ''),
                'exit_popup_body' => (string) data_get($webinar->playback_settings, 'exit_popup_body', ''),
                'exit_popup_cta_text' => (string) data_get($webinar->playback_settings, 'exit_popup_cta_text', ''),
                'exit_popup_cta_url' => (string) data_get($webinar->playback_settings, 'exit_popup_cta_url', ''),
            ],
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
