<?php

namespace App\Events;

use App\Models\ContactImport;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImportProgressUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $contactImport;

    public function __construct(ContactImport $contactImport)
    {
        $this->contactImport = $contactImport;
    }

    /**
     * PrivateChannel per-tenant, sama pola dengan ChatReceived.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.' . $this->contactImport->tenant_id . '.imports'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->contactImport->id,
            'status' => $this->contactImport->status,
            'imported_count' => $this->contactImport->imported_count,
            'skipped_count' => $this->contactImport->skipped_count,
            'error_message' => $this->contactImport->error_message,
        ];
    }
}
