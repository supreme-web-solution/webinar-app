<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WebinarRegistrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'webinar_id',
        'user_id',
        'name',
        'email',
        'access_token',
        'email_verified_at',
        'registered_at',
        'last_joined_at',
        'is_subscribed',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'registered_at' => 'datetime',
            'last_joined_at' => 'datetime',
            'is_subscribed' => 'boolean',
        ];
    }

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(WebinarView::class, 'registrant_id');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'registrant_id');
    }

    public function unsubscribeLog(): HasOne
    {
        return $this->hasOne(EmailUnsubscribe::class, 'registrant_id');
    }
}
