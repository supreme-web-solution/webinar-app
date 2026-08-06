<?php

namespace App\Http\Controllers\Admin;

use App\Services\EmailRichTextFormatter;
use App\Http\Requests\EmailCampaign\StoreEmailCampaignRequest;
use App\Http\Requests\EmailCampaign\UpdateEmailCampaignRequest;
use App\Jobs\SendEmailCampaignBatchJob;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignUnsubscribe;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class EmailCampaignController extends Controller
{
    public function index(): Response
    {
        $campaigns = EmailCampaign::query()
            ->where('user_id', Auth::id())
            ->withCount([
                'recipients',
                'clicks',
                'recipients as clicked_recipients_count' => fn ($query) => $query->where('click_count', '>', 0),
            ])
            ->latest()
            ->paginate(10)
            ->withQueryString()
            ->through(fn (EmailCampaign $campaign) => [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'title_prefix' => $campaign->title_prefix,
                'sender_name' => $campaign->sender_name,
                'cta_label' => $campaign->cta_label,
                'recipients_count' => $campaign->recipients_count,
                'clicks_count' => $campaign->clicks_count,
                'clicked_recipients_count' => $campaign->clicked_recipients_count,
                'updated_at' => $campaign->updated_at?->toDateTimeString(),
            ]);

        return Inertia::render('emails/Index', [
            'campaigns' => $campaigns,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('emails/Create', [
            'defaults' => [
                'title_prefix' => '[Campaign]',
                'title' => '',
                'sender_name' => (string) Auth::user()?->name,
                'body' => '',
                'cta_label' => 'Open Link',
                'cta_url' => '',
                'settings' => [
                    'send_on_import' => true,
                ],
            ],
            'attendees' => [
                'subscribed_total' => 0,
                'subscribed' => [],
                'unsubscribed_total' => 0,
                'unsubscribed' => [],
            ],
            'attendeeImportUrl' => null,
            'attendeeActionUrls' => null,
            'sendUrl' => null,
            'stats' => [
                'recipients' => 0,
                'sent_recipients' => 0,
                'clicks' => 0,
            ],
        ]);
    }

    public function store(StoreEmailCampaignRequest $request): RedirectResponse
    {
        $data = $this->normalizePayload($request->validated());
        $data['user_id'] = Auth::id();

        $campaign = EmailCampaign::create($data);

        return redirect()
            ->route('admin.emails.edit', ['campaign' => $campaign->id])
            ->with('success', 'Email campaign created. Continue with attendee import.');
    }

    public function edit(EmailCampaign $campaign): Response
    {
        abort_unless($campaign->user_id === Auth::id(), 403);

        $attendeesPreviewLimit = 200;
        $subscribedTotal = $campaign->recipients()->where('is_subscribed', true)->count();
        $unsubscribedTotal = $campaign->recipients()->where('is_subscribed', false)->count();

        return Inertia::render('emails/Edit', [
            'campaign' => [
                'id' => $campaign->id,
                'title_prefix' => $campaign->title_prefix ?: '[Campaign]',
                'title' => $campaign->title,
                'sender_name' => $campaign->sender_name,
                'body' => $campaign->body ?? '',
                'cta_label' => $campaign->cta_label ?: 'Open Link',
                'cta_url' => $campaign->cta_url,
                'settings' => array_merge([
                    'send_on_import' => true,
                ], is_array($campaign->settings) ? $campaign->settings : []),
            ],
            'attendees' => [
                'subscribed_total' => $subscribedTotal,
                'subscribed' => $campaign->recipients()
                    ->where('is_subscribed', true)
                    ->orderByDesc('imported_at')
                    ->limit($attendeesPreviewLimit)
                    ->get()
                    ->map(fn ($recipient) => [
                        'id' => $recipient->id,
                        'name' => $recipient->name,
                        'email' => $recipient->email,
                        'imported_at' => $recipient->imported_at?->toDateTimeString(),
                        'send_count' => $recipient->send_count,
                        'click_count' => $recipient->click_count,
                        'last_clicked_at' => $recipient->last_clicked_at?->toDateTimeString(),
                        'unsubscribe_url' => route('admin.emails.attendees.unsubscribe', [
                            'campaign' => $campaign->id,
                            'recipient' => $recipient->id,
                        ]),
                    ]),
                'unsubscribed_total' => $unsubscribedTotal,
                'unsubscribed' => $campaign->recipients()
                    ->with('unsubscribeLog')
                    ->where('is_subscribed', false)
                    ->orderByDesc('updated_at')
                    ->limit($attendeesPreviewLimit)
                    ->get()
                    ->map(fn ($recipient) => [
                        'id' => $recipient->id,
                        'name' => $recipient->name,
                        'email' => $recipient->email,
                        'unsubscribed_at' => $recipient->unsubscribeLog?->unsubscribed_at?->toDateTimeString(),
                        'delete_url' => route('admin.emails.attendees.delete', [
                            'campaign' => $campaign->id,
                            'recipient' => $recipient->id,
                        ]),
                    ]),
            ],
            'attendeeImportUrl' => route('admin.emails.attendees.import', ['campaign' => $campaign->id]),
            'attendeeActionUrls' => [
                'bulk_unsubscribe_url' => route('admin.emails.attendees.unsubscribe.bulk', ['campaign' => $campaign->id]),
                'bulk_delete_url' => route('admin.emails.attendees.delete.bulk', ['campaign' => $campaign->id]),
            ],
            'sendUrl' => route('admin.emails.send', ['campaign' => $campaign->id]),
            'stats' => [
                'recipients' => $campaign->recipients()->count(),
                'sent_recipients' => $campaign->recipients()->where('send_count', '>', 0)->count(),
                'clicks' => $campaign->clicks()->count(),
            ],
            'basics_ready' => $campaign->isReadyToSend(),
            'missing_basics' => $campaign->missingBasicsFields(),
        ]);
    }

    public function update(UpdateEmailCampaignRequest $request, EmailCampaign $campaign): RedirectResponse
    {
        $campaign->update($this->normalizePayload($request->validated()));

        return back()->with('success', 'Email campaign updated successfully.');
    }

    public function send(EmailCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->user_id === Auth::id(), 403);

        if ($redirect = $this->redirectIfBasicsNotReady($campaign)) {
            return $redirect;
        }

        $recipientIds = $campaign->recipients()
            ->where('is_subscribed', true)
            ->pluck('id');
        if ($recipientIds->isEmpty()) {
            return back()->withErrors([
                'attendees' => 'Import attendees first before sending.',
            ]);
        }

        $recipientIdList = $recipientIds->values()->all();

        Log::info('email_campaign.send.requested', [
            'campaign_id' => $campaign->id,
            'user_id' => Auth::id(),
            'subject' => $campaign->prefixedTitleLine(),
            'recipient_count' => count($recipientIdList),
            'source' => 'manual_send',
        ]);

        $queued = $this->dispatchEmailBatches($campaign, $recipientIdList, 'manual_send');

        return back()->with('success', "Campaign send queued for {$queued} recipient(s).");
    }

    public function destroy(EmailCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->user_id === Auth::id(), 403);

        DB::transaction(function () use ($campaign): void {
            EmailCampaignUnsubscribe::query()
                ->where('campaign_id', $campaign->id)
                ->delete();

            $campaign->delete();
        });

        return redirect()
            ->route('admin.emails.index')
            ->with('success', 'Email campaign deleted.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data): array
    {
        $prefix = trim((string) ($data['title_prefix'] ?? ''));
        $data['title_prefix'] = $prefix !== '' ? $prefix : '[Campaign]';

        $ctaLabel = trim((string) ($data['cta_label'] ?? ''));
        $data['cta_label'] = $ctaLabel !== '' ? $ctaLabel : 'Open Link';

        $senderName = trim(strip_tags((string) ($data['sender_name'] ?? '')));
        $data['sender_name'] = $senderName !== '' ? $senderName : (string) Auth::user()?->name;

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $data['settings'] = [
            'send_on_import' => (bool) ($settings['send_on_import'] ?? true),
        ];

        $data['body'] = $this->sanitizeBody((string) ($data['body'] ?? ''));

        return $data;
    }

    private function sanitizeBody(string $body): string
    {
        return app(EmailRichTextFormatter::class)->sanitizeForStorage($body);
    }

    private function redirectIfBasicsNotReady(EmailCampaign $campaign): ?RedirectResponse
    {
        $missing = $campaign->missingBasicsFields();
        if ($missing === []) {
            return null;
        }

        return back()->withErrors([
            'basics' => 'Save campaign basics before importing or sending: '.implode(', ', $missing).'.',
        ]);
    }

    /**
     * @param array<int, int> $recipientIds
     */
    private function dispatchEmailBatches(EmailCampaign $campaign, array $recipientIds, string $source = 'manual_send'): int
    {
        $batchSize = max(1, (int) env('WEBINAR_EMAIL_BATCH_SIZE', 100));
        $baseDelaySeconds = max(0, (int) env('WEBINAR_EMAIL_BATCH_DELAY_BASE_SECONDS', 0));
        $delayIncrementSeconds = max(0, (int) env('WEBINAR_EMAIL_BATCH_DELAY_INCREMENT_SECONDS', 5));
        $emailQueue = (string) config('services.queues.emails', 'emails');

        $chunks = collect($recipientIds)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->chunk($batchSize);

        Log::info('email_campaign_batch.dispatched', [
            'campaign_id' => $campaign->id,
            'subject' => $campaign->prefixedTitleLine(),
            'source' => $source,
            'recipient_count' => $chunks->sum(fn ($chunk) => $chunk->count()),
            'batch_count' => $chunks->count(),
            'batch_size' => $batchSize,
            'queue' => $emailQueue,
        ]);

        foreach ($chunks as $index => $chunk) {
            $delaySeconds = $baseDelaySeconds + ((int) $index * $delayIncrementSeconds);

            SendEmailCampaignBatchJob::dispatch(
                campaignId: (int) $campaign->id,
                recipientIds: $chunk->all(),
            )
                ->onQueue($emailQueue)
                ->delay(now()->addSeconds($delaySeconds));

            Log::info('email_campaign_batch.job_queued', [
                'campaign_id' => $campaign->id,
                'source' => $source,
                'batch_index' => $index,
                'batch_recipient_count' => $chunk->count(),
                'delay_seconds' => $delaySeconds,
                'queue' => $emailQueue,
            ]);
        }

        return $chunks->sum(fn ($chunk) => $chunk->count());
    }
}
