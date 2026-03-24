<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebinarChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $chatToken,
        public readonly ChatMessage $chatMessage,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel("webinar.chat.{$this->chatToken}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->chatMessage->id,
            'sender' => $this->chatMessage->sender_name ?? 'System',
            'message' => $this->chatMessage->message,
            'self' => $this->chatMessage->sender_type === 'attendee',
            'at' => ($this->chatMessage->sent_at ?? $this->chatMessage->created_at)?->format('H:i'),
        ];
    }
}
