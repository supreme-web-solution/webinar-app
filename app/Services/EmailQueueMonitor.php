<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailQueueMonitor
{
    private const EMAIL_JOB_NAMES = [
        'App\\Jobs\\SendWebinarEmailsBatchJob',
        'App\\Jobs\\SendEmailCampaignBatchJob',
    ];

    /**
     * @return array{
     *     queue_connection: string,
     *     email_queue: string,
     *     pending_on_email_queue: int,
     *     oldest_pending_seconds: int|null,
     *     failed_email_jobs: int,
     *     worker_hint: string|null
     * }
     */
    public function snapshot(): array
    {
        $emailQueue = (string) config('services.queues.emails', 'emails');
        $connection = (string) config('queue.default', 'sync');

        $pending = 0;
        $oldestSeconds = null;

        if (Schema::hasTable('jobs')) {
            $pending = (int) DB::table('jobs')->where('queue', $emailQueue)->count();

            $oldestAvailableAt = DB::table('jobs')
                ->where('queue', $emailQueue)
                ->orderBy('id')
                ->value('available_at');

            if ($oldestAvailableAt !== null) {
                $oldestSeconds = max(0, Carbon::now()->timestamp - (int) $oldestAvailableAt);
            }
        }

        $failedCount = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failedCount = (int) DB::table('failed_jobs')
                ->where(function ($query): void {
                    foreach (self::EMAIL_JOB_NAMES as $index => $jobName) {
                        if ($index === 0) {
                            $query->where('payload', 'like', '%'.$jobName.'%');
                        } else {
                            $query->orWhere('payload', 'like', '%'.$jobName.'%');
                        }
                    }
                })
                ->count();
        }

        $workerHint = null;
        if ($connection === 'sync') {
            $workerHint = 'Queue is set to sync — emails send during the web request, not in the background.';
        } elseif ($pending > 0 && $oldestSeconds !== null && $oldestSeconds >= 120) {
            $workerHint = 'Jobs have been waiting over 2 minutes. On the server, run Horizon or a queue worker (e.g. php artisan queue:work) for the "'.$emailQueue.'" queue.';
        } elseif ($pending > 0) {
            $workerHint = 'Send jobs are queued. If nothing moves for a few minutes, check that a queue worker is running.';
        }

        return [
            'queue_connection' => $connection,
            'email_queue' => $emailQueue,
            'pending_on_email_queue' => $pending,
            'oldest_pending_seconds' => $oldestSeconds,
            'failed_email_jobs' => $failedCount,
            'worker_hint' => $workerHint,
        ];
    }

    /**
     * @return array<int, array{id: int, job: string, failed_at: string|null, error: string|null}>
     */
    public function recentFailedEmailJobs(int $limit = 8): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        $rows = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'payload', 'exception', 'failed_at']);

        $results = [];

        foreach ($rows as $row) {
            $payload = (string) $row->payload;
            $job = null;

            foreach (self::EMAIL_JOB_NAMES as $jobName) {
                if (str_contains($payload, $jobName)) {
                    $job = class_basename($jobName);
                    break;
                }
            }

            if ($job === null) {
                continue;
            }

            $exception = (string) $row->exception;
            $firstLine = trim(strtok($exception, "\n") ?: $exception);

            $results[] = [
                'id' => (int) $row->id,
                'job' => $job,
                'failed_at' => $row->failed_at,
                'error' => mb_substr($firstLine, 0, 280),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
