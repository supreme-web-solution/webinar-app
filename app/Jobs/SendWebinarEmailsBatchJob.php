<?php

namespace App\Jobs;

use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use App\Services\ResendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SendWebinarEmailsBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int, int> $registrantIds
     */
    public function __construct(
        private readonly int $webinarId,
        private readonly array $registrantIds,
        private readonly string $subject,
        private readonly string $intro,
        private readonly ?string $markSentColumn = null,
    ) {
    }

    public function handle(ResendService $resendService): void
    {
        if ($this->registrantIds === []) {
            return;
        }

        $webinar = Webinar::query()->find($this->webinarId);
        if (!$webinar) {
            return;
        }

        $registrants = WebinarRegistrant::query()
            ->where('webinar_id', $this->webinarId)
            ->where('is_subscribed', true)
            ->whereIn('id', $this->registrantIds)
            ->get();

        if ($registrants->isEmpty()) {
            return;
        }

        $result = $resendService->sendWebinarEmailBatch(
            $webinar,
            $registrants,
            $this->subject,
            $this->intro
        );

        if (
            in_array($this->markSentColumn, ['reminder_sent_at', 'follow_up_sent_at'], true)
            && $result['sent_registrant_ids'] !== []
        ) {
            WebinarRegistrant::query()
                ->whereIn('id', $result['sent_registrant_ids'])
                ->update([
                    $this->markSentColumn => Carbon::now(),
                ]);
        }
    }
}
