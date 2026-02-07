<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentDashboardController extends Controller
{
    /**
     * Get student dashboard overview
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function dashboard()
    {
        $student = Auth::user();
        $today = Carbon::today();
        $currentMonth = Carbon::now()->startOfMonth();

        // Today's attendance status
        $todayAttendance = DB::table('attendance_logs')
            ->where('student_id', $student->id)
            ->whereDate('created_at', $today)
            ->select('status', 'created_at')
            ->first();

        // Monthly attendance statistics
        $monthlyStats = DB::table('attendance_logs')
            ->where('student_id', $student->id)
            ->where('created_at', '>=', $currentMonth)
            ->select(
                DB::raw('COUNT(*) as total_days'),
                DB::raw('SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present_days'),
                DB::raw('SUM(CASE WHEN status = "late" THEN 1 ELSE 0 END) as late_days'),
                DB::raw('SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent_days')
            )
            ->first();

        // Calculate attendance percentage
        $attendanceRate = 0;
        if ($monthlyStats && $monthlyStats->total_days > 0) {
            $attendanceRate = round(($monthlyStats->present_days / $monthlyStats->total_days) * 100, 1);
        }

        // Today's schedule count
        $todayScheduleCount = DB::table('schedules')
            ->join('classes', 'schedules.class_id', '=', 'classes.id')
            ->join('users', 'users.class_id', '=', 'classes.id')
            ->where('users.id', $student->id)
            ->where('schedules.day_of_week', Carbon::today()->dayOfWeek)
            ->where('schedules.is_active', true)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'today_status' => [
                    'date' => $today->format('Y-m-d'),
                    'attendance' => $todayAttendance ? [
                        'status' => $todayAttendance->status,
                        'time' => Carbon::parse($todayAttendance->created_at)->format('H:i'),
                    ] : null,
                    'total_subjects' => $todayScheduleCount,
                ],
                'monthly_summary' => [
                    'month' => $currentMonth->format('Y-m'),
                    'attendance_rate' => $attendanceRate,
                    'total_days' => $monthlyStats->total_days ?? 0,
                    'present_days' => $monthlyStats->present_days ?? 0,
                    'late_days' => $monthlyStats->late_days ?? 0,
                    'absent_days' => $monthlyStats->absent_days ?? 0,
                ],
            ],
        ]);
    }

    /**
     * Get student attendance history (last 30 days)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function history()
    {
        $student = Auth::user();
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $history = DB::table('attendance_logs')
            ->leftJoin('schedules', 'attendance_logs.schedule_id', '=', 'schedules.id')
            ->leftJoin('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->leftJoin('users as teachers', 'schedules.teacher_id', '=', 'teachers.id')
            ->where('attendance_logs.student_id', $student->id)
            ->where('attendance_logs.created_at', '>=', $thirtyDaysAgo)
            ->select(
                'attendance_logs.status',
                'attendance_logs.created_at',
                'subjects.name as subject_name',
                'subjects.code as subject_code',
                'teachers.name as teacher_name',
                'schedules.start_time',
                'schedules.end_time'
            )
            ->orderBy('attendance_logs.created_at', 'desc')
            ->get()
            ->map(function ($record) {
                return [
                    'date' => Carbon::parse($record->created_at)->format('Y-m-d'),
                    'time' => Carbon::parse($record->created_at)->format('H:i'),
                    'status' => $record->status,
                    'subject' => [
                        'name' => $record->subject_name,
                        'code' => $record->subject_code,
                    ],
                    'teacher' => $record->teacher_name,
                    'schedule' => [
                        'start_time' => $record->start_time,
                        'end_time' => $record->end_time,
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $thirtyDaysAgo->format('Y-m-d'),
                    'to' => Carbon::now()->format('Y-m-d'),
                ],
                'total_records' => $history->count(),
                'history' => $history,
            ],
        ]);
    }

    /**
     * Get student's today schedule
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function schedule()
    {
        $student = Auth::user();
        $today = Carbon::today();

        $schedule = DB::table('schedules')
            ->join('classes', 'schedules.class_id', '=', 'classes.id')
            ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->join('users as teachers', 'schedules.teacher_id', '=', 'teachers.id')
            ->join('users', 'users.class_id', '=', 'classes.id')
            ->where('users.id', $student->id)
            ->where('schedules.day_of_week', $today->dayOfWeek)
            ->where('schedules.is_active', true)
            ->select(
                'schedules.id',
                'schedules.start_time',
                'schedules.end_time',
                'schedules.room',
                'subjects.name as subject_name',
                'subjects.code as subject_code',
                'teachers.name as teacher_name',
                'classes.name as class_name'
            )
            ->orderBy('schedules.start_time')
            ->get()
            ->map(function ($item) use ($today) {
                $startTime = Carbon::parse($today->format('Y-m-d').' '.$item->start_time);
                $endTime = Carbon::parse($today->format('Y-m-d').' '.$item->end_time);
                $now = Carbon::now();

                // Determine schedule status
                $status = 'upcoming';
                if ($now->between($startTime, $endTime)) {
                    $status = 'ongoing';
                } elseif ($now->gt($endTime)) {
                    $status = 'completed';
                }

                return [
                    'id' => $item->id,
                    'subject' => [
                        'name' => $item->subject_name,
                        'code' => $item->subject_code,
                    ],
                    'teacher' => $item->teacher_name,
                    'time' => [
                        'start' => $item->start_time,
                        'end' => $item->end_time,
                    ],
                    'room' => $item->room,
                    'status' => $status,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $today->format('Y-m-d'),
                'day_name' => $today->format('l'),
                'total_subjects' => $schedule->count(),
                'schedule' => $schedule,
            ],
        ]);
    }

    /**
     * Get student profile information
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile()
    {
        $student = Auth::user();

        $profile = DB::table('users')
            ->leftJoin('classes', 'users.class_id', '=', 'classes.id')
            ->leftJoin('grades', 'classes.grade_id', '=', 'grades.id')
            ->leftJoin('schools', 'users.school_id', '=', 'schools.id')
            ->where('users.id', $student->id)
            ->select(
                'users.name',
                'users.username',
                'users.email',
                'users.phone',
                'users.photo_url',
                'classes.name as class_name',
                'grades.name as grade_name',
                'schools.name as school_name'
            )
            ->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $profile->name,
                'username' => $profile->username,
                'email' => $profile->email,
                'phone' => $profile->phone,
                'photo_url' => $profile->photo_url,
                'class' => [
                    'name' => $profile->class_name,
                    'grade' => $profile->grade_name,
                ],
                'school' => $profile->school_name,
            ],
        ]);
    }
}
