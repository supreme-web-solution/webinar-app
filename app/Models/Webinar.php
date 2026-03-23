<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Webinar extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'uuid',
        'title',
        'title_prefix',
        'schedule_mode',
        'host_name',
        'description',
        'scheduled_at',
        'scheduled_timezone',
        'video_source',
        'video_url',
        'video_duration_seconds',
        'thumbnail_path',
        'slug',
        'min_viewers',
        'max_viewers',
        'is_published',
        'published_at',
        'email_settings',
        'playback_settings',
        'registration_settings',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'email_settings' => 'array',
            'playback_settings' => 'array',
            'registration_settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $webinar): void {
            if (empty($webinar->uuid)) {
                $webinar->uuid = (string) Str::uuid();
            }

            if (empty($webinar->slug)) {
                $webinar->slug = Str::lower(Str::random(12));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registrants(): HasMany
    {
        return $this->hasMany(WebinarRegistrant::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(WebinarView::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(WebinarOffer::class);
    }

    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(ScheduledMessage::class);
    }

    public function analyticsEvents(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    /**
     * Subject/header line for emails: "[Prefix] : Title" (default prefix when unset).
     */
    public function prefixedTitleLine(): string
    {
        $prefix = trim((string) ($this->title_prefix ?? ''));
        if ($prefix === '') {
            $prefix = '[Confirmation]';
        }

        return "{$prefix} : {$this->title}";
    }

    public function isAutoMode(): bool
    {
        return $this->schedule_mode === 'auto';
    }

    public function isScheduledMode(): bool
    {
        return !$this->isAutoMode();
    }

    public function scheduledEndAt(): ?Carbon
    {
        if ($this->isAutoMode() || !$this->scheduled_at instanceof Carbon) {
            return null;
        }

        return $this->scheduled_at->copy()->addMinutes(90);
    }

    public function hasEnded(): bool
    {
        $endAt = $this->scheduledEndAt();

        if ($endAt === null) {
            return false;
        }

        return now()->greaterThanOrEqualTo($endAt);
    }
}
