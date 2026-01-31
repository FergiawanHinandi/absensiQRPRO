<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentAttended implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public $attendance,
        public $schoolId
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('school.'.$this->schoolId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->attendance->id,
            'student_id' => $this->attendance->student_id,
            'status' => $this->attendance->status,
            'check_in_time' => $this->attendance->check_in_time,
            'student_name' => $this->attendance->student->name ?? 'Unknown',
            'class_name' => $this->attendance->schedule->class->name ?? 'Unknown',
        ];
    }
}
