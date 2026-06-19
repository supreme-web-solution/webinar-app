<?php

namespace App\Services;

use App\Models\PostmarkEmailDelivery;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PostmarkDeliveryTrackingService
{
    public function __construct(
        private readonly EmailSuppressionService $suppressionService,
    ) {
    }

    /**
     * @param  array<int, array{
     *     message_id: string,
     *     email: string,
     *     user_id: int,
     *     source_type: string,
     *     webinar_id?: int|null,
     *     registrant_id?: int|null,
     *     campaign_id?: int|null,
     *     recipient_id?: int|null,
     *     email_type?: string|null,
     *     subject?: string|null,
     * }>  $records
     */
    public function recordAccepted(array $records): void
    {
        if ($records === []) {
            return;
        }

        $now = Carbon::now();
        $rows = [];

        foreach ($records as $record) {
            $messageId = trim((string) ($record['message_id'] ?? ''));
            if ($messageId === '') {
                continue;
            }

            $rows[] = [
                'user_id' => $record['user_id'],
                'postmark_message_id' => $messageId,
                'email' => $record['email'],
                'status' => PostmarkEmailDelivery::STATUS_ACCEPTED,
                'source_type' => $record['source_type'],
                'webinar_id' => $record['webinar_id'] ?? null,
                'registrant_id' => $record['registrant_id'] ?? null,
                'campaign_id' => $record['campaign_id'] ?? null,
                'recipient_id' => $record['recipient_id'] ?? null,
                'email_type' => $record['email_type'] ?? null,
                'subject' => $record['subject'] ?? null,
                'accepted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        PostmarkEmailDelivery::query()->upsert(
            $rows,
            ['postmark_message_id'],
            ['status', 'accepted_at', 'updated_at']
        );
    }

    /**
     * @param  array<int, array{
     *     email: string,
     *     user_id: int,
     *     source_type: string,
     *     webinar_id?: int|null,
     *     registrant_id?: int|null,
     *     campaign_id?: int|null,
     *     recipient_id?: int|null,
     *     email_type?: string|null,
     *     subject?: string|null,
     * }>  $records
     */
    public function recordSuppressedAtApi(array $records): void
    {
        if ($records === []) {
            return;
        }

        $now = Carbon::now();

        foreach ($records as $record) {
            PostmarkEmailDelivery::query()->create([
                'user_id' => $record['user_id'],
                'postmark_message_id' => null,
                'email' => $record['email'],
                'status' => PostmarkEmailDelivery::STATUS_SUPPRESSED,
                'source_type' => $record['source_type'],
                'webinar_id' => $record['webinar_id'] ?? null,
                'registrant_id' => $record['registrant_id'] ?? null,
                'campaign_id' => $record['campaign_id'] ?? null,
                'recipient_id' => $record['recipient_id'] ?? null,
                'email_type' => $record['email_type'] ?? null,
                'subject' => $record['subject'] ?? null,
                'accepted_at' => $now,
            ]);
        }
    }

    public function handleWebhook(array $payload): void
    {
        $recordType = (string) data_get($payload, 'RecordType', '');

        match ($recordType) {
            'Delivery' => $this->handleDelivery($payload),
            'Bounce' => $this->handleBounce($payload),
            'SpamComplaint' => $this->handleSpamComplaint($payload),
            default => Log::debug('postmark.webhook.ignored', ['record_type' => $recordType]),
        };
    }

    /**
     * @return array{
     *     accepted: int,
     *     delivered: int,
     *     bounced: int,
     *     spam_complaint: int,
     *     suppressed: int,
     *     pending: int,
     *     delivery_rate: float|null,
     * }
     */
    public function statsForUser(int $userId, int $days = 30): array
    {
        $since = Carbon::now()->subDays($days);

        $counts = PostmarkEmailDelivery::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $accepted = (int) ($counts[PostmarkEmailDelivery::STATUS_ACCEPTED] ?? 0);
        $delivered = (int) ($counts[PostmarkEmailDelivery::STATUS_DELIVERED] ?? 0);
        $bounced = (int) ($counts[PostmarkEmailDelivery::STATUS_BOUNCED] ?? 0);
        $spamComplaint = (int) ($counts[PostmarkEmailDelivery::STATUS_SPAM_COMPLAINT] ?? 0);
        $suppressed = (int) ($counts[PostmarkEmailDelivery::STATUS_SUPPRESSED] ?? 0);
        $pending = $accepted;

        $resolved = $delivered + $bounced;
        $deliveryRate = $resolved > 0 ? round(($delivered / $resolved) * 100, 1) : null;

        return [
            'accepted' => $accepted,
            'delivered' => $delivered,
            'bounced' => $bounced,
            'spam_complaint' => $spamComplaint,
            'suppressed' => $suppressed,
            'pending' => $pending,
            'delivery_rate' => $deliveryRate,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function statsByEmailType(int $userId, int $days = 30): array
    {
        $since = Carbon::now()->subDays($days);

        return PostmarkEmailDelivery::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->whereNotNull('email_type')
            ->selectRaw('email_type, COUNT(*) as total')
            ->groupBy('email_type')
            ->orderByDesc('total')
            ->pluck('total', 'email_type')
            ->all();
    }

    private function handleDelivery(array $payload): void
    {
        $messageId = (string) data_get($payload, 'MessageID', '');
        if ($messageId === '') {
            return;
        }

        $delivery = PostmarkEmailDelivery::query()
            ->where('postmark_message_id', $messageId)
            ->first();

        if (! $delivery) {
            $delivery = $this->createFromWebhookMetadata($payload, PostmarkEmailDelivery::STATUS_DELIVERED);
            if (! $delivery) {
                Log::info('postmark.webhook.delivery.orphan', ['message_id' => $messageId]);

                return;
            }
        }

        $deliveredAt = $this->parsePostmarkTimestamp(data_get($payload, 'DeliveredAt'));

        $delivery->update([
            'status' => PostmarkEmailDelivery::STATUS_DELIVERED,
            'delivered_at' => $deliveredAt,
            'email' => (string) (data_get($payload, 'Recipient') ?: $delivery->email),
        ]);

        Log::info('postmark.webhook.delivery.recorded', [
            'message_id' => $messageId,
            'delivery_id' => $delivery->id,
        ]);
    }

    private function handleBounce(array $payload): void
    {
        $messageId = (string) data_get($payload, 'MessageID', '');
        if ($messageId === '') {
            return;
        }

        $bounceType = (string) data_get($payload, 'Type', '');
        $description = (string) data_get($payload, 'Description', '');
        $bouncedAt = $this->parsePostmarkTimestamp(data_get($payload, 'BouncedAt'));

        $delivery = PostmarkEmailDelivery::query()
            ->where('postmark_message_id', $messageId)
            ->first();

        if (! $delivery) {
            $delivery = $this->createFromWebhookMetadata($payload, PostmarkEmailDelivery::STATUS_BOUNCED);
            if (! $delivery) {
                Log::info('postmark.webhook.bounce.orphan', ['message_id' => $messageId]);

                return;
            }
        }

        $delivery->update([
            'status' => PostmarkEmailDelivery::STATUS_BOUNCED,
            'bounced_at' => $bouncedAt,
            'bounce_type' => $bounceType !== '' ? $bounceType : null,
            'bounce_description' => $description !== '' ? $description : null,
            'email' => (string) (data_get($payload, 'Email') ?: data_get($payload, 'Recipient') ?: $delivery->email),
        ]);

        if (strcasecmp($bounceType, 'HardBounce') === 0) {
            $this->suppressFromDelivery($delivery);
        }

        Log::info('postmark.webhook.bounce.recorded', [
            'message_id' => $messageId,
            'bounce_type' => $bounceType,
            'delivery_id' => $delivery->id,
        ]);
    }

    private function handleSpamComplaint(array $payload): void
    {
        $messageId = (string) data_get($payload, 'MessageID', '');
        if ($messageId === '') {
            return;
        }

        $delivery = PostmarkEmailDelivery::query()
            ->where('postmark_message_id', $messageId)
            ->first();

        if (! $delivery) {
            $delivery = $this->createFromWebhookMetadata($payload, PostmarkEmailDelivery::STATUS_SPAM_COMPLAINT);
            if (! $delivery) {
                Log::info('postmark.webhook.spam_complaint.orphan', ['message_id' => $messageId]);

                return;
            }
        }

        $delivery->update([
            'status' => PostmarkEmailDelivery::STATUS_SPAM_COMPLAINT,
            'email' => (string) (data_get($payload, 'Email') ?: $delivery->email),
        ]);

        $this->suppressFromDelivery($delivery);

        Log::info('postmark.webhook.spam_complaint.recorded', [
            'message_id' => $messageId,
            'delivery_id' => $delivery->id,
        ]);
    }

    private function createFromWebhookMetadata(array $payload, string $status): ?PostmarkEmailDelivery
    {
        $metadata = data_get($payload, 'Metadata', []);
        if (! is_array($metadata)) {
            return null;
        }

        $source = (string) ($metadata['source'] ?? '');
        $userId = $this->resolveUserIdFromMetadata($metadata, $source);
        if ($userId === null) {
            return null;
        }

        $messageId = (string) data_get($payload, 'MessageID', '');
        $email = (string) (data_get($payload, 'Recipient') ?: data_get($payload, 'Email') ?: '');
        $now = Carbon::now();

        return PostmarkEmailDelivery::query()->create([
            'user_id' => $userId,
            'postmark_message_id' => $messageId !== '' ? $messageId : null,
            'email' => $email,
            'status' => $status,
            'source_type' => $source === 'campaign' ? 'campaign_recipient' : 'webinar_registrant',
            'webinar_id' => isset($metadata['webinar_id']) ? (int) $metadata['webinar_id'] : null,
            'registrant_id' => isset($metadata['registrant_id']) ? (int) $metadata['registrant_id'] : null,
            'campaign_id' => isset($metadata['campaign_id']) ? (int) $metadata['campaign_id'] : null,
            'recipient_id' => isset($metadata['recipient_id']) ? (int) $metadata['recipient_id'] : null,
            'email_type' => isset($metadata['email_type']) ? (string) $metadata['email_type'] : null,
            'accepted_at' => $now,
            'delivered_at' => $status === PostmarkEmailDelivery::STATUS_DELIVERED
                ? $this->parsePostmarkTimestamp(data_get($payload, 'DeliveredAt'))
                : null,
            'bounced_at' => $status === PostmarkEmailDelivery::STATUS_BOUNCED
                ? $this->parsePostmarkTimestamp(data_get($payload, 'BouncedAt'))
                : null,
        ]);
    }

  /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveUserIdFromMetadata(array $metadata, string $source): ?int
    {
        if ($source === 'webinar' && isset($metadata['webinar_id'])) {
            return Webinar::query()->whereKey((int) $metadata['webinar_id'])->value('user_id');
        }

        if ($source === 'campaign' && isset($metadata['campaign_id'])) {
            return \App\Models\EmailCampaign::query()->whereKey((int) $metadata['campaign_id'])->value('user_id');
        }

        if (isset($metadata['registrant_id'])) {
            return WebinarRegistrant::query()
                ->whereKey((int) $metadata['registrant_id'])
                ->with('webinar:id,user_id')
                ->first()
                ?->webinar
                ?->user_id;
        }

        return null;
    }

    private function suppressFromDelivery(PostmarkEmailDelivery $delivery): void
    {
        if ($delivery->registrant_id !== null) {
            $this->suppressionService->suppressWebinarRegistrants([$delivery->registrant_id]);

            return;
        }

        if ($delivery->recipient_id !== null) {
            $this->suppressionService->suppressCampaignRecipients([$delivery->recipient_id]);
        }
    }

    private function parsePostmarkTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return Carbon::now();
        }
    }
}
