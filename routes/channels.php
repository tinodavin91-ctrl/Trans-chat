<?php

use App\Services\FirestoreService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    return app(FirestoreService::class)->userIsParticipant($conversationId, $user->id);
});

Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (string) $user->id === (string) $userId;
});

Broadcast::channel('presence.online', function ($user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
        'hide_online_status' => (bool) $user->hide_online_status,
    ];
});
