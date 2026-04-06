<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarAiKnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'webinar_id',
        'source_id',
        'chunk_index',
        'content',
        'content_hash',
        'embedding',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'meta' => 'array',
        ];
    }

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(WebinarAiKnowledgeSource::class, 'source_id');
    }
}
