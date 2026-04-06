<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use App\Models\WebinarView;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $user = Auth::user();
        $userId = $user->id;

        $webinarBase = Webinar::query()->where('user_id', $userId);

        $totalWebinars = (clone $webinarBase)->count();
        $publishedWebinars = (clone $webinarBase)->where('is_published', true)->count();
        $draftWebinars = $totalWebinars - $publishedWebinars;

        $totalRegistrants = WebinarRegistrant::query()
            ->whereHas('webinar', fn ($q) => $q->where('user_id', $userId))
            ->count();

        $totalViews = WebinarView::query()
            ->whereHas('webinar', fn ($q) => $q->where('user_id', $userId))
            ->count();

        $totalChatMessages = ChatMessage::query()
            ->whereHas('webinar', fn ($q) => $q->where('user_id', $userId))
            ->count();

        $below50Segment = WebinarRegistrant::query()
            ->where('engagement_segment', 'below_50')
            ->whereHas('webinar', fn ($q) => $q->where('user_id', $userId))
            ->count();

        $above50Segment = WebinarRegistrant::query()
            ->where('engagement_segment', 'above_50')
            ->whereHas('webinar', fn ($q) => $q->where('user_id', $userId))
            ->count();

        $completedNoClickSegment = WebinarRegistrant::query()
            ->where('engagement_segment', 'completed_no_click')
            ->whereHas('webinar', fn ($q) => $q->where('user_id', $userId))
            ->count();

        $recentWebinars = Webinar::query()
            ->where('user_id', $userId)
            ->withCount(['registrants', 'views'])
            ->latest('updated_at')
            ->limit(6)
            ->get()
            ->map(fn (Webinar $w) => [
                'id' => $w->id,
                'title' => $w->title,
                'uuid' => $w->uuid,
                'host_name' => $w->host_name,
                'schedule_mode' => $w->schedule_mode ?: 'scheduled',
                'has_ended' => $w->hasEnded(),
                'is_published' => $w->is_published,
                'video_source' => $w->video_source,
                'registrants_count' => $w->registrants_count,
                'views_count' => $w->views_count,
                'scheduled_at_label' => $w->scheduled_at !== null
                    ? $w->scheduled_at->copy()->timezone($w->scheduled_timezone ?: 'UTC')->format('M j, Y · g:i a')
                    : null,
                'scheduled_timezone' => $w->scheduled_timezone ?: config('app.timezone', 'UTC'),
                'updated_at' => $w->updated_at?->toDateTimeString(),
                'edit_url' => route('admin.webinars.edit', $w),
                'registration_link' => route('webinar.register', ['webinar' => $w->uuid]),
                'chat_link' => route('admin.webinars.chat.show', ['webinar' => $w->id]),
            ]);

        return Inertia::render('Dashboard', [
            'user' => [
                'name' => $user->name,
            ],
            'stats' => [
                'total_webinars' => $totalWebinars,
                'published_webinars' => $publishedWebinars,
                'draft_webinars' => $draftWebinars,
                'total_registrants' => $totalRegistrants,
                'total_views' => $totalViews,
                'total_chat_messages' => $totalChatMessages,
                'segment_below_50' => $below50Segment,
                'segment_above_50' => $above50Segment,
                'segment_completed_no_click' => $completedNoClickSegment,
            ],
            'recentWebinars' => $recentWebinars,
        ]);
    }
}
