<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webinar\StoreWebinarRequest;
use App\Http\Requests\Webinar\UpdateWebinarRequest;
use App\Models\Webinar;
use App\Models\WebinarOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class WebinarController extends Controller
{
    public function index(): Response
    {
        $webinars = Webinar::query()
            ->where('user_id', Auth::id())
            ->withCount(['registrants', 'views'])
            ->latest()
            ->paginate(10)
            ->through(fn (Webinar $webinar) => [
                'id' => $webinar->id,
                'uuid' => $webinar->uuid,
                'title' => $webinar->title,
                'schedule_mode' => $webinar->schedule_mode ?: 'scheduled',
                'has_ended' => $webinar->hasEnded(),
                'scheduled_at_label' => $webinar->scheduled_at
                    ? $webinar->scheduled_at->copy()->timezone($webinar->scheduled_timezone ?: 'UTC')->format('M j, Y · g:i a')
                    : null,
                'scheduled_timezone' => $webinar->scheduled_timezone ?: config('app.timezone', 'UTC'),
                'host_name' => $webinar->host_name,
                'video_source' => $webinar->video_source,
                'is_published' => $webinar->is_published,
                'registrants_count' => $webinar->registrants_count,
                'views_count' => $webinar->views_count,
                'registration_link' => route('webinar.register', ['webinar' => $webinar->uuid]),
                'room_link' => route('webinar.room.public', ['webinar' => $webinar->uuid]),
                'chat_link' => route('admin.webinars.chat.show', ['webinar' => $webinar->id]),
                'notify_link' => route('admin.webinars.notify', ['webinar' => $webinar->id]),
                'updated_at' => $webinar->updated_at?->toDateTimeString(),
            ]);

        return Inertia::render('webinars/Index', [
            'webinars' => $webinars,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('webinars/Create', [
            'defaults' => [
                'title' => '',
                'title_prefix' => '[Confirmation]',
                'schedule_mode' => 'auto',
                'host_name' => (string) Auth::user()?->name,
                'description' => '',
                'scheduled_at' => Carbon::now()->addDay()->format('Y-m-d\\TH:i'),
                'scheduled_timezone' => config('app.timezone', 'UTC'),
                'video_source' => 'youtube',
                'video_url' => '',
                'video_duration_seconds' => null,
                'thumbnail_path' => '',
                'uuid' => null,
                'min_viewers' => 80,
                'max_viewers' => 180,
                'is_published' => false,
                'email_settings' => [
                    'send_confirmation' => true,
                    'send_reminder' => true,
                    'send_follow_up' => true,
                ],
                'playback_settings' => [
                    'show_fake_viewers' => true,
                    'redirect_enabled' => false,
                    'redirect_url' => '',
                ],
                'registration_settings' => $this->defaultRegistrationSettings(),
                'offers' => [],
            ],
            'attendees' => [
                'subscribed' => [],
                'unsubscribed' => [],
            ],
            'attendeeImportUrl' => null,
            'attendeeActionUrls' => null,
            'timezoneOptions' => timezone_identifiers_list(),
        ]);
    }

    public function store(StoreWebinarRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $offers = $data['offers'] ?? [];
        unset($data['offers']);
        $data = $this->normalizeDescriptionPayload($data);
        $data = $this->normalizeTitlePrefixPayload($data);
        $data = $this->normalizeSchedulePayload($data);
        $data = $this->normalizePlaybackSettingsPayload($data);
        $data = $this->normalizeRegistrationSettingsPayload($data);
        $data['user_id'] = Auth::id();

        if (($data['is_published'] ?? false) && empty($data['published_at'])) {
            $data['published_at'] = Carbon::now();
        }

        $webinar = Webinar::create($data);
        $this->syncOffers($webinar, $offers);

        return redirect()
            ->route('admin.webinars.edit', $webinar)
            ->with('success', 'Webinar created. Continue configuring details in step tabs.');
    }

    public function edit(Webinar $webinar): Response
    {
        abort_unless($webinar->user_id === Auth::id(), 403);

        return Inertia::render('webinars/Edit', [
            'webinar' => [
                'id' => $webinar->id,
                'title' => $webinar->title,
                'title_prefix' => $webinar->title_prefix ?: '[Confirmation]',
                'schedule_mode' => $webinar->schedule_mode ?: 'scheduled',
                'host_name' => $webinar->host_name,
                'description' => $webinar->description,
                'scheduled_at' => $webinar->scheduled_at
                    ? $webinar->scheduled_at->setTimezone($webinar->scheduled_timezone ?: 'UTC')->format('Y-m-d\\TH:i')
                    : '',
                'scheduled_timezone' => $webinar->scheduled_timezone ?: config('app.timezone', 'UTC'),
                'video_source' => $webinar->video_source,
                'video_url' => $webinar->video_url,
                'video_duration_seconds' => $webinar->video_duration_seconds,
                'thumbnail_path' => $webinar->thumbnail_path,
                'uuid' => $webinar->uuid,
                'min_viewers' => $webinar->min_viewers,
                'max_viewers' => $webinar->max_viewers,
                'is_published' => $webinar->is_published,
                'email_settings' => $webinar->email_settings ?? [
                    'send_confirmation' => true,
                    'send_reminder' => true,
                    'send_follow_up' => true,
                ],
                'playback_settings' => array_merge([
                    'show_fake_viewers' => true,
                    'redirect_enabled' => false,
                    'redirect_url' => '',
                ], is_array($webinar->playback_settings) ? $webinar->playback_settings : []),
                'registration_settings' => $webinar->registration_settings ?? $this->defaultRegistrationSettings(),
                'offers' => $webinar->offers()
                    ->orderBy('trigger_second')
                    ->get()
                    ->map(fn (WebinarOffer $offer) => [
                        'id' => $offer->id,
                        'title' => $offer->title,
                        'description' => $offer->description,
                        'trigger_second' => $offer->trigger_second,
                        'button_text' => $offer->button_text,
                        'button_url' => $offer->button_url,
                        'display_mode' => $offer->display_mode,
                    ]),
            ],
            'attendees' => [
                'subscribed' => $webinar->registrants()
                    ->where('is_subscribed', true)
                    ->orderByDesc('registered_at')
                    ->get()
                    ->map(fn ($registrant) => [
                        'id' => $registrant->id,
                        'name' => $registrant->name,
                        'email' => $registrant->email,
                        'registered_at' => $registrant->registered_at?->toDateTimeString(),
                        'unsubscribe_url' => route('admin.webinars.attendees.unsubscribe', [
                            'webinar' => $webinar->id,
                            'registrant' => $registrant->id,
                        ]),
                    ]),
                'unsubscribed' => $webinar->registrants()
                    ->with('unsubscribeLog')
                    ->where('is_subscribed', false)
                    ->orderByDesc('updated_at')
                    ->get()
                    ->map(fn ($registrant) => [
                        'id' => $registrant->id,
                        'name' => $registrant->name,
                        'email' => $registrant->email,
                        'unsubscribed_at' => $registrant->unsubscribeLog?->unsubscribed_at?->toDateTimeString(),
                        'delete_url' => route('admin.webinars.attendees.delete', [
                            'webinar' => $webinar->id,
                            'registrant' => $registrant->id,
                        ]),
                    ]),
            ],
            'attendeeImportUrl' => route('admin.webinars.attendees.import', ['webinar' => $webinar->id]),
            'attendeeActionUrls' => [
                'bulk_unsubscribe_url' => route('admin.webinars.attendees.unsubscribe.bulk', ['webinar' => $webinar->id]),
                'bulk_delete_url' => route('admin.webinars.attendees.delete.bulk', ['webinar' => $webinar->id]),
            ],
            'timezoneOptions' => timezone_identifiers_list(),
            'stats' => [
                'registrants' => $webinar->registrants()->count(),
                'views' => $webinar->views()->count(),
                'views_60_seconds' => $webinar->views()->where('watch_duration_seconds', '>=', 60)->count(),
                'views_watched_to_end' => $webinar->views()->whereNotNull('left_at')->count(),
                'chat_messages' => $webinar->chatMessages()->count(),
                'offers' => $webinar->offers()->count(),
                'cta_clicks' => $webinar->analyticsEvents()->where('event_type', 'offer_cta_clicked')->count(),
            ],
        ]);
    }

    public function update(UpdateWebinarRequest $request, Webinar $webinar): RedirectResponse
    {
        $data = $request->validated();
        $offers = $data['offers'] ?? [];
        unset($data['offers']);
        $data = $this->normalizeDescriptionPayload($data);
        $data = $this->normalizeTitlePrefixPayload($data);
        $data = $this->normalizeSchedulePayload($data);
        $data = $this->normalizePlaybackSettingsPayload($data);
        $data = $this->normalizeRegistrationSettingsPayload($data);

        if (($data['is_published'] ?? false) && $webinar->published_at === null) {
            $data['published_at'] = Carbon::now();
        }

        $webinar->update($data);
        $this->syncOffers($webinar, $offers);

        return back()->with('success', 'Webinar updated successfully.');
    }

    /**
     * @return array{buttons: array<int, array<string, mixed>>}
     */
    private function defaultRegistrationSettings(): array
    {
        return [
            'buttons' => [
                [
                    'label' => 'Join Webinar Now',
                    'enabled' => true,
                    'is_primary' => true,
                    'urgency_mode' => 'none',
                    'urgency_minutes' => null,
                ],
                [
                    'label' => 'Secure My Seat',
                    'enabled' => false,
                    'is_primary' => false,
                    'urgency_mode' => 'minutes',
                    'urgency_minutes' => 15,
                ],
                [
                    'label' => 'Join Live Session',
                    'enabled' => false,
                    'is_primary' => false,
                    'urgency_mode' => 'live',
                    'urgency_minutes' => null,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeRegistrationSettingsPayload(array $data): array
    {
        $settings = $data['registration_settings'] ?? $this->defaultRegistrationSettings();
        $buttons = is_array($settings) && isset($settings['buttons']) && is_array($settings['buttons'])
            ? array_values($settings['buttons'])
            : [];

        if (count($buttons) !== 3) {
            $buttons = $this->defaultRegistrationSettings()['buttons'];
        }

        $normalized = [];
        foreach ($buttons as $index => $button) {
            $label = trim((string) ($button['label'] ?? ''));
            $enabled = (bool) ($button['enabled'] ?? false);
            $isPrimary = (bool) ($button['is_primary'] ?? false);
            $urgencyMode = (string) ($button['urgency_mode'] ?? 'none');
            $urgencyMinutes = isset($button['urgency_minutes']) ? (int) $button['urgency_minutes'] : null;

            if (!in_array($urgencyMode, ['none', 'minutes', 'live'], true)) {
                $urgencyMode = 'none';
            }

            if ($urgencyMode !== 'minutes') {
                $urgencyMinutes = null;
            }

            $normalized[] = [
                'label' => $label !== '' ? $label : 'Join Webinar',
                'enabled' => $enabled,
                'is_primary' => $isPrimary,
                'urgency_mode' => $urgencyMode,
                'urgency_minutes' => $urgencyMinutes,
                'position' => $index,
            ];
        }

        if (!collect($normalized)->contains(fn ($button) => $button['enabled'] === true)) {
            $normalized[0]['enabled'] = true;
        }

        foreach ($normalized as $idx => $button) {
            if ($button['enabled'] === false && $button['is_primary'] === true) {
                $normalized[$idx]['is_primary'] = false;
            }
        }

        $primaryIndex = collect($normalized)->search(fn ($button) => $button['enabled'] === true && $button['is_primary'] === true);
        if ($primaryIndex === false) {
            $firstEnabled = collect($normalized)->search(fn ($button) => $button['enabled'] === true);
            if ($firstEnabled !== false) {
                $normalized[$firstEnabled]['is_primary'] = true;
            }
        } else {
            foreach ($normalized as $idx => $button) {
                if ($idx !== $primaryIndex) {
                    $normalized[$idx]['is_primary'] = false;
                }
            }
        }

        $data['registration_settings'] = [
            'buttons' => $normalized,
        ];

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeTitlePrefixPayload(array $data): array
    {
        $raw = trim((string) ($data['title_prefix'] ?? ''));
        $data['title_prefix'] = $raw !== '' ? $raw : '[Confirmation]';

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeSchedulePayload(array $data): array
    {
        $mode = (string) ($data['schedule_mode'] ?? 'auto');
        $data['schedule_mode'] = in_array($mode, ['auto', 'scheduled'], true) ? $mode : 'auto';

        if ($data['schedule_mode'] === 'auto') {
            $data['scheduled_at'] = null;
            $data['scheduled_timezone'] = config('app.timezone', 'UTC');

            return $data;
        }

        $timezone = (string) ($data['scheduled_timezone'] ?? config('app.timezone', 'UTC'));
        $scheduledAt = (string) ($data['scheduled_at'] ?? '');

        if ($scheduledAt !== '') {
            $data['scheduled_at'] = Carbon::createFromFormat('Y-m-d\\TH:i', $scheduledAt, $timezone)->utc();
        }

        $data['scheduled_timezone'] = $timezone;

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDescriptionPayload(array $data): array
    {
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            $data['description'] = '';

            return $data;
        }

        $allowedTags = '<p><br><strong><em><b><i><u><ul><ol><li><a>';
        $sanitized = strip_tags($description, $allowedTags);

        // Keep only safe links and remove unknown attributes from allowed tags.
        $sanitized = preg_replace_callback('/<a\b[^>]*>/i', static function (array $matches): string {
            $tag = $matches[0] ?? '';
            if (! preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                return '<a>';
            }

            $href = trim((string) ($hrefMatch[1] ?? ''));
            if (! preg_match('/^https?:\/\//i', $href)) {
                return '<a>';
            }

            return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">';
        }, $sanitized) ?? '';

        $sanitized = preg_replace('/<(p|br|strong|em|b|i|u|ul|ol|li)\b[^>]*>/i', '<$1>', $sanitized) ?? '';
        $data['description'] = trim($sanitized);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizePlaybackSettingsPayload(array $data): array
    {
        $settings = is_array($data['playback_settings'] ?? null)
            ? $data['playback_settings']
            : [];

        $redirectEnabled = (bool) ($settings['redirect_enabled'] ?? false);
        $redirectUrl = trim((string) ($settings['redirect_url'] ?? ''));

        $data['playback_settings'] = [
            'show_fake_viewers' => (bool) ($settings['show_fake_viewers'] ?? true),
            'redirect_enabled' => $redirectEnabled,
            'redirect_url' => $redirectEnabled ? $redirectUrl : '',
        ];

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $offers
     */
    private function syncOffers(Webinar $webinar, array $offers): void
    {
        $webinar->offers()->delete();

        foreach ($offers as $offer) {
            $webinar->offers()->create([
                'created_by' => Auth::id(),
                'title' => (string) ($offer['title'] ?? ''),
                'description' => $offer['description'] ?? null,
                'trigger_second' => (int) ($offer['trigger_second'] ?? 1),
                'button_text' => (string) ($offer['button_text'] ?? 'Claim Offer'),
                'button_url' => (string) ($offer['button_url'] ?? ''),
                'display_mode' => (string) ($offer['display_mode'] ?? 'chat'),
                'is_active' => true,
            ]);
        }
    }
}
