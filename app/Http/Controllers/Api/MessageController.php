<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageReactionUpdated;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    public function __construct(private FirestoreService $firestore)
    {
    }

    public function index(Request $request, string $conversation)
    {
        $this->firestore->get('conversations', $conversation);
        abort_unless($this->firestore->userIsParticipant($conversation, $request->user()->id), 403);

        return response()->json(
            $this->firestore->messagesForConversation($conversation)->values()
        );
    }

    public function store(Request $request, string $conversationId)
    {
        $this->firestore->get('conversations', $conversationId);
        abort_unless($this->firestore->userIsParticipant($conversationId, $request->user()->id), 403);

        $data = [
            'conversation_id' => $conversationId,
            'sender_id' => $request->user()->id,
            'body' => $request->input('body'),
            'attachment_path' => null,
            'attachment_type' => null,
            'type' => 'text',
            'sticker_id' => null,
            'read_at' => null,
            'starred_at' => null,
        ];

        if ($request->filled('sticker_id')) {
            $sticker = $this->firestore->find('stickers', $request->input('sticker_id'));
            if (! $sticker) {
                return response()->json(['message' => 'Sticker not found.'], 422);
            }

            $data['sticker_id'] = $request->input('sticker_id');
            $data['type'] = 'sticker';
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('attachments', 'public');

            $data['attachment_path'] = $path;
            $data['attachment_type'] = str_starts_with((string) $file->getMimeType(), 'audio')
                ? 'audio'
                : (str_starts_with((string) $file->getMimeType(), 'image') ? 'image' : 'file');
            $data['type'] = $data['attachment_type'];
        }

        $message = $this->firestore->hydrateMessage(
            $this->firestore->create('messages', $data)
        );
        $message['conversation'] = [
            'id' => $conversationId,
            'users' => $this->firestore->conversationUsers($conversationId)->values()->all(),
        ];

        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message);
    }

    public function uploadAttachment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:20480',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $file = $request->file('file');
        $path = $file->store('attachments', 'public');
        $mime = (string) $file->getMimeType();

        $type = 'file';
        if (str_starts_with($mime, 'image/')) {
            $type = 'image';
        } elseif (str_starts_with($mime, 'audio/')) {
            $type = 'audio';
        }

        return response()->json([
            'url' => Storage::url($path),
            'name' => $file->getClientOriginalName(),
            'type' => $type,
        ]);
    }

    public function toggleStar(Request $request, string $message)
    {
        $document = $this->firestore->get('messages', $message);
        abort_unless(
            $this->firestore->userIsParticipant($document['conversation_id'], $request->user()->id),
            403
        );

        $updated = $this->firestore->update('messages', $message, [
            'starred_at' => empty($document['starred_at']) ? $this->firestore->now() : null,
        ]);

        return response()->json($this->firestore->hydrateMessage($updated));
    }

    public function toggleReaction(Request $request, string $message)
    {
        $validated = $request->validate([
            'emoji' => 'required|string|max:8',
        ]);

        $document = $this->firestore->get('messages', $message);
        abort_unless(
            $this->firestore->userIsParticipant($document['conversation_id'], $request->user()->id),
            403
        );

        $existing = $this->firestore->where('message_reactions', 'message_id', '=', $message)
            ->first(fn (array $reaction) => ($reaction['user_id'] ?? null) === $request->user()->id);

        if ($existing && ($existing['emoji'] ?? null) === $validated['emoji']) {
            $this->firestore->delete('message_reactions', $existing['id']);
        } elseif ($existing) {
            $this->firestore->update('message_reactions', $existing['id'], [
                'emoji' => $validated['emoji'],
            ]);
        } else {
            $this->firestore->create('message_reactions', [
                'message_id' => $message,
                'user_id' => $request->user()->id,
                'emoji' => $validated['emoji'],
            ]);
        }

        $hydrated = $this->firestore->hydrateMessage(
            $this->firestore->get('messages', $message)
        );

        broadcast(new MessageReactionUpdated($hydrated))->toOthers();

        return response()->json($hydrated);
    }
}
