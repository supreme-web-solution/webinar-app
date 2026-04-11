<?php

namespace App\Jobs;

use App\Models\WebinarAiKnowledgeSource;
use App\Services\AI\WebinarVideoTranscriptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateWebinarVideoTranscriptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $sourceId,
    ) {
    }

    public function handle(WebinarVideoTranscriptService $service): void
    {
        $source = WebinarAiKnowledgeSource::query()->find($this->sourceId);
        if (! $source) {
            return;
        }

        try {
            $service->transcribeAndQueueIngestion($source);
        } catch (\Throwable $e) {
            Log::error('GenerateWebinarVideoTranscriptJob failed', [
                'source_id' => $this->sourceId,
                'message' => $e->getMessage(),
            ]);

            $source->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'meta' => array_merge($source->meta ?? [], [
                    'transcription_status' => 'failed',
                ]),
            ]);
        }
    }
}
