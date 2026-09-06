<?php

namespace App\Services;

use App\Models\User;
use App\Support\FirestoreDocument;
use Carbon\Carbon;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kreait\Laravel\Firebase\Facades\Firebase;

class FirestoreService
{
    public function client(): FirestoreClient
    {
        return Firebase::firestore()->database();
    }

    public function collection(string $name)
    {
        return $this->client()->collection($name);
    }

    public function find(string $collection, string $id): ?array
    {
        $snapshot = $this->collection($collection)->document($id)->snapshot();

        if (! $snapshot->exists()) {
            return null;
        }

        return $this->normalize($snapshot);
    }

    public function get(string $collection, string $id): array
    {
        $document = $this->find($collection, $id);

        if ($document === null) {
            abort(404);
        }

        return $document;
    }

    public function create(string $collection, array $data, ?string $id = null): array
    {
        $id ??= Str::uuid()->toString();
        $now = $this->now();

        $payload = array_merge($data, [
            'created_at' => $data['created_at'] ?? $now,
            'updated_at' => $data['updated_at'] ?? $now,
        ]);

        $this->collection($collection)->document($id)->set($payload);

        return array_merge(['id' => $id], $payload);
    }

    public function update(string $collection, string $id, array $data): array
    {
        $existing = $this->get($collection, $id);
        $payload = array_merge($data, [
            'updated_at' => $this->now(),
        ]);

        $this->collection($collection)->document($id)->set($payload, ['merge' => true]);

        return array_merge($existing, $payload, ['id' => $id]);
    }

    public function delete(string $collection, string $id): void
    {
        $this->collection($collection)->document($id)->delete();
    }

    public function deleteWhere(string $collection, string $field, mixed $value): void
    {
        foreach ($this->where($collection, $field, '=', $value) as $document) {
            $this->delete($collection, $document['id']);
        }
    }

    public function all(string $collection): Collection
    {
        return collect($this->collection($collection)->documents())
            ->filter(fn (DocumentSnapshot $snapshot) => $snapshot->exists())
            ->map(fn (DocumentSnapshot $snapshot) => $this->normalize($snapshot))
            ->values();
    }

    public function where(string $collection, string $field, string $operator, mixed $value): Collection
    {
        return collect($this->collection($collection)->where($field, $operator, $value)->documents())
            ->filter(fn (DocumentSnapshot $snapshot) => $snapshot->exists())
            ->map(fn (DocumentSnapshot $snapshot) => $this->normalize($snapshot))
            ->values();
    }

    public function whereIn(string $collection, string $field, array $values): Collection
    {
        if ($values === []) {
            return collect();
        }

        // Document IDs are not stored as a queryable field — fetch by reference.
        if ($field === 'id') {
            return $this->findMany($collection, $values);
        }

        return collect($values)
            ->chunk(10)
            ->flatMap(function ($chunk) use ($collection, $field) {
                return collect($this->collection($collection)
                    ->where($field, 'in', $chunk->values()->all())
                    ->documents())
                    ->filter(fn (DocumentSnapshot $snapshot) => $snapshot->exists())
                    ->map(fn (DocumentSnapshot $snapshot) => $this->normalize($snapshot));
            })
            ->values();
    }

    public function findMany(string $collection, array $ids): Collection
    {
        return collect($ids)
            ->unique()
            ->filter()
            ->map(fn (string $id) => $this->find($collection, $id))
            ->filter()
            ->values();
    }

    public function findUserByEmail(string $email): ?User
    {
        $document = $this->where('users', 'email', '=', $email)->first();

        return $document ? $this->toUser($document) : null;
    }

    public function findUser(string $id): ?User
    {
        $document = $this->find('users', $id);

        return $document ? $this->toUser($document) : null;
    }

    public function toUser(array $document): User
    {
        return new User($document['id'], $document);
    }

    public function toDocument(array $document): FirestoreDocument
    {
        return new FirestoreDocument($document['id'], $document);
    }

    public function createToken(User $user, string $name = 'auth_token'): string
    {
        $plainTextToken = Str::random(64);

        $this->create('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => ['*'],
            'last_used_at' => null,
        ]);

