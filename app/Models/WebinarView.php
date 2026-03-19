<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebinarView extends Model
{
    use HasFactory;

    protected $fillable = [
        'webinar_id',
        'registrant_id',
        'joined_at',
        'left_at',
        'session_started_at',
        'watch_duration_seconds',
        'timeline_offset_seconds',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'session_started_at' => 'datetime',
        ];
    }

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    public function registrant(): BelongsTo
    {
        return $this->belongsTo(WebinarRegistrant::class, 'registrant_id');
    }

    public function analyticsEvents(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'view_id');
    }
}
