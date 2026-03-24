<?php

namespace App\Http\Controllers;

use App\Events\WebinarChatMessageSent;
use App\Models\ChatMessage;
use App\Models\WebinarRegistrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class WebinarChatController extends Controller
{
    public function index(string $token): JsonResponse
    {
        $registrant = WebinarRegistrant::query()
            ->where('access_token', $token)
            ->firstOrFail();

        $messages = Cache::remember(
            "webinar:chat:{$registrant->access_token}",
            now()->addSeconds(8),
            function () use ($registrant) {
                return ChatMessage::query()
                    ->where('webinar_id', $registrant->webinar_id)
                    ->where(function ($query) use ($registrant): void {
                        $query->where('registrant_id', $registrant->id)
                            ->orWhere(function ($nested): void {
                                $nested->whereNull('registrant_id')
                                    ->where('sender_type', 'system');
                            });
                    })
                    ->orderBy('sent_at')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (ChatMessage $message) => [
                        'id' => $message->id,
                        'sender' => $message->sender_name ?? 'System',
                        'message' => $message->message,
                        'self' => $message->sender_type === 'attendee',
                        'at' => ($message->sent_at ?? $message->created_at)?->format('H:i'),
                    ])
                    ->values();
            }
        );

        return response()->json([
            'messages' => $messages,
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $registrant = WebinarRegistrant::query()
            ->where('access_token', $token)
            ->firstOrFail();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $message = ChatMessage::create([
            'webinar_id' => $registrant->webinar_id,
            'registrant_id' => $registrant->id,
            'sender_type' => 'attendee',
            'sender_name' => $registrant->name,
            'message' => $validated['message'],
            'is_automated' => false,
            'sent_at' => Carbon::now(),
        ]);

        Cache::forget("webinar:chat:{$registrant->access_token}");
        broadcast(new WebinarChatMessageSent($registrant->access_token, $message))->toOthers();

        return response()->json([
            'message' => [
                'id' => $message->id,
                'sender' => $message->sender_name,
                'message' => $message->message,
                'self' => true,
                'at' => $message->sent_at?->format('H:i'),
            ],
        ]);
    }
}
