<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailCampaignBatchJob;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignUnsubscribe;
use App\Services\EngagedAudienceExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EmailCampaignAttendeeController extends Controller
{
    public function importCsv(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->user_id === Auth::id(), 403);

        $missing = $campaign->missingBasicsFields();
        if ($missing !== []) {
            return back()->withErrors([
                'basics' => 'Save campaign basics before importing or sending: '.implode(', ', $missing).'.',
            ]);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
        ]);

        $uploadedFile = $validated['file'];
        $filePath = $uploadedFile->getRealPath();
        if ($filePath === false) {
            return back()->withErrors(['file' => 'Unable to read uploaded file.']);
        }

        $rows = $this->parseRowsFromFile($uploadedFile->getClientOriginalExtension(), $filePath);
        if (count($rows) === 0) {
            return back()->withErrors(['file' => 'Uploaded file appears empty.']);
        }

        $header = array_map(fn ($item) => Str::lower(trim((string) $item)), $rows[0]);
        $hasHeader = in_array('email', $header, true);

        if ($hasHeader) {
            array_shift($rows);
        } else {
            $header = ['email', 'name'];
        }

        $indexMap = [
            'name' => array_search('name', $header, true),
            'email' => array_search('email', $header, true),
        ];

        if ($indexMap['email'] === false) {
            $indexMap['email'] = 0;
        }

        $now = Carbon::now();
        $emailToName = [];
        foreach ($rows as $row) {
            $email = Str::lower(trim((string) ($row[$indexMap['email']] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $name = trim((string) ($indexMap['name'] !== false ? ($row[$indexMap['name']] ?? '') : ''));
            if ($name === '') {
                $name = Str::of($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->value();
            }

            $emailToName[$email] = $name;
        }

        $emails = array_keys($emailToName);
        if ($emails === []) {
            return back()->withErrors(['file' => 'Uploaded file contained no valid emails.']);
        }

        $globallyUnsubscribedEmails = collect();
        foreach (array_chunk($emails, 500) as $emailChunk) {
            $globallyUnsubscribedEmails = $globallyUnsubscribedEmails->merge(
                EmailCampaignRecipient::query()
                    ->whereIn('email', $emailChunk)
                    ->where('is_subscribed', false)
                    ->whereHas('campaign', fn ($q) => $q->where('user_id', $campaign->user_id))
                    ->pluck('email')
                    ->all()
            );
        }

        $globallyUnsubscribedLookup = array_flip(
            $globallyUnsubscribedEmails->unique()->values()->all()
        );

        $existingRecipients = collect();
        foreach (array_chunk($emails, 500) as $emailChunk) {
            $existingRecipients = $existingRecipients->merge(
                EmailCampaignRecipient::query()
                    ->where('campaign_id', $campaign->id)
                    ->whereIn('email', $emailChunk)
                    ->get(['id', 'email', 'access_token'])
            );
        }

        $existingRecipients = $existingRecipients->keyBy('email');

        $upsertRows = [];
        $allowedEmails = [];
        foreach ($emailToName as $email => $name) {
            if (isset($globallyUnsubscribedLookup[$email])) {
                continue;
            }

            $allowedEmails[] = $email;
            $existing = $existingRecipients->get($email);

            $upsertRows[] = [
                'campaign_id' => $campaign->id,
                'email' => $email,
                'name' => $name,
                'access_token' => $existing?->access_token ?: Str::random(40),
                'is_subscribed' => true,
                'imported_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        if ($upsertRows !== []) {
            foreach (array_chunk($upsertRows, 500) as $chunk) {
                EmailCampaignRecipient::query()->upsert(
                    $chunk,
                    ['campaign_id', 'email'],
                    ['name', 'imported_at', 'updated_at', 'access_token', 'is_subscribed']
                );
            }
        }

        $recipientIds = collect();
        if ($allowedEmails !== []) {
            foreach (array_chunk($allowedEmails, 500) as $emailChunk) {
                $recipientIds = $recipientIds->merge(
                    EmailCampaignRecipient::query()
                        ->where('campaign_id', $campaign->id)
                        ->whereIn('email', $emailChunk)
                        ->where('is_subscribed', true)
                        ->pluck('id')
                );
            }
        }

        $imported = count($upsertRows);
        $queued = 0;
        $sendOnImport = (bool) data_get($campaign->settings, 'send_on_import', true);
        $recipientIdList = $recipientIds->unique()->values()->all();

        Log::info('email_campaign.attendees.imported', [
            'campaign_id' => $campaign->id,
            'user_id' => Auth::id(),
            'imported' => $imported,
            'send_on_import' => $sendOnImport,
        ]);

        if ($sendOnImport) {
            $queued = $this->dispatchEmailBatches($campaign, $recipientIdList, 'csv_import');
        }

        return back()->with('success', "Import complete. {$imported} attendee(s) processed, {$queued} email(s) queued.");
    }

    public function moveToUnsubscribed(EmailCampaign $campaign, EmailCampaignRecipient $recipient): RedirectResponse
    {
        abort_unless($campaign->user_id === Auth::id(), 403);
        abort_unless($recipient->campaign_id === $campaign->id, 404);

        $recipient->update([
            'is_subscribed' => false,
        ]);

        EmailCampaignUnsubscribe::updateOrCreate(
            ['recipient_id' => $recipient->id],
            [
                'campaign_id' => $campaign->id,
                'email' => $recipient->email,
                'token' => hash('sha256', $recipient->access_token),
                'unsubscribed_at' => Carbon::now(),
                'reason' => 'manually-moved-by-host',
            ],
        );

        return back()->with('success', 'Attendee moved to unsubscribed list.');
    }

    public function moveManyToUnsubscribed(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->user_id === Auth::id(), 403);

        $validated = $request->validate([
            'attendee_ids' => ['required', 'array', 'min:1'],
            'attendee_ids.*' => ['integer'],
        ]);

        $recipients = EmailCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('id', $validated['attendee_ids'])
            ->where('is_subscribed', true)
            ->get();

        foreach ($recipients as $recipient) {
            $recipient->update([
                'is_subscribed' => false,
            ]);

            EmailCampaignUnsubscribe::updateOrCreate(
                ['recipient_id' => $recipient->id],
                [
                    'campaign_id' => $campaign->id,
                    'email' => $recipient->email,
                    'token' => hash('sha256', $recipient->access_token),
                    'unsubscribed_at' => Carbon::now(),
                    'reason' => 'manually-moved-by-host',
                ],
            );
        }

        return back()->with('success', $recipients->count().' attendee(s) moved to unsubscribed list.');
    }

    public function deleteUnsubscribed(EmailCampaign $campaign, EmailCampaignRecipient $recipient): RedirectResponse
    {
        abort_unless($campaign->user_id === Auth::id(), 403);
        abort_unless($recipient->campaign_id === $campaign->id, 404);
        abort_unless($recipient->is_subscribed === false, 422);

        EmailCampaignUnsubscribe::query()
            ->where('campaign_id', $campaign->id)
            ->where('email', $recipient->email)
            ->delete();

        $recipient->delete();

        return back()->with('success', 'Unsubscribed attendee deleted.');
    }

    public function deleteManyUnsubscribed(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->user_id === Auth::id(), 403);

        $validated = $request->validate([
            'attendee_ids' => ['required', 'array', 'min:1'],
            'attendee_ids.*' => ['integer'],
        ]);

        $recipients = EmailCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('id', $validated['attendee_ids'])
            ->where('is_subscribed', false)
            ->get();

        foreach ($recipients as $recipient) {
            EmailCampaignUnsubscribe::query()
                ->where('campaign_id', $campaign->id)
                ->where('email', $recipient->email)
                ->delete();

            $recipient->delete();
        }

        return back()->with('success', $recipients->count().' unsubscribed attendee(s) deleted.');
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseRowsFromFile(string $extension, string $filePath): array
    {
        $extension = Str::lower($extension);

        if (in_array($extension, ['csv', 'txt'], true)) {
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                return [];
            }

            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = array_map(fn ($item) => trim((string) $item), $row);
            }
            fclose($handle);

            return $rows;
        }

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $sheet = IOFactory::load($filePath)->getSheet(0);
            $rows = $sheet->toArray(null, false, false, false);

            return array_map(
                fn ($row) => array_map(fn ($item) => trim((string) $item), $row),
                $rows,
            );
        }

        return [];
    }

    /**
     * @param array<int, int> $recipientIds
     */
    private function dispatchEmailBatches(EmailCampaign $campaign, array $recipientIds, string $source = 'csv_import'): int
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

    public function exportClicked(EmailCampaign $campaign, EngagedAudienceExportService $exportService): StreamedResponse
    {
        abort_unless($campaign->user_id === Auth::id(), 403);

        return $exportService->exportCampaignClickedRecipients($campaign);
    }
}
