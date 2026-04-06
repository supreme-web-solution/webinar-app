<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SmtpSettingsUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SmtpController extends Controller
{
    /**
     * Show the user's SMTP settings page.
     */
    public function edit(): Response
    {
        $user = auth()->user();

        return Inertia::render('settings/Smtp', [
            'smtp' => [
                'smtp_enabled' => (bool) $user?->smtp_enabled,
                'smtp_host' => (string) ($user?->smtp_host ?? ''),
                'smtp_port' => $user?->smtp_port,
                'smtp_encryption' => (string) ($user?->smtp_encryption ?? 'tls'),
                'smtp_username' => (string) ($user?->smtp_username ?? ''),
                'smtp_from_address' => (string) ($user?->smtp_from_address ?? ''),
                'smtp_from_name' => (string) ($user?->smtp_from_name ?? ''),
                'has_password' => !empty($user?->smtp_password),
                'test_email' => (string) ($user?->email ?? ''),
            ],
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's SMTP settings.
     */
    public function update(SmtpSettingsUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $enabled = (bool) ($validated['smtp_enabled'] ?? false);

        $validationErrors = $this->validateRequiredSmtpFields($validated, $enabled, !empty($user?->smtp_password));
        if ($validationErrors !== []) {
            return back()->withErrors($validationErrors);
        }

        $payload = [
            'smtp_enabled' => $enabled,
            'smtp_host' => $validated['smtp_host'] ?? null,
            'smtp_port' => $validated['smtp_port'] ?? null,
            'smtp_encryption' => ($validated['smtp_encryption'] ?? null) === 'none' ? null : ($validated['smtp_encryption'] ?? null),
            'smtp_username' => $validated['smtp_username'] ?? null,
            'smtp_from_address' => $validated['smtp_from_address'] ?? null,
            'smtp_from_name' => $validated['smtp_from_name'] ?? null,
        ];

        $incomingPassword = (string) ($validated['smtp_password'] ?? '');
        if ($incomingPassword !== '') {
            $payload['smtp_password'] = $incomingPassword;
        }

        $user?->update($payload);

        return back()->with('status', 'smtp-settings-updated');
    }

    /**
     * Send a test email using the submitted SMTP credentials.
     */
    public function test(SmtpSettingsUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $testEmailData = $request->validate([
            'test_email' => ['required', 'email:rfc,dns', 'max:255'],
        ]);

        $validationErrors = $this->validateRequiredSmtpFields($validated, true, !empty($user?->smtp_password));
        if ($validationErrors !== []) {
            return back()->withErrors($validationErrors);
        }

        $host = trim((string) ($validated['smtp_host'] ?? ''));
        $port = (int) ($validated['smtp_port'] ?? 0);
        $encryption = (string) ($validated['smtp_encryption'] ?? 'tls');
        $username = trim((string) ($validated['smtp_username'] ?? ''));
        $passwordInput = (string) ($validated['smtp_password'] ?? '');
        $password = $passwordInput !== '' ? $passwordInput : (string) ($user?->smtp_password ?? '');
        $fromAddress = trim((string) ($validated['smtp_from_address'] ?? ''));
        $fromName = trim((string) ($validated['smtp_from_name'] ?? ''));
        $toAddress = trim((string) ($testEmailData['test_email'] ?? ''));

        $transport = [
            'transport' => 'smtp',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption === 'none' ? null : $encryption,
            'username' => $username,
            'password' => $password,
            'timeout' => null,
        ];

        try {
            app('mail.manager')->build($transport)->send([], [], function ($message) use ($toAddress, $fromAddress, $fromName): void {
                $message->to($toAddress);
                $message->from($fromAddress, $fromName);
                $message->subject('SMTP Test - Webinar Platform');
                $message->html('<p>Your SMTP configuration works correctly.</p><p>This is a test email from your Webinar account settings.</p>');
            });

            return back()->with('status', 'smtp-test-sent');
        } catch (\Throwable $exception) {
            Log::warning('smtp.test.failed', [
                'user_id' => $user?->id,
                'message' => $exception->getMessage(),
            ]);

            return back()
                ->withErrors(['smtp_test' => 'SMTP test failed: '.$exception->getMessage()])
                ->with('status', 'smtp-test-failed');
        }
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, string>
     */
    private function validateRequiredSmtpFields(array $validated, bool $enabled, bool $hasExistingPassword): array
    {
        if (!$enabled) {
            return [];
        }

        $errors = [];

        if (blank($validated['smtp_host'] ?? null)) {
            $errors['smtp_host'] = 'SMTP host is required when SMTP is enabled.';
        }

        if (blank($validated['smtp_port'] ?? null)) {
            $errors['smtp_port'] = 'SMTP port is required when SMTP is enabled.';
        }

        if (blank($validated['smtp_username'] ?? null)) {
            $errors['smtp_username'] = 'SMTP username is required when SMTP is enabled.';
        }

        if (blank($validated['smtp_from_address'] ?? null)) {
            $errors['smtp_from_address'] = 'From email is required when SMTP is enabled.';
        }

        if (blank($validated['smtp_from_name'] ?? null)) {
            $errors['smtp_from_name'] = 'From name is required when SMTP is enabled.';
        }

        $incomingPassword = (string) ($validated['smtp_password'] ?? '');
        if (!$hasExistingPassword && $incomingPassword === '') {
            $errors['smtp_password'] = 'SMTP password is required when enabling SMTP for the first time.';
        }

        return $errors;
    }
}
