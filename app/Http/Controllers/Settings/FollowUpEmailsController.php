<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\FollowUpEmailsUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FollowUpEmailsController extends Controller
{
    private const SEGMENT_KEYS = ['below_50', 'above_50', 'completed_no_click'];

    private function normalizeFollowUpBody(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^(?:\s*<p>\s*(?:<br\s*\/?>\s*)?<\/p>\s*)+$/i', $trimmed)) {
            return '';
        }

        if (preg_match('/^(?:\s*<p>\s*<\/p>\s*)+$/i', $trimmed)) {
            return '';
        }

        return $trimmed;
    }

    public function edit(): Response
    {
        $user = auth()->user();
        $stored = is_array($user?->follow_up_segment_emails) ? $user->follow_up_segment_emails : [];

        $segments = [];
        foreach (self::SEGMENT_KEYS as $key) {
            $row = data_get($stored, $key, []);
            $segments[$key] = [
                'enabled' => (bool) data_get($row, 'enabled', true),
                'subject' => (string) data_get($row, 'subject', ''),
                'body' => $this->normalizeFollowUpBody((string) data_get($row, 'body', '')),
            ];
        }

        return Inertia::render('settings/FollowUpEmails', [
            'segments' => $segments,
            'status' => session('status'),
        ]);
    }

    public function update(FollowUpEmailsUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $payload = [];

        foreach (self::SEGMENT_KEYS as $key) {
            $row = data_get($validated, "segments.{$key}", []);
            $payload[$key] = [
                'enabled' => (bool) data_get($row, 'enabled', true),
                'subject' => trim((string) data_get($row, 'subject', '')),
                'body' => $this->normalizeFollowUpBody((string) data_get($row, 'body', '')),
            ];
        }

        $request->user()?->update([
            'follow_up_segment_emails' => $payload,
        ]);

        return back()->with('status', 'follow-up-emails-updated');
    }
}
