<?php

namespace App\Jobs;

use App\Events\WebinarChatMessageSent;
use App\Models\ChatMessage;
use App\Models\WebinarRegistrant;
use App\Services\AI\WebinarAiAssistantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class GenerateWebinarAiReplyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $registrantId,
        public readonly int $attendeeMessageId,
    ) {
    }

    public function handle(WebinarAiAssistantService $assistant): void
    {
        $registrant = WebinarRegistrant::query()->with('webinar')->find($this->registrantId);
        if (! $registrant || ! $registrant->webinar) {
            return;
        }

        $attendeeMessage = ChatMessage::query()->find($this->attendeeMessageId);
        if (! $attendeeMessage || $attendeeMessage->sender_type !== 'attendee') {
            return;
        }

        $alreadyReplied = ChatMessage::query()
            ->where('webinar_id', $registrant->webinar_id)
            ->where('registrant_id', $registrant->id)
            ->where('sender_type', 'system')
            ->where('is_automated', true)
            ->where('meta->reply_to_message_id', $attendeeMessage->id)
            ->exists();

        if ($alreadyReplied) {
            return;
        }

        $result = $assistant->maybeGenerateReply($registrant->webinar, $registrant, (string) $attendeeMessage->message);
        if (! $result || trim((string) ($result['answer'] ?? '')) === '') {
            return;
        }

        $assistantName = trim((string) data_get($registrant->webinar->ai_settings, 'assistant_name', 'AI Webinar Helper'));
        if ($assistantName === '') {
            $assistantName = 'AI Webinar Helper';
        }

        $reply = ChatMessage::create([
            'webinar_id' => $registrant->webinar_id,
            'registrant_id' => $registrant->id,
            'sender_type' => 'system',
            'sender_name' => $assistantName,
            'message' => $result['answer'],
            'is_automated' => true,
            'meta' => [
                'reply_to_message_id' => $attendeeMessage->id,
                'classification' => $result['classification'] ?? null,
                'sources' => $result['sources'] ?? [],
            ],
            'sent_at' => Carbon::now(),
        ]);

        Cache::forget("webinar:chat:{$registrant->access_token}");
        broadcast(new WebinarChatMessageSent($registrant->access_token, $reply))->toOthers();
    }
}
