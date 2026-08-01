<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function __construct(private \App\Services\TeacherScheduleService $teacherScheduleService) {}

    /**
     * Get today's schedules for authenticated teacher
     */
    public function today(Request $request)
    {
        $schedules = $this->teacherScheduleService->getTodaySchedules($request->user());

        return response()->success([
            'schedules' => $schedules,
        ]);
    }

    /**
     * Get all schedules for authenticated teacher
     */
    public function index(Request $request)
    {
        // Using getWeeklySchedules which is optimized and grouping aware
        $groupedSchedules = $this->teacherScheduleService->getWeeklySchedules($request->user());

        return response()->success([
            'schedules' => current($groupedSchedules) !== false ? $groupedSchedules : [],
        ]);
    }
}
