<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScheduleChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public $schedule,
        public $schoolId
    ) {}
}
