<?php

namespace App\Services;

use App\Models\EmailActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EmailActivityLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $level,
        string $event,
        string $summary,
        array $context = [],
        ?int $userId = null,
    ): void {
        $level = in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)
            ? $level
            : 'info';

        try {
            EmailActivityLog::query()->create([
                'user_id' => $userId ?? Auth::id(),
                'level' => $level,
                'event' => $event,
                'summary' => mb_substr(trim($summary), 0, 512),
                'context' => $context === [] ? null : $context,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('email_activity_log.persist_failed', [
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
