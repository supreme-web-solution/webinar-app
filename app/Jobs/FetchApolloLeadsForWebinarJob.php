<?php

namespace App\Jobs;

use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use App\Services\Apollo\ApolloLeadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FetchApolloLeadsForWebinarJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array{job_title?: string, industry?: string, location?: string, company_size?: string, keyword?: string} $filters
     */
    public function __construct(
        private readonly int $webinarId,
        private readonly int $userId,
        private readonly int $requestedCount,
        private readonly array $filters,
    ) {
        $this->onQueue((string) config('services.queues.apollo_fetch', 'apollo-fetch'));
    }

    public function handle(ApolloLeadService $apolloLeadService): void
    {
        $webinar = Webinar::query()->find($this->webinarId);
        if (! $webinar || (int) $webinar->user_id !== $this->userId) {
            return;
        }

        $configuredMax = max(1, (int) config('services.apollo.max_fetch', 250));
        $fetchCount = min($this->requestedCount, $configuredMax);

        try {
            $contacts = $apolloLeadService->searchContacts($this->filters, $fetchCount);
        } catch (\Throwable $exception) {
            Log::warning('apollo.fetch.job.failed', [
                'webinar_id' => $this->webinarId,
                'requested_count' => $this->requestedCount,
                'effective_fetch_count' => $fetchCount,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        if ($contacts === []) {
            Log::info('apollo.fetch.job.no_contacts', [
                'webinar_id' => $this->webinarId,
                'requested' => $this->requestedCount,
                'configured_max' => $configuredMax,
            ]);

            return;
        }

        $emailToName = [];
        foreach ($contacts as $contact) {
            $email = Str::lower(trim((string) ($contact['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $name = trim((string) ($contact['name'] ?? ''));
            if ($name === '') {
                $name = Str::of($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->value();
            }

            $emailToName[$email] = $name;
        }

        if ($emailToName === []) {
            return;
        }

        $emails = array_keys($emailToName);
        $now = Carbon::now();
        $sendConfirmation = (bool) data_get($webinar->email_settings, 'send_confirmation', true);

        $globallyUnsubscribedEmails = collect();
        foreach (array_chunk($emails, 500) as $emailChunk) {
            $globallyUnsubscribedEmails = $globallyUnsubscribedEmails->merge(
                WebinarRegistrant::query()
                    ->whereIn('email', $emailChunk)
                    ->where('is_subscribed', false)
                    ->whereHas('webinar', fn ($q) => $q->where('webinars.user_id', $webinar->user_id))
                    ->pluck('email')
                    ->all()
            );
        }

        $globallyUnsubscribedLookup = array_flip(
            $globallyUnsubscribedEmails
                ->unique()
                ->values()
                ->all()
        );

        $existingRegistrants = collect();
        foreach (array_chunk($emails, 500) as $emailChunk) {
            $existingRegistrants = $existingRegistrants->merge(
                WebinarRegistrant::query()
                    ->where('webinar_id', $webinar->id)
                    ->whereIn('email', $emailChunk)
                    ->get(['email', 'access_token', 'registered_at'])
            );
        }
        $existingRegistrants = $existingRegistrants->keyBy('email');

        $upsertRows = [];
        $allowedEmails = [];
        foreach ($emailToName as $email => $name) {
            if (isset($globallyUnsubscribedLookup[$email])) {
                continue;
            }

            $allowedEmails[] = $email;
            $existing = $existingRegistrants->get($email);

            $upsertRows[] = [
                'webinar_id' => $webinar->id,
                'email' => $email,
                'name' => $name,
                'registered_at' => $existing?->registered_at ?? $now,
                'is_subscribed' => true,
                'access_token' => $existing?->access_token ?: Str::random(40),
            ];
        }

        if ($upsertRows !== []) {
            foreach (array_chunk($upsertRows, 500) as $chunk) {
                WebinarRegistrant::query()->upsert(
                    $chunk,
                    ['webinar_id', 'email'],
                    ['name', 'registered_at', 'is_subscribed', 'access_token'],
                );
            }
        }

        if (! $sendConfirmation || $allowedEmails === []) {
            return;
        }

        $allowedRegistrantIds = collect();
        foreach (array_chunk($allowedEmails, 500) as $emailChunk) {
            $allowedRegistrantIds = $allowedRegistrantIds->merge(
                WebinarRegistrant::query()
                    ->where('webinar_id', $webinar->id)
                    ->whereIn('email', $emailChunk)
                    ->pluck('id')
            );
        }

        $batchSize = max(1, (int) env('WEBINAR_EMAIL_BATCH_SIZE', 100));
        $baseDelaySeconds = max(0, (int) env('WEBINAR_EMAIL_BATCH_DELAY_BASE_SECONDS', 0));
        $delayIncrementSeconds = max(0, (int) env('WEBINAR_EMAIL_BATCH_DELAY_INCREMENT_SECONDS', 5));
        $emailQueue = (string) config('services.queues.emails', 'emails');

        $chunks = $allowedRegistrantIds
            ->unique()
            ->values()
            ->chunk($batchSize);

        foreach ($chunks as $index => $chunk) {
            $delaySeconds = $baseDelaySeconds + ((int) $index * $delayIncrementSeconds);
            SendWebinarEmailsBatchJob::dispatch(
                $webinar->id,
                $chunk->all(),
                $webinar->prefixedTitleLine(),
                'You have been registered for this webinar. Click below to join the webinar.',
            )
                ->onQueue($emailQueue)
                ->delay(now()->addSeconds($delaySeconds));
        }

        Log::info('apollo.fetch.job.completed', [
            'webinar_id' => $webinar->id,
            'requested_count' => $this->requestedCount,
            'effective_fetch_count' => $fetchCount,
            'registered' => count($upsertRows),
            'queued_email_recipients' => $allowedRegistrantIds->unique()->count(),
            'apollo_queue' => (string) config('services.queues.apollo_fetch', 'apollo-fetch'),
            'email_queue' => $emailQueue,
        ]);
    }
}
