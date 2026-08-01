<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceRecentController extends Controller
{
    /**
     * GET /api/v1/attendance/recent
     *
     * Returns the most recent attendance records for the live feed.
     * Scoped to the user's school. For teachers, scoped to their own schedules.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $limit = min((int) $request->query('limit', 8), 50);

        $query = DB::table('attendances')
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->leftJoin('classes', 'schedules.class_id', '=', 'classes.id')
            ->where('attendances.school_id', $schoolId)
            ->where('attendances.attendance_date', Carbon::now()->toDateString());

        // For teachers: scope to their own schedules
        if (in_array($user->role_type, ['teacher', 'homeroom_teacher'])) {
            $query->where('schedules.teacher_id', $user->id);
        }

        $records = $query
            ->select(
                'attendances.id',
                'attendances.student_id',
                'users.name as student_name',
                'classes.name as class_name',
                'attendances.status',
                'attendances.check_in_time'
            )
            ->orderBy('attendances.created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'student_id' => (int) $item->student_id,
                    'student_name' => $item->student_name,
                    'class_name' => $item->class_name ?? 'Unknown',
                    'status' => $item->status,
                    'check_in_time' => $item->check_in_time
                        ? Carbon::parse($item->check_in_time)->format('H:i')
                        : '--:--',
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $records,
        ]);
    }
}
