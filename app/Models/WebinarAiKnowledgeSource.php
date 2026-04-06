<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebinarAiKnowledgeSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'webinar_id',
        'source_type',
        'title',
        'source_url',
        'storage_path',
        'raw_text',
        'status',
        'error_message',
        'meta',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(WebinarAiKnowledgeChunk::class, 'source_id');
    }
}
