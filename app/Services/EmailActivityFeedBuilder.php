<?php

namespace App\Services;

use App\Models\EmailActivityLog;
use App\Models\PostmarkEmailDelivery;
use Illuminate\Support\Carbon;

class EmailActivityFeedBuilder
{
    private const LIMIT = 80;

    /**
     * @param  array<int, array{id: int, job: string, failed_at: string|null, error: string|null}>  $failedJobs
     * @return array<int, array{
     *     id: string,
     *     level: string,
     *     message: string,
     *     created_at: string|null,
     * }>
     */
    public function latestForUser(int $userId, array $failedJobs = []): array
    {
        $entries = [];

        $activity = EmailActivityLog::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'level', 'summary', 'created_at']);

        foreach ($activity as $log) {
            $entries[] = [
                'sort_key' => $this->sortKey($log->created_at, (int) $log->id, 'a'),
                'id' => 'activity-'.$log->id,
                'level' => $log->level,
                'message' => $log->summary,
                'created_at' => $log->created_at?->toDateTimeString(),
            ];
        }

        $deliveries = PostmarkEmailDelivery::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get([
                'id',
                'email',
                'status',
                'subject',
                'email_type',
                'status_detail',
                'bounce_description',
                'accepted_at',
                'delivered_at',
                'created_at',
            ]);

        foreach ($deliveries as $delivery) {
            $entries[] = [
                'sort_key' => $this->sortKey($delivery->created_at, (int) $delivery->id, 'd'),
                'id' => 'delivery-'.$delivery->id,
                'level' => $this->levelForDeliveryStatus((string) $delivery->status),
                'message' => $this->messageForDelivery($delivery),
                'created_at' => $delivery->created_at?->toDateTimeString(),
            ];
        }

        foreach ($failedJobs as $job) {
            $failedAt = $job['failed_at'] ?? null;
            $entries[] = [
                'sort_key' => $this->sortKey(
                    is_string($failedAt) && $failedAt !== '' ? Carbon::parse($failedAt) : null,
                    (int) $job['id'],
                    'f',
                ),
                'id' => 'failed-job-'.$job['id'],
                'level' => 'error',
                'message' => 'Queue job failed ('.$job['job'].'): '.($job['error'] ?? 'Unknown error'),
                'created_at' => is_string($failedAt) ? $failedAt : null,
            ];
        }

        usort($entries, fn (array $a, array $b): int => $b['sort_key'] <=> $a['sort_key']);

        return array_map(
            fn (array $entry): array => [
                'id' => $entry['id'],
                'level' => $entry['level'],
                'message' => $entry['message'],
                'created_at' => $entry['created_at'],
            ],
            array_slice($entries, 0, self::LIMIT),
        );
    }

    private function sortKey(?\DateTimeInterface $time, int $id, string $prefix): string
    {
        $timestamp = $time !== null
            ? $time->format('Y-m-d H:i:s.u')
            : '1970-01-01 00:00:00.000000';

        return $timestamp.'-'.$prefix.'-'.str_pad((string) $id, 12, '0', STR_PAD_LEFT);
    }

    private function levelForDeliveryStatus(string $status): string
    {
        return match ($status) {
            PostmarkEmailDelivery::STATUS_FAILED,
            PostmarkEmailDelivery::STATUS_BOUNCED,
            PostmarkEmailDelivery::STATUS_SPAM_COMPLAINT => 'error',
            PostmarkEmailDelivery::STATUS_SUPPRESSED => 'warning',
            default => 'info',
        };
    }

    private function messageForDelivery(PostmarkEmailDelivery $delivery): string
    {
        $statusLabel = str_replace('_', ' ', (string) $delivery->status);
        $subject = trim((string) ($delivery->subject ?? ''));
        $subjectPart = $subject !== '' ? " — {$subject}" : '';

        $detail = trim((string) ($delivery->bounce_description ?? ''));
        if ($detail === '') {
            $detail = trim((string) ($delivery->status_detail ?? ''));
        }
        if ($detail !== '' && ! str_contains(strtolower($detail), 'accepted by')) {
            $detailPart = " ({$detail})";
        } else {
            $detailPart = '';
        }

        $type = trim((string) ($delivery->email_type ?? ''));
        $typePart = $type !== '' ? ' ['.str_replace('_', ' ', $type).']' : '';

        return "To {$delivery->email}{$typePart}: {$statusLabel}{$subjectPart}{$detailPart}";
    }
}
