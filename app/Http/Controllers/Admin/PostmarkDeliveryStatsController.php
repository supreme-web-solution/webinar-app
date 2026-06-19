<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PostmarkEmailDelivery;
use App\Services\PostmarkDeliveryTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PostmarkDeliveryStatsController extends Controller
{
    public function index(Request $request, PostmarkDeliveryTrackingService $trackingService): Response
    {
        $userId = Auth::id();
        $days = max(1, min(90, (int) $request->query('days', 30)));

        $stats = $trackingService->statsForUser($userId, $days);
        $byEmailType = $trackingService->statsByEmailType($userId, $days);

        $recentDeliveries = PostmarkEmailDelivery::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('id')
            ->limit(100)
            ->get([
                'id',
                'email',
                'status',
                'email_type',
                'source_type',
                'subject',
                'accepted_at',
                'delivered_at',
                'bounced_at',
                'bounce_type',
                'created_at',
            ])
            ->map(fn (PostmarkEmailDelivery $delivery): array => [
                'id' => $delivery->id,
                'email' => $delivery->email,
                'status' => $delivery->status,
                'email_type' => $delivery->email_type,
                'source_type' => $delivery->source_type,
                'subject' => $delivery->subject,
                'accepted_at' => $delivery->accepted_at?->toDateTimeString(),
                'delivered_at' => $delivery->delivered_at?->toDateTimeString(),
                'bounced_at' => $delivery->bounced_at?->toDateTimeString(),
                'bounce_type' => $delivery->bounce_type,
                'created_at' => $delivery->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('admin/postmark/Index', [
            'days' => $days,
            'stats' => $stats,
            'byEmailType' => $byEmailType,
            'recentDeliveries' => $recentDeliveries,
            'webhookConfigured' => (string) config('services.postmark.webhook_token', '') !== '',
            'primaryProvider' => (string) config('services.email.primary', 'postmark'),
        ]);
    }
}
