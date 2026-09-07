<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageReactionUpdated;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Sticker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    public function index(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->users->contains($request->user()->id), 403);

        return response()->json(
            $conversation->messages()->with(['sender', 'reactions', 'sticker'])->get()
        );
    }

    public function store(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->users->contains($request->user()->id), 403);

        $data = [
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'body' => $request->input('body'),
            'attachment_path' => null,
            'attachment_type' => null,
            'type' => 'text',
            'sticker_id' => null,
        ];

        if ($request->filled('sticker_id')) {
            $sticker = Sticker::find($request->input('sticker_id'));
            if (! $sticker) {
                return response()->json(['message' => 'Sticker not found.'], 422);
            }

            $data['sticker_id'] = $sticker->id;
            $data['type'] = 'sticker';
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('attachments', 'public');
            $mime = (string) $file->getMimeType();

            $data['attachment_path'] = $path;
            $data['attachment_type'] = str_starts_with($mime, 'audio')
                ? 'audio'
                : (str_starts_with($mime, 'image') ? 'image' : 'file');
            $data['type'] = $data['attachment_type'];
        }

        $message = Message::create($data)->load(['sender', 'reactions', 'sticker']);
        $message->setRelation('conversation', $conversation->load('users'));

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

    public function toggleStar(Request $request, Message $message)
    {
        abort_unless($message->conversation->users->contains($request->user()->id), 403);

        $message->update([
            'starred_at' => $message->starred_at ? null : now(),
        ]);

        return response()->json($message->load(['sender', 'reactions', 'sticker']));
    }

    public function toggleReaction(Request $request, Message $message)
    {
        $validated = $request->validate([
            'emoji' => 'required|string|max:8',
        ]);

        abort_unless($message->conversation->users->contains($request->user()->id), 403);

        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existing && $existing->emoji === $validated['emoji']) {
            $existing->delete();
        } elseif ($existing) {
            $existing->update(['emoji' => $validated['emoji']]);
        } else {
            MessageReaction::create([
                'message_id' => $message->id,
                'user_id' => $request->user()->id,
                'emoji' => $validated['emoji'],
            ]);
        }

        $message->load(['sender', 'reactions', 'sticker']);

        broadcast(new MessageReactionUpdated($message))->toOthers();

        return response()->json($message);
    }
}
