<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\EmailActivityFeedBuilder;
use App\Services\EmailQueueMonitor;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class EmailActivityController extends Controller
{
    public function index(EmailQueueMonitor $queueMonitor, EmailActivityFeedBuilder $feedBuilder): Response
    {
        $failedJobs = $queueMonitor->recentFailedEmailJobs();

        return Inertia::render('admin/email-activity/Index', [
            'queue' => $queueMonitor->snapshot(),
            'logs' => $feedBuilder->latestForUser((int) Auth::id(), $failedJobs),
        ]);
    }
}
