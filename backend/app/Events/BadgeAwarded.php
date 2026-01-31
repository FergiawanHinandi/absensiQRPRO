<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BadgeAwarded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;
    public $badgeName;
    public $badgeSlug;

    /**
     * Create a new event instance.
     */
    public function __construct($user, $badgeName, $badgeSlug)
    {
        $this->user = $user;
        $this->badgeName = $badgeName;
        $this->badgeSlug = $badgeSlug;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel-name'),
        ];
    }
}
