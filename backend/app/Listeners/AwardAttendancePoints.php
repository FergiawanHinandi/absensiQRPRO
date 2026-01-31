<?php

namespace App\Listeners;

use App\Events\StudentAttended;
use App\Services\GamificationService;

class AwardAttendancePoints
{
    protected $gamificationService;

    /**
     * Create the event listener.
     */
    public function __construct(GamificationService $gamificationService)
    {
        $this->gamificationService = $gamificationService;
    }

    /**
     * Handle the event.
     */
    public function handle(StudentAttended $event): void
    {
        if ($event->attendance) {
            $this->gamificationService->awardDailyPoints($event->attendance);
        }
    }
}
