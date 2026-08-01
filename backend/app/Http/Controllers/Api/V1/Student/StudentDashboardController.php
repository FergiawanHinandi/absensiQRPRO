<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentDashboardController extends Controller
{
    /**
     * Get student dashboard overview (alias for routes using 'index')
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        return $this->dashboard();
    }

    /**
     * Get student dashboard overview
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function dashboard()
    {
        $student = Auth::user();
        $tz = $student?->school?->timezone ?? config('app.timezone');
        $today = Carbon::now($tz)->startOfDay();
        $currentMonth = Carbon::now($tz)->startOfMonth();

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

        // Today's schedule count (school timezone for day_of_week)
        $todayScheduleCount = DB::table('schedules')
            ->join('classes', 'schedules.class_id', '=', 'classes.id')
            ->join('users', 'users.class_id', '=', 'classes.id')
            ->where('users.id', $student->id)
            ->where('schedules.day_of_week', Carbon::now($tz)->dayOfWeek)
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
     * OPTIMIZED: Converted from raw DB queries to Eloquent with eager loading
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function history()
    {
        $student = Auth::user();
        $tz = $student?->school?->timezone ?? config('app.timezone');
        $thirtyDaysAgo = Carbon::now($tz)->subDays(30);

        // OPTIMIZATION: Use Eloquent with eager loading instead of raw joins
        $history = \App\Models\AttendanceLog::with([
            'schedule:id,subject_id,teacher_id,start_time,end_time',
            'schedule.subject:id,name,code',
            'schedule.teacher:id,name'
        ])
            ->where('student_id', $student->id)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($record) {
                return [
                    'date' => Carbon::parse($record->created_at)->format('Y-m-d'),
                    'time' => Carbon::parse($record->created_at)->format('H:i'),
                    'status' => $record->status,
                    'subject' => [
                        'name' => $record->schedule->subject->name ?? null,
                        'code' => $record->schedule->subject->code ?? null,
                    ],
                    'teacher' => $record->schedule->teacher->name ?? null,
                    'schedule' => [
                        'start_time' => $record->schedule->start_time ?? null,
                        'end_time' => $record->schedule->end_time ?? null,
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $thirtyDaysAgo->format('Y-m-d'),
                    'to' => Carbon::now($tz)->format('Y-m-d'),
                ],
                'total_records' => $history->count(),
                'history' => $history,
            ],
        ]);
    }

    /**
     * Get student's today schedule
     * OPTIMIZED: Converted from raw DB queries to Eloquent with eager loading
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function schedule()
    {
        $student = Auth::user();
        $tz = $student?->school?->timezone ?? config('app.timezone');
        $today = Carbon::now($tz)->startOfDay();

        // OPTIMIZATION: Use Eloquent with eager loading and whereHas for filtering
        $schedule = \App\Models\Schedule::with([
            'class:id,name',
            'subject:id,name,code',
            'teacher:id,name'
        ])
            ->whereHas('class.students', fn($q) => $q->where('users.id', $student->id))
            ->where('day_of_week', $today->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->map(function ($item) use ($today, $tz) {
                $startTime = Carbon::parse($today->format('Y-m-d').' '.$item->start_time, $tz);
                $endTime = Carbon::parse($today->format('Y-m-d').' '.$item->end_time, $tz);
                $now = Carbon::now($tz);

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
                        'name' => $item->subject->name ?? null,
                        'code' => $item->subject->code ?? null,
                    ],
                    'teacher' => $item->teacher->name ?? null,
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
     * OPTIMIZED: Converted from raw DB queries to Eloquent with eager loading
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile()
    {
        $student = Auth::user();

        // OPTIMIZATION: Use Eloquent with eager loading instead of raw joins
        $profile = \App\Models\User::with([
            'class:id,name,grade_id',
            'class.grade:id,name',
            'school:id,name'
        ])
            ->where('id', $student->id)
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
                    'name' => $profile->class->name ?? null,
                    'grade' => $profile->class->grade->name ?? null,
                ],
                'school' => $profile->school->name ?? null,
            ],
        ]);
    }

    /**
     * Get today's schedule timeline for student
     * Route alias for '/today-schedule'
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTodayTimeline()
    {
        return $this->schedule();
    }

    /**
     * Get monthly attendance summary for student
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMonthlySummary()
    {
        $student = Auth::user();
        $tz = $student?->school?->timezone ?? config('app.timezone');
        $month = request('month', Carbon::now($tz)->month);
        $year = request('year', Carbon::now($tz)->year);
        $startOfMonth = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $stats = DB::table('attendances')
            ->where('student_id', $student->id)
            ->whereBetween('attendance_date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->select(
                DB::raw('COUNT(*) as total_days'),
                DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days"),
                DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days"),
                DB::raw("SUM(CASE WHEN status IN ('absent', 'alpha') THEN 1 ELSE 0 END) as absent_days"),
                DB::raw("SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick_days"),
                DB::raw("SUM(CASE WHEN status = 'permit' THEN 1 ELSE 0 END) as permit_days")
            )
            ->first();

        $attendanceRate = 0;
        if ($stats && $stats->total_days > 0) {
            $attendanceRate = round((($stats->present_days + $stats->late_days) / $stats->total_days) * 100, 1);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $startOfMonth->format('Y-m'),
                'month_name' => $startOfMonth->isoFormat('MMMM Y'),
                'attendance_rate' => $attendanceRate,
                'total_days' => $stats->total_days ?? 0,
                'present_days' => $stats->present_days ?? 0,
                'late_days' => $stats->late_days ?? 0,
                'absent_days' => $stats->absent_days ?? 0,
                'sick_days' => $stats->sick_days ?? 0,
                'permit_days' => $stats->permit_days ?? 0,
            ],
        ]);
    }

    /**
     * Get student notifications
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function notifications()
    {
        $student = Auth::user();

        $notifications = DB::table('notifications')
            ->where('notifiable_id', $student->id)
            ->where('notifiable_type', 'App\\Models\\User')
            ->orderByDesc('created_at')
            ->limit(request('limit', 20))
            ->get()
            ->map(function ($notification) {
                $data = json_decode($notification->data, true) ?? [];

                return [
                    'id' => $notification->id,
                    'type' => $data['type'] ?? 'general',
                    'title' => $data['title'] ?? 'Notifikasi',
                    'message' => $data['message'] ?? '',
                    'is_read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $notifications->count(),
                'notifications' => $notifications,
            ],
        ]);
    }

    /**
     * Get gamification stats (points, badges, streaks)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getGamificationStats()
    {
        $student = Auth::user();
        $tz = $student?->school?->timezone ?? config('app.timezone');

        // Current streak from User model (managed by GamificationService)
        $streak = $student->current_streak ?? 0;

        // Monthly attendance count (school timezone month boundary)
        $monthlyPresent = DB::table('attendances')
            ->where('student_id', $student->id)
            ->where('attendance_date', '>=', Carbon::now($tz)->startOfMonth()->toDateString())
            ->whereIn('status', ['present', 'late'])
            ->count();

        // Total points from User model
        $totalPoints = $student->total_points ?? 0;

        // Fetch earned badges
        $badges = DB::table('student_badges')
            ->join('badges', 'student_badges.badge_id', '=', 'badges.id')
            ->where('student_badges.student_id', $student->id)
            ->select('badges.name', 'badges.slug', 'badges.description', 'student_badges.awarded_at')
            ->orderBy('student_badges.awarded_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'points' => $totalPoints,
                'streak' => $streak,
                'monthly_present' => $monthlyPresent,
                'badges' => $badges,
                'level' => $this->calculateLevel($totalPoints),
            ],
        ]);
    }

    /**
     * Claim reward certificate
     *
     * Validates eligibility based on attendance rate (min 80%)
     * and generates a gold attendance certificate via CertificateService.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function claimRewardCertificate()
    {
        $student = Auth::user();
        $rewardType = request('reward_type', 'attendance_certificate');
        $tz = $student?->school?->timezone ?? config('app.timezone');

        // Check eligibility based on attendance rate (last 30 days)
        $thirtyDaysAgo = Carbon::now($tz)->subDays(30)->startOfDay();
        $stats = DB::table('attendances')
            ->where('student_id', $student->id)
            ->where('attendance_date', '>=', $thirtyDaysAgo->toDateString())
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present")
            )
            ->first();

        $rate = ($stats && $stats->total > 0)
            ? round(($stats->present / $stats->total) * 100, 1)
            : 0;

        if ($rate < 80) {
            return response()->json([
                'success' => false,
                'message' => 'Tingkat kehadiran belum memenuhi syarat (minimal 80%)',
                'data' => ['current_rate' => $rate],
            ], 422);
        }

        // Generate certificate via CertificateService
        try {
            $certificateService = app(CertificateService::class);
            $semester = Carbon::now($tz)->month <= 6 ? 'Genap' : 'Ganjil';
            $year = Carbon::now($tz)->year;

            $certificate = $certificateService->generateGoldCertificate(
                $student,
                $semester,
                $year,
                $rate
            );

            return response()->json([
                'success' => true,
                'message' => 'Sertifikat penghargaan berhasil dibuat!',
                'data' => [
                    'certificate_id' => $certificate->id,
                    'certificate_code' => $certificate->certificate_code,
                    'reward_type' => $rewardType,
                    'student_name' => $student->name,
                    'attendance_rate' => $rate,
                    'semester' => $semester,
                    'year' => $year,
                    'file_url' => url("storage/{$certificate->file_path}"),
                    'claimed_at' => Carbon::now($tz)->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Certificate generation failed', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat sertifikat. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * Calculate gamification level from points
     */
    private function calculateLevel(int $points): array
    {
        $levels = [
            ['name' => 'Pemula', 'min' => 0],
            ['name' => 'Rajin', 'min' => 100],
            ['name' => 'Teladan', 'min' => 500],
            ['name' => 'Bintang', 'min' => 1000],
            ['name' => 'Juara', 'min' => 2500],
        ];

        $current = $levels[0];
        $next = $levels[1] ?? null;

        foreach ($levels as $i => $level) {
            if ($points >= $level['min']) {
                $current = $level;
                $next = $levels[$i + 1] ?? null;
            }
        }

        return [
            'name' => $current['name'],
            'points_to_next' => $next ? max(0, $next['min'] - $points) : 0,
            'next_level' => $next['name'] ?? null,
        ];
    }
}
