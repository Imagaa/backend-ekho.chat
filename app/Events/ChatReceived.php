<?php

namespace App\Events;

use App\Models\Chat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat;

    public function __construct(Chat $chat)
    {
        $this->chat = $chat;
    }

    /**
     * Tentukan channel broadcast. 
     * Menggunakan PrivateChannel agar hanya user dalam tenant yang sama yang menerima.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.' . $this->chat->tenant_id . '.chats'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->chat->id,
            'customer_phone' => $this->chat->customer_phone,
            'message' => $this->chat->message,
            'direction' => $this->chat->direction,
            'created_at' => $this->chat->created_at->toIso8601String(),
        ];
    }
}