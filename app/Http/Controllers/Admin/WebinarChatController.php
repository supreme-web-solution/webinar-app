<?php

namespace App\Http\Controllers\Admin;

use App\Events\WebinarChatMessageSent;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WebinarChatController extends Controller
{
    public function index(): Response
    {
        $webinars = Webinar::query()
            ->where('user_id', Auth::id())
            ->withCount([
                'chatMessages as attendee_messages_count' => fn ($query) => $query->where('sender_type', 'attendee'),
            ])
            ->latest()
            ->get()
            ->map(fn (Webinar $webinar) => [
                'id' => $webinar->id,
                'title' => $webinar->title,
                'attendee_messages_count' => $webinar->attendee_messages_count,
                'chat_url' => route('admin.webinars.chat.show', $webinar),
            ]);

        return Inertia::render('webinars/ChatsIndex', [
            'webinars' => $webinars,
        ]);
    }

    public function show(Webinar $webinar, Request $request): Response
    {
        abort_unless($webinar->user_id === Auth::id(), 403);

        $registrants = WebinarRegistrant::query()
            ->where('webinar_id', $webinar->id)
            ->whereHas('chatMessages')
            ->withCount('chatMessages')
            ->latest('last_joined_at')
            ->get();

        $selectedRegistrantId = $request->integer('registrant_id');

        $messages = collect();

        if ($selectedRegistrantId > 0) {
            $messages = ChatMessage::query()
                ->where('webinar_id', $webinar->id)
                ->where('registrant_id', $selectedRegistrantId)
                ->orderBy('sent_at')
                ->orderBy('id')
                ->get()
                ->map(fn (ChatMessage $message) => [
                    'id' => $message->id,
                    'sender_type' => $message->sender_type,
                    'sender_name' => $message->sender_name,
                    'message' => $message->message,
                    'sent_at' => ($message->sent_at ?? $message->created_at)?->toDateTimeString(),
                ]);
        }

        return Inertia::render('webinars/ChatShow', [
            'webinar' => [
                'id' => $webinar->id,
                'title' => $webinar->title,
            ],
            'registrants' => $registrants->map(fn (WebinarRegistrant $registrant) => [
                'id' => $registrant->id,
                'name' => $registrant->name,
                'email' => $registrant->email,
                'chat_messages_count' => $registrant->chat_messages_count,
            ]),
            'selectedRegistrantId' => $selectedRegistrantId,
            'messages' => $messages,
        ]);
    }

    public function reply(Request $request, Webinar $webinar, WebinarRegistrant $registrant): RedirectResponse
    {
        abort_unless($webinar->user_id === Auth::id(), 403);
        abort_unless($registrant->webinar_id === $webinar->id, 404);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $message = ChatMessage::create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'sender_type' => 'host',
            'sender_name' => $webinar->host_name,
            'message' => $validated['message'],
            'is_automated' => false,
            'sent_at' => Carbon::now(),
        ]);

        Cache::forget("webinar:chat:{$registrant->access_token}");
        broadcast(new WebinarChatMessageSent($registrant->access_token, $message))->toOthers();

        return redirect()
            ->route('admin.webinars.chat.show', ['webinar' => $webinar->id, 'registrant_id' => $registrant->id]);
    }
}
