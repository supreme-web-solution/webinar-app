<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailUnsubscribe extends Model
{
    use HasFactory;

    protected $fillable = [
        'webinar_id',
        'registrant_id',
        'email',
        'token',
        'unsubscribed_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'unsubscribed_at' => 'datetime',
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
