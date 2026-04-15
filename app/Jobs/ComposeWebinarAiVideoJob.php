<?php

namespace App\Jobs;

use App\Http\Controllers\Admin\WebinarAiStudioController;
use Illuminate\Foundation\Bus\Dispatchable;

class ComposeWebinarAiVideoJob
{
    use Dispatchable;

    public function __construct(
        public readonly string $videoId,
        public readonly string $introVideoUrl,
    ) {
    }

    public function handle(WebinarAiStudioController $controller): void
    {
        $controller->runQueuedLongFormCompose($this->videoId, $this->introVideoUrl);
    }
}
