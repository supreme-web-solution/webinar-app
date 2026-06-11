<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailCampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'name',
        'email',
        'access_token',
        'is_subscribed',
        'imported_at',
        'first_sent_at',
        'last_sent_at',
        'send_count',
        'first_clicked_at',
        'last_clicked_at',
        'click_count',
    ];

    protected function casts(): array
    {
        return [
            'is_subscribed' => 'boolean',
            'imported_at' => 'datetime',
            'first_sent_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'first_clicked_at' => 'datetime',
            'last_clicked_at' => 'datetime',
            'send_count' => 'integer',
            'click_count' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(EmailCampaignClick::class, 'recipient_id');
    }

    public function unsubscribeLog(): HasOne
    {
        return $this->hasOne(EmailCampaignUnsubscribe::class, 'recipient_id');
    }
}
