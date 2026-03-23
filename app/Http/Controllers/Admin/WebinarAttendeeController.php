<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\SendWebinarEmailsBatchJob;
use App\Http\Controllers\Controller;
use App\Models\EmailUnsubscribe;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

class WebinarAttendeeController extends Controller
{
    public function importCsv(Request $request, Webinar $webinar): RedirectResponse
    {
        abort_unless($webinar->user_id === Auth::id(), 403);

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

        $firstRow = $rows[0];
        $header = array_map(fn ($item) => Str::lower(trim((string) $item)), $firstRow);
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
            // Support files where first column is email only (no header).
            $indexMap['email'] = 0;
        }

        $imported = 0;
        $registrantIdsForEmail = [];
        $sendConfirmation = (bool) data_get($webinar->email_settings, 'send_confirmation', true);

        foreach ($rows as $row) {
            $email = trim((string) ($row[$indexMap['email']] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $name = trim((string) ($indexMap['name'] !== false ? ($row[$indexMap['name']] ?? '') : ''));
            if ($name === '') {
                $name = Str::of($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->value();
            }

            $registrant = WebinarRegistrant::firstOrNew([
                'webinar_id' => $webinar->id,
                'email' => $email,
            ]);

            $registrant->name = $name;
            $registrant->registered_at = $registrant->registered_at ?? Carbon::now();
            $registrant->is_subscribed = true;
            $registrant->access_token = $registrant->access_token ?: Str::random(40);
            $registrant->save();

            if ($sendConfirmation) {
                $registrantIdsForEmail[] = $registrant->id;
            }

            $imported++;
        }

        $emailsQueued = $sendConfirmation
            ? $this->dispatchEmailBatches(
                $webinar,
                collect($registrantIdsForEmail)->unique()->values(),
                $webinar->prefixedTitleLine(),
                'You have been registered for this webinar. Click below to join when ready.'
            )
            : 0;

        return back()->with('success', "File import complete. {$imported} attendee(s) registered, {$emailsQueued} email(s) queued.");
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

    public function notifyAll(Webinar $webinar): RedirectResponse
    {
        abort_unless($webinar->user_id === Auth::id(), 403);

        if (! (bool) data_get($webinar->email_settings, 'send_reminder', true)) {
            return back()->with('success', 'Reminder emails are disabled in Reminder/Notification settings.');
        }

        $registrants = $webinar->registrants()
            ->where('is_subscribed', true)
            ->get();

        $emailsQueued = $this->dispatchEmailBatches(
            $webinar,
            $registrants->pluck('id')->values(),
            'Reminder: '.$webinar->prefixedTitleLine(),
            'This is a reminder that your webinar is ready. Click below to join now.',
            'reminder_sent_at'
        );

        return back()->with('success', "Reminder run queued. {$emailsQueued}/{$registrants->count()} email(s) queued.");
    }

    public function moveToUnsubscribed(Webinar $webinar, WebinarRegistrant $registrant): RedirectResponse
    {
        abort_unless($webinar->user_id === Auth::id(), 403);
        abort_unless($registrant->webinar_id === $webinar->id, 404);

        $registrant->update([
            'is_subscribed' => false,
        ]);

        EmailUnsubscribe::updateOrCreate(
            ['registrant_id' => $registrant->id],
            [
                'webinar_id' => $webinar->id,
                'email' => $registrant->email,
                'token' => hash('sha256', $registrant->access_token),
                'unsubscribed_at' => Carbon::now(),
                'reason' => 'manually-moved-by-host',
            ],
        );

        return back()->with('success', 'Attendee moved to unsubscribed list.');
    }

    public function moveManyToUnsubscribed(Request $request, Webinar $webinar): RedirectResponse
    {
        abort_unless($webinar->user_id === Auth::id(), 403);

        $validated = $request->validate([
            'attendee_ids' => ['required', 'array', 'min:1'],
            'attendee_ids.*' => ['integer'],
        ]);

        $registrants = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->whereIn('id', $validated['attendee_ids'])
            ->where('is_subscribed', true)
            ->get();

        foreach ($registrants as $registrant) {
            $registrant->update([
                'is_subscribed' => false,
            ]);

            EmailUnsubscribe::updateOrCreate(
                ['registrant_id' => $registrant->id],
                [
                    'webinar_id' => $webinar->id,
                    'email' => $registrant->email,
                    'token' => hash('sha256', $registrant->access_token),
                    'unsubscribed_at' => Carbon::now(),
                    'reason' => 'manually-moved-by-host',
                ],
            );
        }

        return back()->with('success', $registrants->count().' attendee(s) moved to unsubscribed list.');
    }

    public function deleteUnsubscribed(Webinar $webinar, WebinarRegistrant $registrant): RedirectResponse
    {
        abort_unless($webinar->user_id === Auth::id(), 403);
        abort_unless($registrant->webinar_id === $webinar->id, 404);
        abort_unless($registrant->is_subscribed === false, 422);

        EmailUnsubscribe::query()
            ->where('webinar_id', $webinar->id)
            ->where('email', $registrant->email)
            ->delete();

        $registrant->delete();

        return back()->with('success', 'Unsubscribed attendee deleted.');
    }

    public function deleteManyUnsubscribed(Request $request, Webinar $webinar): RedirectResponse
    {
        abort_unless($webinar->user_id === Auth::id(), 403);

        $validated = $request->validate([
            'attendee_ids' => ['required', 'array', 'min:1'],
            'attendee_ids.*' => ['integer'],
        ]);

        $registrants = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->whereIn('id', $validated['attendee_ids'])
            ->where('is_subscribed', false)
            ->get();

        foreach ($registrants as $registrant) {
            EmailUnsubscribe::query()
                ->where('webinar_id', $webinar->id)
                ->where('email', $registrant->email)
                ->delete();

            $registrant->delete();
        }

        return back()->with('success', $registrants->count().' unsubscribed attendee(s) deleted.');
    }

    /**
     * @param Collection<int, int> $registrantIds
     */
    private function dispatchEmailBatches(
        Webinar $webinar,
        Collection $registrantIds,
        string $subject,
        string $intro,
        ?string $markSentColumn = null
    ): int {
        $chunks = $registrantIds
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->chunk(100);

        foreach ($chunks as $index => $chunk) {
            SendWebinarEmailsBatchJob::dispatch(
                $webinar->id,
                $chunk->all(),
                $subject,
                $intro,
                $markSentColumn
            )
                ->onQueue('emails')
                ->delay(now()->addSeconds((int) $index * 5));
        }

        return $chunks->sum(fn ($chunk) => $chunk->count());
    }
}
