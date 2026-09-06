<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageRead;
use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    public function __construct(private FirestoreService $firestore)
    {
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $conversationIds = $this->firestore
            ->where('conversation_participants', 'user_id', '=', $userId)
            ->pluck('conversation_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $conversations = $this->firestore->whereIn('conversations', 'id', $conversationIds)
            ->map(function (array $conversation) use ($userId) {
                $loaded = $this->firestore->loadConversation($conversation);
                $loaded['unread_count'] = $this->firestore->unreadCount($conversation['id'], $userId);

                return $loaded;
            })
            ->values();

        return response()->json($conversations);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:direct,group',
            'name' => 'required_if:type,group|nullable|string|max:255',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        foreach ($request->user_ids as $userId) {
            if (! $this->firestore->find('users', $userId)) {
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
                return response()->json($this->firestore->loadConversation($existing));
            }
        }

        $conversation = $this->firestore->create('conversations', [
            'type' => $request->type,
            'name' => $request->type === 'group' ? $request->name : null,
            'created_by' => $authUserId,
        ]);

        foreach ($participantIds as $participantId) {
            $this->firestore->create('conversation_participants', [
                'conversation_id' => $conversation['id'],
                'user_id' => $participantId,
                'last_read_at' => null,
            ]);
        }

        return response()->json($this->firestore->loadConversation($conversation), 201);
    }

    public function show(Request $request, string $conversation)
    {
        $document = $this->firestore->get('conversations', $conversation);
        abort_unless($this->firestore->userIsParticipant($conversation, $request->user()->id), 403);

        return response()->json($this->firestore->loadConversation($document, withMessages: true));
    }

    public function markRead(Request $request, string $conversation)
    {
        $userId = $request->user()->id;
        $this->firestore->get('conversations', $conversation);
        abort_unless($this->firestore->userIsParticipant($conversation, $userId), 403);

        $messageIds = [];

        foreach ($this->firestore->where('messages', 'conversation_id', '=', $conversation) as $message) {
            if (($message['sender_id'] ?? null) === $userId || ! empty($message['read_at'])) {
                continue;
            }

            $this->firestore->update('messages', $message['id'], [
                'read_at' => $this->firestore->now(),
            ]);
            $messageIds[] = $message['id'];
        }

        if ($messageIds === []) {
            return response()->json(['message' => 'Nothing to mark as read.']);
        }

        broadcast(new MessageRead($conversation, $messageIds, $userId))->toOthers();

        return response()->json(['message_ids' => $messageIds]);
    }

    public function typing(Request $request, string $conversation)
    {
        $this->firestore->get('conversations', $conversation);
        abort_unless($this->firestore->userIsParticipant($conversation, $request->user()->id), 403);

        broadcast(new UserTyping($conversation, $request->user()))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    public function media(Request $request, string $conversation)
    {
        $this->firestore->get('conversations', $conversation);
        abort_unless($this->firestore->userIsParticipant($conversation, $request->user()->id), 403);

        $messages = $this->firestore->messagesForConversation($conversation)->sortByDesc('created_at')->values();

        $mediaMessages = $messages
            ->filter(fn (array $message) => in_array($message['attachment_type'] ?? null, ['image', 'audio'], true))
            ->values();

        $docMessages = $messages
            ->filter(fn (array $message) => ($message['attachment_type'] ?? null) === 'file')
            ->values();

        $linkMessages = $messages
            ->filter(fn (array $message) => ! empty($message['body']) && preg_match('/https?:\/\/[^\s]+/', $message['body']))
            ->map(function (array $message) {
                preg_match_all('/https?:\/\/[^\s]+/', $message['body'], $matches);
                $message['links'] = $matches[0] ?? [];

                return $message;
            })
            ->values();

        return response()->json([
            'media' => $mediaMessages,
            'docs' => $docMessages,
            'links' => $linkMessages,
        ]);
    }

    public function starred(Request $request, string $conversation)
    {
        $this->firestore->get('conversations', $conversation);
        abort_unless($this->firestore->userIsParticipant($conversation, $request->user()->id), 403);

        $starred = $this->firestore->messagesForConversation($conversation)
            ->filter(fn (array $message) => ! empty($message['starred_at']))
            ->sortByDesc('starred_at')
            ->values();

        return response()->json($starred);
    }

    public function clear(Request $request, string $conversation)
    {
        $this->firestore->get('conversations', $conversation);
        abort_unless($this->firestore->userIsParticipant($conversation, $request->user()->id), 403);

        $this->firestore->deleteWhere('messages', 'conversation_id', $conversation);

        return response()->json(['message' => 'Chat history cleared.']);
    }

    private function findDirectConversation(string $userA, string $userB): ?array
    {
        $conversations = $this->firestore->where('conversations', 'type', '=', 'direct');

        foreach ($conversations as $conversation) {
            $participantIds = $this->firestore->conversationParticipants($conversation['id'])
                ->pluck('user_id')
                ->sort()
                ->values()
                ->all();

            $expected = collect([$userA, $userB])->sort()->values()->all();

            if ($participantIds === $expected) {
                return $conversation;
            }
        }

        return null;
    }
}
