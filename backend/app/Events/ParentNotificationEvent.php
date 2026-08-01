<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ParentNotificationEvent
 *
 * Broadcasts real-time attendance notification to parent's private channel.
 * Parent frontend subscribes to `parent.{parentId}` via Echo to receive
 * instant updates when their child checks in, is late, or marked absent.
 *
 * Usage:
 *   event(new ParentNotificationEvent($parent, $student, 'present', 'Hadir ✅', '...'));
 */
class ParentNotificationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The parent user receiving the notification
     */
    public User $parent;

    /**
     * The student who checked in
     */
    public array $studentInfo;

    /**
     * Notification payload
     */
    public array $payload;

    /**
     * Create a new event instance.
     */
    public function __construct(User $parent, array $studentInfo, string $type, string $title, string $message, array $extraData = [])
    {
        $this->parent = $parent;
        $this->studentInfo = $studentInfo;
        $this->payload = [
            'id' => uniqid('notif_', true),
            'type' => $type, // 'check_in', 'late', 'absent', 'alert'
            'title' => $title,
            'message' => $message,
            'student_id' => $studentInfo['id'],
            'student_name' => $studentInfo['name'],
            'timestamp' => now()->toIso8601String(),
            'time_ago' => 'Baru saja',
            'read' => false,
            'data' => $extraData,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("parent.{$this->parent->id}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'parent.notification';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
