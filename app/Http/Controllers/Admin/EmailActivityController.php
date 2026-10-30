<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailActivityLog;
use App\Models\PostmarkEmailDelivery;
use App\Services\EmailQueueMonitor;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class EmailActivityController extends Controller
{
    private const ACTIVITY_LIMIT = 60;

    private const DELIVERY_LIMIT = 40;

    public function index(EmailQueueMonitor $queueMonitor): Response
    {
        $userId = Auth::id();

        $activity = EmailActivityLog::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get(['id', 'level', 'event', 'summary', 'context', 'created_at'])
            ->map(fn (EmailActivityLog $log): array => [
                'id' => $log->id,
                'level' => $log->level,
                'event' => $log->event,
                'summary' => $log->summary,
                'context' => $log->context,
                'created_at' => $log->created_at?->toDateTimeString(),
            ]);

        $recentDeliveries = PostmarkEmailDelivery::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::DELIVERY_LIMIT)
            ->get([
                'id',
                'email',
                'status',
                'email_type',
                'subject',
                'accepted_at',
                'delivered_at',
                'bounced_at',
                'bounce_type',
                'bounce_description',
                'created_at',
            ])
            ->map(fn (PostmarkEmailDelivery $delivery): array => [
                'id' => $delivery->id,
                'email' => $delivery->email,
                'status' => $delivery->status,
                'email_type' => $delivery->email_type,
                'subject' => $delivery->subject,
                'accepted_at' => $delivery->accepted_at?->toDateTimeString(),
                'delivered_at' => $delivery->delivered_at?->toDateTimeString(),
                'bounced_at' => $delivery->bounced_at?->toDateTimeString(),
                'bounce_type' => $delivery->bounce_type,
                'bounce_description' => $delivery->bounce_description,
                'created_at' => $delivery->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('admin/email-activity/Index', [
            'queue' => $queueMonitor->snapshot(),
            'failedJobs' => $queueMonitor->recentFailedEmailJobs(),
            'activity' => $activity,
            'recentDeliveries' => $recentDeliveries,
            'primaryProvider' => (string) config('services.email.primary', 'postmark'),
            'webhookConfigured' => (string) config('services.postmark.webhook_token', '') !== '',
        ]);
    }
}
