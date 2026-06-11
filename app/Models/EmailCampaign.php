<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title_prefix',
        'title',
        'sender_name',
        'body',
        'cta_label',
        'cta_url',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class, 'campaign_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(EmailCampaignClick::class, 'campaign_id');
    }

    public function prefixedTitleLine(): string
    {
        $prefix = trim((string) ($this->title_prefix ?? ''));
        if ($prefix === '') {
            $prefix = '[Campaign]';
        }

        return "{$prefix} : {$this->title}";
    }

    /**
     * @return array<int, string>
     */
    public function missingBasicsFields(): array
    {
        $missing = [];

        if (trim((string) $this->title) === '') {
            $missing[] = 'Email Title';
        }

        if (trim((string) $this->sender_name) === '') {
            $missing[] = 'Sender Name';
        }

        if (trim((string) $this->cta_label) === '') {
            $missing[] = 'CTA Label';
        }

        $ctaUrl = trim((string) $this->cta_url);
        if ($ctaUrl === '' || filter_var($ctaUrl, FILTER_VALIDATE_URL) === false) {
            $missing[] = 'CTA Link';
        }

        $bodyPlain = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($this->body ?? ''))) ?? '');
        if ($bodyPlain === '') {
            $missing[] = 'Email Body';
        }

        return $missing;
    }

    public function isReadyToSend(): bool
    {
        return $this->missingBasicsFields() === [];
    }
}
