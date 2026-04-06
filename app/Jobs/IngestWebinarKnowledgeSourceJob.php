<?php

namespace App\Jobs;

use App\Models\WebinarAiKnowledgeSource;
use App\Services\AI\WebinarKnowledgeIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestWebinarKnowledgeSourceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $sourceId,
    ) {
    }

    public function handle(WebinarKnowledgeIngestionService $ingestionService): void
    {
        $source = WebinarAiKnowledgeSource::query()->find($this->sourceId);
        if (! $source) {
            return;
        }

        $ingestionService->ingestSource($source);
    }
}
