<?php

namespace App\Events;

use App\Models\StudentAttendanceRisk;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RiskLevelUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public StudentAttendanceRisk $riskProfile) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->riskProfile->student_id),
        ];
    }
}
