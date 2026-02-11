<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Services\TeacherScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Controller for teacher schedule operations
 * 
 * Endpoints:
 * - GET /api/v1/teacher/schedules - Get weekly schedules
 * - GET /api/v1/teacher/schedules/today - Get today's schedules
 * - GET /api/v1/teacher/schedules/{id} - Get single schedule
 */
class TeacherScheduleController extends Controller
{
    public function __construct(
        private TeacherScheduleService $scheduleService
    ) {}

    /**
     * Get teacher's weekly schedules
     * 
     * @param Request $request
     * @return JsonResponse
     * 
     * @queryParam week string ISO week format (e.g., "2026-W06"). Defaults to current week.
     * 
     * @response 200 {
     *   "status": true,
     *   "message": "Teacher schedules retrieved",
     *   "data": {
     *     "week": "2026-W06",
     *     "schedules": {
     *       "1": [{ "id": 1, "day_name": "Senin", ... }],
     *       "2": [{ "id": 2, "day_name": "Selasa", ... }]
     *     }
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user();

        // Policy check: teacher can only view their own schedules
        // This is enforced by the service querying only teacher_id = user.id
        if (!in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized - Only teachers can access this endpoint',
            ], 403);
        }

        $week = $request->query('week');
        
        $schedules = $this->scheduleService->getWeeklySchedules($teacher, $week);

        return response()->json([
            'status' => true,
            'message' => 'Teacher schedules retrieved',
            'data' => [
                'week' => $week ?? now()->format('Y-\WW'),
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->name,
                'schedules' => $schedules,
            ],
        ]);
    }

    /**
     * Get today's schedules for teacher (TIMEZONE-AWARE)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function today(Request $request): JsonResponse
    {
        $teacher = $request->user();

        if (!in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // Get school timezone
        $schoolTimezone = $teacher->school->timezone ?? 'Asia/Jakarta';
        $now = \Carbon\Carbon::now($schoolTimezone);

        $schedules = $this->scheduleService->getTodaySchedules($teacher);

        return response()->json([
            'status' => true,
            'message' => 'Today\'s schedules retrieved',
            'data' => [
                'date' => $now->toDateString(),
                'day_name' => $now->locale('id')->dayName,
                'day_of_week' => $now->dayOfWeek,
                'timezone' => $schoolTimezone,
                'current_time' => $now->format('H:i:s'),
                'schedules' => $schedules,
            ],
        ]);
    }

    /**
     * Get single schedule by ID
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $teacher = $request->user();

        if (!in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $schedule = $this->scheduleService->getScheduleById($teacher, $id);

        if (!$schedule) {
            return response()->json([
                'status' => false,
                'message' => 'Schedule not found or not authorized',
            ], 404);
        }

        // Additional policy check via Gate
        if (Gate::denies('view', $schedule)) {
            return response()->json([
                'status' => false,
                'message' => 'Not authorized to view this schedule',
            ], 403);
        }

        return response()->json([
            'status' => true,
            'message' => 'Schedule retrieved',
            'data' => $schedule,
        ]);
    }
}
