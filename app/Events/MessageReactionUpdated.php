<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReactionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $message)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message['conversation_id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.reaction.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message['id'],
            'conversation_id' => $this->message['conversation_id'],
            'reactions' => collect($this->message['reactions'] ?? [])->map(function ($reaction) {
                return [
                    'id' => $reaction['id'] ?? null,
                    'emoji' => $reaction['emoji'] ?? null,
                    'user_id' => $reaction['user_id'] ?? null,
                    'user_name' => $reaction['user']['name'] ?? null,
                ];
            })->values()->all(),
        ];
    }
}
