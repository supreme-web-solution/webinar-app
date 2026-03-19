<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'webinar_id',
        'registrant_id',
        'sender_type',
        'sender_name',
        'message',
        'timeline_second',
        'is_automated',
        'meta',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_automated' => 'boolean',
            'meta' => 'array',
            'sent_at' => 'datetime',
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
}
