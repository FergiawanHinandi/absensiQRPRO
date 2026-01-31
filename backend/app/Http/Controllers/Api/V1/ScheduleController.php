<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    /**
     * Get today's schedules for authenticated teacher
     */
    public function today(Request $request)
    {
        $dayOfWeek = now()->dayOfWeek;
        $schedules = Schedule::where('school_id', $request->user()->school_id)
            ->where('teacher_id', $request->user()->id)
            ->where('day_of_week', $dayOfWeek)
            ->with('subject', 'class')
            ->orderBy('start_time')
            ->get();

        return response()->success([
            'schedules' => $schedules,
        ]);
    }

    /**
     * Get all schedules for authenticated teacher
     */
    public function index(Request $request)
    {
        $schedules = Schedule::where('school_id', $request->user()->school_id)
            ->where('teacher_id', $request->user()->id)
            ->with('subject', 'class')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->success([
            'schedules' => $schedules,
        ]);
    }
}
