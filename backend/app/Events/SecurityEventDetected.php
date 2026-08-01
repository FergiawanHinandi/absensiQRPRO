<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityEventDetected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public array $eventData
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        // Broadcast to super admins via system health channel (global)
        $channels[] = new PrivateChannel('system.health');

        // If school context is available, broadcast to school admin security channel
        if (isset($this->eventData['school_id'])) {
            $channels[] = new PrivateChannel('admin.security.' . $this->eventData['school_id']);
        }

        return $channels;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'security_event',
            'event_type' => $this->eventData['type'] ?? 'unknown',
            'severity' => $this->eventData['severity'] ?? 'info',
            'message' => $this->getEventMessage(),
            'timestamp' => now()->toIso8601String(),
            'event_id' => uniqid('sec_', true),
        ];
    }

    /**
     * Get human-readable message for the event.
     */
    private function getEventMessage(): string
    {
        return match ($this->eventData['type'] ?? '') {
            'brute_force_attack' => 'Serangan brute force terdeteksi: ' .
                ($this->eventData['attempts'] ?? 'banyak') . ' percobaan gagal untuk ' .
                ($this->eventData['identifier'] ?? 'unknown'),
            'sql_injection_attempt' => 'Percobaan SQL Injection terdeteksi',
            'xss_attempt' => 'Percobaan XSS terdeteksi',
            'unauthorized_access' => 'Percobaan akses tidak sah',
            'token_compromise' => 'Kemungkinan kompromi token terdeteksi',
            default => 'Event keamanan: ' . ($this->eventData['type'] ?? 'unknown'),
        };
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'security.event';
    }
}
