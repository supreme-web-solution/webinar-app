<?php

namespace App\Jobs;

use App\Http\Controllers\Admin\WebinarAiStudioController;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ComposeWebinarAiVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Compose can run for several minutes, and shorter queue retry_after values
     * can cause the same job payload to be re-attempted while the original worker
     * is still processing. Keep tries high to avoid false max-attempt failures.
     */
    public int $tries = 25;

    public int $timeout = 7200;

    public function __construct(
        public readonly string $videoId,
        public readonly string $introVideoUrl,
    ) {
        $this->onQueue((string) env('QUEUE_AI_VIDEO_COMPOSE_NAME', 'ai-video-compose'));
    }

    public function handle(WebinarAiStudioController $controller): void
    {
        $controller->runQueuedLongFormCompose($this->videoId, $this->introVideoUrl);
    }
}