        return $user->id.'|'.$plainTextToken;
    }

    public function findUserByToken(string $plainTextToken): ?User
    {
        if (! str_contains($plainTextToken, '|')) {
            return null;
        }

        [$userId, $token] = explode('|', $plainTextToken, 2);
        $hashed = hash('sha256', $token);

        $accessToken = $this->where('personal_access_tokens', 'token', '=', $hashed)
            ->first(fn (array $document) => ($document['tokenable_id'] ?? null) === $userId);

        if (! $accessToken) {
            return null;
        }

        $user = $this->findUser($userId);

        if ($user) {
            $user->currentAccessTokenHash = $hashed;
        }

        return $user;
    }

    public function deleteCurrentToken(User $user): void
    {
        if (! $user->currentAccessTokenHash) {
            return;
        }

        $token = $this->where('personal_access_tokens', 'token', '=', $user->currentAccessTokenHash)->first();

        if ($token) {
            $this->delete('personal_access_tokens', $token['id']);
        }
    }

    public function deleteOtherTokens(User $user): void
    {
        $tokens = $this->where('personal_access_tokens', 'tokenable_id', '=', $user->id);

        foreach ($tokens as $token) {
            if ($user->currentAccessTokenHash && ($token['token'] ?? null) === $user->currentAccessTokenHash) {
                continue;
            }

            $this->delete('personal_access_tokens', $token['id']);
        }
    }

    public function conversationParticipants(string $conversationId): Collection
    {
        return $this->where('conversation_participants', 'conversation_id', '=', $conversationId);
    }

    public function userIsParticipant(string $conversationId, string $userId): bool
    {
        return $this->conversationParticipants($conversationId)
            ->contains(fn (array $participant) => ($participant['user_id'] ?? null) === $userId);
    }

    public function conversationUsers(string $conversationId): Collection
    {
        $userIds = $this->conversationParticipants($conversationId)
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->whereIn('users', 'id', $userIds)
            ->map(fn (array $user) => $this->publicUser($user));
    }

    public function publicUser(array|User $user): array
    {
        $data = $user instanceof User ? $user->toArray() : $user;

        return [
            'id' => $data['id'],
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'hide_online_status' => (bool) ($data['hide_online_status'] ?? false),
        ];
    }

    public function loadConversation(array $conversation, bool $withMessages = false): array
    {
        $conversation['users'] = $this->conversationUsers($conversation['id'])->values()->all();
        $conversation['latest_message'] = $this->latestMessage($conversation['id']);

        if ($withMessages) {
            $conversation['messages'] = $this->messagesForConversation($conversation['id'])->all();
        }

        return $conversation;
    }

    public function latestMessage(string $conversationId): ?array
    {
        return $this->messagesForConversation($conversationId)->sortByDesc('created_at')->first();
    }

    public function messagesForConversation(string $conversationId): Collection
    {
        return $this->where('messages', 'conversation_id', '=', $conversationId)
            ->sortBy('created_at')
            ->values()
            ->map(fn (array $message) => $this->hydrateMessage($message));
    }

    public function hydrateMessage(array $message): array
    {
        $sender = isset($message['sender_id']) ? $this->find('users', $message['sender_id']) : null;
        $message['sender'] = $sender ? $this->publicUser($sender) : null;
        $message['attachment_url'] = ! empty($message['attachment_path'])
            ? asset('storage/'.$message['attachment_path'])
            : null;

        if (! empty($message['sticker_id'])) {
            $sticker = $this->find('stickers', $message['sticker_id']);
            if ($sticker) {
                $sticker['image_url'] = url('/api/stickers/'.$sticker['id'].'/image');
            }
            $message['sticker'] = $sticker;
        } else {
            $message['sticker'] = null;
        }

        $message['reactions'] = $this->where('message_reactions', 'message_id', '=', $message['id'])
            ->map(function (array $reaction) {
                $user = $this->find('users', $reaction['user_id'] ?? '');
                $reaction['user'] = $user ? [
                    'id' => $user['id'],
                    'name' => $user['name'] ?? null,
                ] : null;

                return $reaction;
            })
            ->values()
            ->all();

        return $message;
    }

    public function unreadCount(string $conversationId, string $userId): int
    {
        return $this->where('messages', 'conversation_id', '=', $conversationId)
            ->filter(function (array $message) use ($userId) {
                return ($message['sender_id'] ?? null) !== $userId
                    && empty($message['read_at']);
            })
            ->count();
    }

    public function now(): string
    {
        return Carbon::now()->toIso8601String();
    }

    private function normalize(DocumentSnapshot $snapshot): array
    {
        $data = $snapshot->data() ?? [];
        $data['id'] = $snapshot->id();

        foreach (['created_at', 'updated_at', 'read_at', 'starred_at', 'last_read_at', 'last_used_at', 'email_verified_at'] as $field) {
            if (isset($data[$field]) && is_object($data[$field]) && method_exists($data[$field], 'get')) {
                $data[$field] = Carbon::instance($data[$field]->get())->toIso8601String();
            }
        }

        return $data;
    }
}
