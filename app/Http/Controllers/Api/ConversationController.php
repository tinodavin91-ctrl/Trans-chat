<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageRead;
use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $conversations = Conversation::whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->with(['users', 'latestMessage'])
            ->get()
            ->map(function (Conversation $conversation) use ($userId) {
                $participant = $conversation->participants()->where('user_id', $userId)->first();
                $lastReadAt = $participant?->last_read_at;

                $conversation->unread_count = Message::where('conversation_id', $conversation->id)
                    ->where('sender_id', '!=', $userId)
                    ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
                    ->count();

                return $conversation;
            });

        return response()->json($conversations);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:direct,group',
            'name' => 'required_if:type,group|nullable|string|max:255',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        foreach ($request->user_ids as $userId) {
            if (! User::find($userId)) {
                return response()->json([
                    'errors' => ['user_ids' => ["User {$userId} was not found."]],
                ], 422);
            }
        }

        $authUserId = $request->user()->id;
        $participantIds = array_values(array_unique(array_merge($request->user_ids, [$authUserId])));

        if ($request->type === 'direct') {
            if (count($participantIds) !== 2) {
                return response()->json(['message' => 'Direct chats need exactly one other user.'], 422);
            }

            $existing = $this->findDirectConversation($participantIds[0], $participantIds[1]);

            if ($existing) {
                return response()->json($existing->load(['users', 'latestMessage']));
            }
        }

        $conversation = Conversation::create([
            'type' => $request->type,
            'name' => $request->type === 'group' ? $request->name : null,
            'created_by' => $authUserId,
        ]);

        $conversation->users()->attach($participantIds);

        return response()->json($conversation->load(['users', 'latestMessage']), 201);
    }

    public function show(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->users->contains($request->user()->id), 403);

        return response()->json($conversation->load(['users', 'messages.sender', 'messages.reactions', 'messages.sticker']));
    }

    public function markRead(Request $request, Conversation $conversation)
    {
        $userId = $request->user()->id;
        abort_unless($conversation->users->contains($userId), 403);

        $messageIds = Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->pluck('id');

        if ($messageIds->isEmpty()) {
            return response()->json(['message' => 'Nothing to mark as read.']);
        }

        Message::whereIn('id', $messageIds)->update(['read_at' => now()]);

        $conversation->participants()->where('user_id', $userId)->update(['last_read_at' => now()]);

        broadcast(new MessageRead($conversation->id, $messageIds->all(), $userId))->toOthers();

        return response()->json(['message_ids' => $messageIds]);
    }

    public function typing(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->users->contains($request->user()->id), 403);

        broadcast(new UserTyping($conversation->id, $request->user()))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    public function media(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->users->contains($request->user()->id), 403);

        $messages = $conversation->messages()->orderByDesc('created_at')->get();

        $mediaMessages = $messages->whereIn('attachment_type', ['image', 'audio'])->values();
        $docMessages = $messages->where('attachment_type', 'file')->values();

        $linkMessages = $messages
            ->filter(fn (Message $message) => $message->body && preg_match('/https?:\/\/[^\s]+/', $message->body))
            ->map(function (Message $message) {
                preg_match_all('/https?:\/\/[^\s]+/', $message->body, $matches);
                $message->links = $matches[0] ?? [];

                return $message;
            })
            ->values();

        return response()->json([
            'media' => $mediaMessages,
            'docs' => $docMessages,
            'links' => $linkMessages,
        ]);
    }

    public function starred(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->users->contains($request->user()->id), 403);

        $starred = $conversation->messages()
            ->whereNotNull('starred_at')
            ->orderByDesc('starred_at')
            ->get();

        return response()->json($starred);
    }

    public function clear(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->users->contains($request->user()->id), 403);

        $conversation->messages()->delete();

        return response()->json(['message' => 'Chat history cleared.']);
    }

    private function findDirectConversation(int|string $userA, int|string $userB): ?Conversation
    {
        return Conversation::where('type', 'direct')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userA))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userB))
            ->withCount('participants')
            ->having('participants_count', 2)
            ->first();
    }
}
