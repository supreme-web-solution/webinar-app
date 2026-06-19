<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostmarkEmailDelivery extends Model
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_SPAM_COMPLAINT = 'spam_complaint';

    public const STATUS_SUPPRESSED = 'suppressed';

    protected $fillable = [
        'user_id',
        'postmark_message_id',
        'email',
        'status',
        'source_type',
        'webinar_id',
        'registrant_id',
        'campaign_id',
        'recipient_id',
        'email_type',
        'subject',
        'accepted_at',
        'delivered_at',
        'bounced_at',
        'bounce_type',
        'bounce_description',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'bounced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    public function registrant(): BelongsTo
    {
        return $this->belongsTo(WebinarRegistrant::class, 'registrant_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(EmailCampaignRecipient::class, 'recipient_id');
    }
}
