<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $message;

    public function __construct(array $message)
    {
        $this->message = $message;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('conversation.'.$this->message['conversation_id']),
        ];

        $participantIds = collect($this->message['conversation']['users'] ?? [])
            ->pluck('id')
            ->filter()
            ->all();

        if ($participantIds === [] && isset($this->message['conversation_id'])) {
            $participantIds = app(\App\Services\FirestoreService::class)
                ->conversationParticipants($this->message['conversation_id'])
                ->pluck('user_id')
                ->filter()
                ->all();
        }

        foreach ($participantIds as $participantId) {
            $channels[] = new PrivateChannel('user.'.$participantId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'conversation_id' => $this->message['conversation_id'],
        ];
    }
}
