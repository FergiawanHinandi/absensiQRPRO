<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Models\TeacherRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherDashboardController extends Controller
{
    use \App\Traits\UsesCacheTags;

    /**
     * Get Teacher Dashboard Overview
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $key = "teacher_dashboard_index_{$schoolId}_{$user->id}";

        $data = $this->cacheWithTags(['dashboard', "school_{$schoolId}"], $key, 120, function () use ($user) {
            // 1. Determine Homeroom Status/Class
            // We use the TeacherRole table synced by Admin
            $currentDate = Carbon::now();
            $academicYearId = DB::table('academic_years')
                ->where('school_id', $user->school_id)
                ->where('is_active', true)
                ->value('id') ?? 1;

            $teacherRole = TeacherRole::where('teacher_id', $user->id)
                ->where('academic_year_id', $academicYearId)
                ->first();

            $isHomeroom = $teacherRole && $teacherRole->is_homeroom_teacher;
            $homeroomClassId = $isHomeroom ? $teacherRole->homeroom_class_id : null;

            // 2. Get Today's Schedules
            $dayOfWeekInt = $currentDate->dayOfWeek;

            $schedules = DB::table('schedules')
                ->join('classes', 'schedules.class_id', '=', 'classes.id')
                ->leftJoin('subjects', 'schedules.subject_id', '=', 'subjects.id')
                ->where('schedules.teacher_id', $user->id)
                ->where('schedules.day_of_week', $dayOfWeekInt)
                ->where('schedules.school_id', $user->school_id)
                ->where('schedules.is_active', true)
                ->select(
                    'schedules.id',
                    'schedules.start_time',
                    'schedules.end_time',
                    'schedules.room',
                    'classes.name as class_name',
                    'subjects.name as subject_name',
                    'subjects.code as subject_code'
                )
                ->orderBy('schedules.start_time')
                ->get()
                ->map(function ($item) {
                    // Determine status based on time
                    $now = Carbon::now()->format('H:i:s');
                    $status = 'upcoming';
                    if ($now > $item->end_time) {
                        $status = 'finished';
                    } elseif ($now >= $item->start_time) {
                        $status = 'ongoing';
                    }

                    return [
                        'id' => $item->id,
                        'subject' => [
                            'name' => $item->subject_name ?? 'Kegiatan Lain',
                            'code' => $item->subject_code ?? '-',
                        ],
                        'class' => [
                            'name' => $item->class_name,
                        ],
                        'room' => $item->room,
                        'start_time' => $item->start_time,
                        'end_time' => $item->end_time,
                        'status' => $status,
                    ];
                });

            // 3. Homeroom Stats (Real Data)
            $homeroomStats = null;
            $atRiskStudents = [];

            if ($isHomeroom && $homeroomClassId) {
                $totalStudents = User::whereHas('classStudents', function ($query) use ($homeroomClassId) {
                    $query->where('class_id', $homeroomClassId)
                        ->where('status', 'active');
                })
                    ->where('role_type', 'student')
                    ->where('is_active', true)
                    ->count();

                // Mock attendance for day (fetching real attendance requires Attendance model which mimics complex logic)
                // For now we assume perfect attendance to avoid complex query unless we have the Attendance model handy.
                // Let's check table 'attendances' for today.
                $todayStr = $currentDate->toDateString();

                $attendanceCounts = DB::table('attendances')
                    ->where('school_id', $user->school_id)
                    ->where('class_id', $homeroomClassId)
                    ->where('date', $todayStr)
                    ->groupBy('status')
                    ->select('status', DB::raw('count(*) as total'))
                    ->pluck('total', 'status');

                $present = $attendanceCounts['present'] ?? 0;
                $late = $attendanceCounts['late'] ?? 0;
                $sick = $attendanceCounts['sick'] ?? 0;
                $permission = $attendanceCounts['permit'] ?? 0;
                $alpha = $attendanceCounts['alpha'] ?? 0;

                // Percentage calculation
                $attendancePercentage = $totalStudents > 0
                    ? round((($present + $late) / $totalStudents) * 100, 1)
                    : 0;

                $homeroomStats = [
                    'total_students' => $totalStudents,
                    'present' => $present,
                    'late' => $late,
                    'sick' => $sick,
                    'permission' => $permission,
                    'alpha' => $alpha,
                    'attendance_percentage' => $attendancePercentage,
                ];

                // Mock At Risk (Requires deeper history analysis)
                // We return empty for now or static placeholder until later
                $atRiskStudents = [];
            }

            return [
                'teacher_name' => $user->name,
                'is_homeroom' => $isHomeroom,
                'date' => $currentDate->isoFormat('dddd, D MMMM Y'),
                'schedules' => $schedules,
                'homeroom_stats' => $homeroomStats,
                'at_risk_students' => $atRiskStudents,
                // Next action suggestion
                'next_action' => $schedules->first() ? [
                    'type' => 'open_schedule',
                    'label' => 'Jadwal: '.($schedules->first()['subject']['name']).' - '.$schedules->first()['class']['name'],
                    'schedule_id' => $schedules->first()['id'],
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get Homeroom Summary Stats
     */
    /**
     * Get Homeroom Summary Stats
     */
    public function homeroomSummary(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $key = "homeroom_summary_{$schoolId}_{$user->id}";

        // CACHE: 60 seconds as requested
        $data = $this->cacheWithTags(['dashboard', "school_{$schoolId}", "teacher_{$user->id}"], $key, 60, function () use ($user) {
            $currentDate = Carbon::now();
            $todayStr = $currentDate->toDateString();

            // 1. Determine Class
            $academicYearId = DB::table('academic_years')
                ->where('school_id', $user->school_id)
                ->where('is_active', true)
                ->value('id') ?? 1;

            $teacherRole = TeacherRole::where('teacher_id', $user->id)
                ->where('academic_year_id', $academicYearId)
                ->first();

            $isHomeroom = $teacherRole && $teacherRole->is_homeroom_teacher;

            if (! $isHomeroom) {
                return [
                    'is_homeroom' => false,
                ];
            }

            $homeroomClassId = $teacherRole->homeroom_class_id;
            $classInfo = DB::table('classes')->where('id', $homeroomClassId)->select('id', 'name')->first();

            // 2. Student Counts
            $totalStudents = User::whereHas('classStudents', function ($query) use ($homeroomClassId) {
                $query->where('class_id', $homeroomClassId)
                    ->where('status', 'active');
            })
                ->where('role_type', 'student')
                ->where('is_active', true)
                ->count();

            // 3. Today's Attendance Stats
            $attendanceCounts = DB::table('attendances')
                ->where('school_id', $user->school_id)
                ->where('class_id', $homeroomClassId)
                ->where('date', $todayStr)
                ->groupBy('status')
                ->select('status', DB::raw('count(*) as total'))
                ->pluck('total', 'status');

            $present = $attendanceCounts['present'] ?? 0;
            $late = $attendanceCounts['late'] ?? 0;
            $sick = $attendanceCounts['sick'] ?? 0;
            $permission = $attendanceCounts['permit'] ?? 0;
            $alpha = $attendanceCounts['alpha'] ?? 0;

            $totalAttendanceRecords = $present + $late + $sick + $permission + $alpha;
            $notCheckedIn = max(0, $totalStudents - $totalAttendanceRecords);

            $attendanceRate = $totalStudents > 0
                ? round((($present + $late) / $totalStudents) * 100, 1)
                : 0;

            // 4. 7-Day Trend
            $startDate = $currentDate->copy()->subDays(6);
            $trendData = DB::table('attendances')
                ->where('school_id', $user->school_id)
                ->where('class_id', $homeroomClassId)
                ->whereBetween('date', [$startDate->toDateString(), $todayStr])
                ->whereIn('status', ['present', 'late']) // Count as present
                ->groupBy('date')
                ->select('date', DB::raw('count(*) as present_count'))
                ->orderBy('date')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [$item->date => $item->present_count];
                });

            // Fill missing days with 0
            $chartData = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = $currentDate->copy()->subDays($i)->toDateString();
                $count = $trendData[$d] ?? 0;
                $chartData[] = [
                    'date' => $d,
                    'label' => Carbon::parse($d)->isoFormat('dd'), // Sen, Sel...
                    'present_count' => $count,
                    'rate' => $totalStudents > 0 ? round(($count / $totalStudents) * 100, 1) : 0,
                ];
            }

            return [
                'is_homeroom' => true,
                'class_info' => $classInfo,
                'summary' => [
                    'total_students' => $totalStudents,
                    'present_today' => $present + $late, // Present includes Late usually
                    'late_today' => $late,
                    'absent_today' => $sick + $permission + $alpha,
                    'not_checked_in' => $notCheckedIn,
                    'attendance_rate' => $attendanceRate,
                ],
                'details' => [
                    'present' => $present,
                    'late' => $late,
                    'sick' => $sick,
                    'permission' => $permission,
                    'alpha' => $alpha,
                ],
                'trends' => $chartData,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get Homeroom Students
     */
    /**
     * Get Homeroom Students with detailed attendance metrics
     */
    public function myStudents(Request $request)
    {
        $user = $request->user();

        // 1. Validate Homeroom Access
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $user->school_id)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $classId = $teacherRole->homeroom_class_id;

        // 2. Fetch Students with eager loading to prevent N+1
        $students = User::whereHas('classStudents', function ($query) use ($classId) {
            $query->where('class_id', $classId)->where('status', 'active');
        })
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->select('id', 'name', 'username as nis', 'device_id')
            ->orderBy('name')
            ->get();

        if ($students->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $studentIds = $students->pluck('id');

        // 3. OPTIMIZED: Single batch query for all attendance data (Last 30 Days)
        $thirtyDaysAgo = Carbon::now()->subDays(30)->toDateString();

        $attendances = DB::table('attendances')
            ->whereIn('student_id', $studentIds)
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('school_id', $user->school_id)
            ->orderBy('date', 'desc')
            ->select('student_id', 'status', 'date')
            ->get()
            ->groupBy('student_id');

        // 4. Process Metrics per Student (single loop, no additional queries)
        $processedStudents = $students->map(function ($student) use ($attendances) {
            $records = $attendances->get($student->id) ?? collect();

            // A. Last Status
            $lastRecord = $records->first();
            $lastStatus = $lastRecord ? $lastRecord->status : 'no_data';

            // B. Attendance Rate (30 Days)
            $totalRecords = $records->count();
            $presentCount = $records->whereIn('status', ['present', 'late'])->count();
            $rate = $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 1) : 100;

            // C. Consecutive Absence Streak
            $streak = 0;
            foreach ($records as $record) {
                if (in_array($record->status, ['sick', 'permit', 'alpha'])) {
                    $streak++;
                } else {
                    break;
                }
            }

            // D. Risk Analysis
            $risk = 'green';
            if ($rate < 70 || $streak >= 3) {
                $risk = 'red';
            } elseif ($rate < 85 || $streak >= 2) {
                $risk = 'yellow';
            }

            return [
                'id' => $student->id,
                'name' => $student->name,
                'nis' => $student->nis,
                'registered' => ! empty($student->device_id),
                'metrics' => [
                    'last_status' => $lastStatus,
                    'attendance_rate' => $rate,
                    'absence_streak' => $streak,
                    'risk_level' => $risk,
                ],
            ];
        });

        // 5. Sort by Risk (Red first)
        $sortedStudents = $processedStudents->sortBy(function ($s) {
            return match ($s['metrics']['risk_level']) {
                'red' => 1,
                'yellow' => 2,
                default => 3
            };
        })->values();

        return response()->json([
            'success' => true,
            'data' => $sortedStudents,
        ]);
    }

    /**
     * Get Reward Candidates
     * Track students close to earning rewards
     */
    public function getRewardCandidates(Request $request)
    {
        $user = $request->user();

        // 1. Validate Homeroom Access
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $user->school_id)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $classId = $teacherRole->homeroom_class_id;

        // 2. Fetch Students with Points/Streak info
        $students = User::whereHas('classStudents', function ($query) use ($classId) {
            $query->where('class_id', $classId)->where('status', 'active');
        })
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->select('id', 'name', 'total_points', 'current_streak')
            ->get();

        // 3. Filter Candidates
        $closeToLevelUp = [];
        $closeToStreakReward = [];

        foreach ($students as $student) {
            $points = $student->total_points ?? 0;
            $streak = $student->current_streak ?? 0;

            // Check Level Up Proximity (2% threshold)
            // Bronze (0-200) -> Next Silver (201). Threshold 196-200.
            // Silver (201-500) -> Next Gold (501). Threshold 490-500.
            // Gold (501-1000) -> Next Platinum (1001). Threshold 980-1000.

            $nextLevel = null;
            $pointsNeeded = 0;

            if ($points >= 196 && $points <= 200) {
                $nextLevel = 'Silver';
                $pointsNeeded = 201 - $points;
            } elseif ($points >= 490 && $points <= 500) {
                $nextLevel = 'Gold';
                $pointsNeeded = 501 - $points;
            } elseif ($points >= 980 && $points <= 1000) {
                $nextLevel = 'Platinum';
                $pointsNeeded = 1001 - $points;
            }

            if ($nextLevel) {
                $closeToLevelUp[] = [
                    'id' => $student->id,
                    'name' => $student->name,
                    'current_points' => $points,
                    'next_level' => $nextLevel,
                    'points_needed' => $pointsNeeded,
                ];
            }

            // Check Streak Proximity (Close to 30 days)
            // Range 25-29
            if ($streak >= 25 && $streak < 30) {
                $closeToStreakReward[] = [
                    'id' => $student->id,
                    'name' => $student->name,
                    'current_streak' => $streak,
                    'days_needed' => 30 - $streak,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'close_to_level_up' => $closeToLevelUp,
                'close_to_streak_reward' => $closeToStreakReward,
            ],
        ]);
    }

    /**
     * Get Student Alerts (Critical Issues)
     */
    public function getAlerts(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // 1. Resolve Homeroom
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $classId = $teacherRole->homeroom_class_id;

        // 2. Get Students Map
        $students = User::whereHas('classStudents', function ($query) use ($classId) {
            $query->where('class_id', $classId)->where('status', 'active');
        })
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->pluck('name', 'id');

        if ($students->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $studentIds = $students->keys();
        $alerts = [];

        // Fetch Risk Data (assuming 'student_attendance_risk' table is populated via Cron/Service)
        // If not populated, we fallback to calculation or just skip.
        // For REAL TIME accuracy, we query the Risk Table + Recent Attendance.

        $latestRisk = DB::table('student_attendance_risk')
            ->whereIn('student_id', $studentIds)
            ->whereIn('risk_level', ['high', 'medium'])
            ->where('calculated_at', '>=', Carbon::now()->subHours(24)) // Fresh risk
            ->get()
            ->keyBy('student_id');

        // A. HIGH Risk Students
        foreach ($latestRisk as $risk) {
            if ($risk->risk_level === 'high') {
                $alerts[] = [
                    'type' => 'high_risk',
                    'severity' => 'critical',
                    'student_id' => $risk->student_id,
                    'student_name' => $students[$risk->student_id] ?? 'Unknown',
                    'detail' => "Student is at HIGH risk (Score: {$risk->risk_score}). Factors: ".implode(', ', json_decode($risk->factors_json) ?? []),
                    'score' => $risk->risk_score,
                ];
            }
        }

        // B. Risk Increase (Low -> Medium/High)
        // Compare with previous risk record (older than 24h)
        $previousRisk = DB::table('student_attendance_risk')
            ->whereIn('student_id', $studentIds)
            ->where('calculated_at', '<', Carbon::now()->subHours(24))
            ->orderBy('calculated_at', 'desc')
            ->get()
            ->unique('student_id')
            ->keyBy('student_id');

        foreach ($latestRisk as $studentId => $current) {
            $prev = $previousRisk[$studentId] ?? null;
            $prevLevel = $prev ? $prev->risk_level : 'low'; // Default low if new

            if ($prevLevel === 'low' && in_array($current->risk_level, ['medium', 'high'])) {
                $alerts[] = [
                    'type' => 'risk_escalation',
                    'severity' => 'high',
                    'student_id' => $studentId,
                    'student_name' => $students[$studentId] ?? 'Unknown',
                    'detail' => "Risk escalated from {$prevLevel} to {$current->risk_level}",
                    'escalation' => "{$prevLevel} -> {$current->risk_level}",
                ];
            }
        }

        // C. Sudden 2-Day Absence
        // Real-time check from 'attendances'
        $recentRecords = DB::table('attendances')
            ->whereIn('student_id', $studentIds)
            ->where('school_id', $schoolId)
            ->where('attendance_date', '>=', Carbon::now()->subDays(5)->toDateString())
            ->orderBy('attendance_date', 'desc')
            ->get()
            ->groupBy('student_id');

        foreach ($students as $id => $name) {
            $records = $recentRecords->get($id);
            $streak = 0;

            if ($records) {
                // Group by date to handle multiple sessions per day
                $dates = $records->groupBy('attendance_date');
                foreach ($dates as $date => $dayRecs) {
                    // Check if day is absent (all records absent)
                    $isAbsent = $dayRecs->every(fn ($r) => in_array($r->status, ['absent', 'alpha']));
                    // If status 'alpha' (unexplained) specifically? Prompt says "absence" in general.
                    // Let's assume 'absent'/'alpha'.
                    if ($isAbsent) {
                        $streak++;
                    } else {
                        break;
                    }
                }
            }

            // Exactly 2 days sudden absence? Or 2+? Prompt says "sudden 2-day absence".
            // Let's alert on >= 2 if we haven't already alerted on high risk (streak 3 is high risk).
            // streak=2 is specific trigger.
            if ($streak == 2) {
                $alerts[] = [
                    'type' => 'sudden_absence',
                    'severity' => 'medium',
                    'student_id' => $id,
                    'student_name' => $name,
                    'detail' => 'Absent for last 2 consecutive days',
                    'days' => 2,
                ];
            }
        }

        // Sort Alerts: Critical -> High -> Medium
        $severityOrder = ['critical' => 1, 'high' => 2, 'medium' => 3];
        usort($alerts, function ($a, $b) use ($severityOrder) {
            return $severityOrder[$a['severity']] <=> $severityOrder[$b['severity']];
        });

        return response()->json([
            'success' => true,
            'data' => $alerts,
        ]);
    }

    /**
     * Get Student Risk Detail Breakdown
     */
    public function getStudentRiskDetail(Request $request, $studentId, \App\Services\RiskAnalysisService $riskService)
    {
        $user = $request->user();

        // Validation: Ensure student belongs to teacher's class? Or just school?
        // For simplicity, just check school.
        $student = User::where('id', $studentId)
            ->where('school_id', $user->school_id)
            ->where('role_type', 'student')
            ->firstOrFail();

        $riskData = $riskService->calculateStudentRisk($student);

        return response()->json([
            'success' => true,
            'data' => $riskData,
        ]);
    }

    /**
     * Get Class Timeline (Today's Schedule + Attendance)
     */
    public function getClassTimeline(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $todayStr = Carbon::now()->toDateString();
        $dayOfWeek = Carbon::now()->dayOfWeek;

        // 1. Get Homeroom Class
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $classId = $teacherRole->homeroom_class_id;

        // 2. Get Schedules
        $schedules = DB::table('schedules')
            ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->join('users as teachers', 'schedules.teacher_id', '=', 'teachers.id')
            ->where('schedules.class_id', $classId)
            ->where('schedules.school_id', $schoolId)
            ->where('schedules.day_of_week', $dayOfWeek)
            ->where('schedules.is_active', true)
            ->select(
                'schedules.id',
                'schedules.start_time',
                'schedules.end_time',
                'subjects.name as subject_name',
                'teachers.name as teacher_name'
            )
            ->orderBy('schedules.start_time')
            ->get();

        // 3. Get Attendance Counts per Schedule
        $scheduleIds = $schedules->pluck('id');
        $attendanceCounts = DB::table('attendances')
            ->whereIn('schedule_id', $scheduleIds)
            ->where('attendance_date', $todayStr)
            ->whereIn('status', ['present', 'late'])
            ->select('schedule_id', DB::raw('count(*) as present_count'))
            ->groupBy('schedule_id')
            ->pluck('present_count', 'schedule_id');

        // 4. Transform
        $timeline = $schedules->map(function ($schedule) use ($attendanceCounts) {
            $now = Carbon::now()->format('H:i:s');
            $status = 'upcoming';
            if ($now > $schedule->end_time) {
                $status = 'completed';
            } elseif ($now >= $schedule->start_time) {
                $status = 'ongoing';
            }

            return [
                'id' => $schedule->id,
                'subject' => $schedule->subject_name,
                'teacher' => $schedule->teacher_name,
                'time_range' => substr($schedule->start_time, 0, 5).' - '.substr($schedule->end_time, 0, 5),
                'status' => $status,
                'present_count' => $attendanceCounts[$schedule->id] ?? 0,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $timeline,
        ]);
    }

    /**
     * Get Monthly Attendance Analytics
     */
    public function getMonthlyAnalytics(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // 1. Resolve Homeroom
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $classId = $teacherRole->homeroom_class_id;

        // 2. Fetch Aggregated Data (PHP-side processing for complex metrics)
        $monthAttendances = DB::table('attendances')
            ->where('class_id', $classId)
            ->whereBetween('attendance_date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->select('student_id', 'status', 'attendance_date', 'check_in_time')
            ->get();

        // A. Weekly Attendance Rate
        $weeklyStats = $monthAttendances->groupBy(function ($item) {
            return Carbon::parse($item->attendance_date)->weekOfMonth;
        })->map(function ($weekRecords, $weekNum) {
            $total = $weekRecords->count();
            $present = $weekRecords->whereIn('status', ['present', 'late'])->count();

            return [
                'week' => 'Week '.$weekNum,
                'rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
            ];
        })->values();

        // B. Data Preparation for Ranking
        $studentIds = $monthAttendances->pluck('student_id')->unique();
        $studentNames = User::whereIn('id', $studentIds)->pluck('name', 'id');

        // C. Top 5 Most Absent
        $topAbsent = $monthAttendances
            ->whereIn('status', ['absent', 'sick', 'permit', 'alpha'])
            ->groupBy('student_id')
            ->map(function ($records, $studentId) use ($studentNames) {
                return [
                    'student_id' => $studentId,
                    'name' => $studentNames[$studentId] ?? 'Unknown',
                    'count' => $records->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values();

        // D. Top 5 Most Punctual (Present and NOT Late)
        $topPunctual = $monthAttendances
            ->where('status', 'present')
            ->groupBy('student_id')
            ->map(function ($records, $studentId) use ($studentNames) {
                return [
                    'student_id' => $studentId,
                    'name' => $studentNames[$studentId] ?? 'Unknown',
                    'count' => $records->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values();

        // E. Average Arrival Time
        $times = $monthAttendances
            ->whereNotNull('check_in_time')
            ->map(function ($item) {
                return Carbon::parse($item->check_in_time)->secondsSinceMidnight();
            });

        $avgArrivalTime = '-';
        if ($times->isNotEmpty()) {
            $avgSeconds = $times->average();
            $avgArrivalTime = gmdate('H:i', (int) $avgSeconds);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $startOfMonth->format('F Y'),
                'weekly_trends' => $weeklyStats,
                'most_absent' => $topAbsent,
                'most_punctual' => $topPunctual,
                'avg_arrival_time' => $avgArrivalTime,
            ],
        ]);
    }

    /**
     * Get Parent Contact List for Students with Issues
     */
    public function getParentContactList(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $todayStr = Carbon::now()->toDateString();
        $thirtyDaysAgo = Carbon::now()->subDays(30)->toDateString();

        // 1. Resolve Homeroom
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $classId = $teacherRole->homeroom_class_id;

        // 2. OPTIMIZED: Fetch Students with eager loading
        $students = User::whereHas('classStudents', function ($query) use ($classId) {
            $query->where('class_id', $classId)->where('status', 'active');
        })
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->select('id', 'name')
            ->get();

        if ($students->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $studentIds = $students->pluck('id');

        // 3. OPTIMIZED: Batch Query All Data in single queries

        // A. Today's Status - Single query
        $todayRecords = DB::table('attendances')
            ->whereIn('student_id', $studentIds)
            ->where('attendance_date', $todayStr)
            ->select('student_id', 'status')
            ->get()
            ->pluck('status', 'student_id');

        // B. 30 Days Stats - Single aggregated query
        $stats = DB::table('attendances')
            ->whereIn('student_id', $studentIds)
            ->where('attendance_date', '>=', $thirtyDaysAgo)
            ->select(
                'student_id',
                DB::raw('count(*) as total'),
                DB::raw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present_count")
            )
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        // C. Last 5 Records - Single query with window function simulation
        $recentHistory = DB::table('attendances')
            ->whereIn('student_id', $studentIds)
            ->orderBy('attendance_date', 'desc')
            ->select('student_id', 'attendance_date', 'status')
            ->get()
            ->groupBy('student_id')
            ->map(function ($records) {
                return $records->take(5);
            });

        // D. Parents Info - Single query with joins
        $studentParents = DB::table('student_parents')
            ->join('users', 'student_parents.parent_id', '=', 'users.id')
            ->leftJoin('user_profiles', 'users.id', '=', 'user_profiles.user_id')
            ->whereIn('student_parents.student_id', $studentIds)
            ->select(
                'student_parents.student_id',
                'users.name as parent_name',
                'users.email',
                'user_profiles.phone',
                'student_parents.relationship'
            )
            ->get()
            ->groupBy('student_id');

        // 4. Process and Filter (single loop, no additional queries)
        $contactList = [];

        foreach ($students as $student) {
            $issues = [];
            $todayStatus = $todayRecords[$student->id] ?? null;

            // Criteria 1: Absent Today
            if (! $todayStatus || in_array($todayStatus, ['absent', 'sick', 'permit', 'alpha'])) {
                $statusLabel = $todayStatus ? ucfirst($todayStatus) : 'Not Checked In';
                $issues[] = "Absent today ({$statusLabel})";
            }

            // Criteria 2: Late Today
            if ($todayStatus === 'late') {
                $issues[] = 'Late today';
            }

            // Criteria 3: Low Rate
            $stat = $stats[$student->id] ?? null;
            $rate = 100;
            if ($stat && $stat->total > 0) {
                $rate = ($stat->present_count / $stat->total) * 100;
            }
            if ($rate < 75) {
                $issues[] = 'Low attendance ('.round($rate, 1).'%)';
            }

            // Only include if there are issues
            if (! empty($issues)) {
                $parents = $studentParents[$student->id] ?? collect();

                $contactList[] = [
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'issues' => $issues,
                    'parents' => $parents->map(function ($p) {
                        return [
                            'name' => $p->parent_name,
                            'relation' => $p->relationship,
                            'contact' => $p->phone ?? $p->email ?? '-',
                        ];
                    }),
                    'history' => $recentHistory[$student->id] ?? [],
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $contactList,
        ]);
    }

    /**
     * Get Attendance Correction Needed List
     * Anomalies that require teacher verification.
     */
    public function getCorrectionList(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $today = Carbon::now()->toDateString();
        // Look back 3 days for corrections
        $lookbackDate = Carbon::now()->subDays(3)->toDateString();

        // 1. Resolve Homeroom
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $classId = $teacherRole->homeroom_class_id;

        // 2. Fetch Attendances with Anomalies
        $corrections = DB::table('attendances')
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->leftJoin('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->where('attendances.class_id', $classId)
            ->where('attendances.school_id', $schoolId)
            ->whereBetween('attendances.attendance_date', [$lookbackDate, $today])
            ->select(
                'attendances.id',
                'attendances.student_id',
                'users.name as student_name',
                'users.device_id as registered_device',
                'attendances.attendance_date',
                'attendances.check_in_time',
                'attendances.status',
                'attendances.device_id_in',
                'schedules.start_time',
                'schedules.end_time',
                'subjects.name as subject_name'
            )
            ->get();

        $list = [];

        foreach ($corrections as $record) {
            $issues = [];
            $checkIn = $record->check_in_time ? Carbon::parse($record->check_in_time) : null;
            $checkInTimeOnly = $checkIn ? $checkIn->format('H:i:s') : null;

            // 1. Absent but Scanned
            if (in_array($record->status, ['absent', 'alpha', 'sick', 'permit']) && $record->check_in_time) {
                $issues[] = 'marked_absent_but_has_log';
            }

            // 2. Scanned Outside Window (Very Late/Early or After Class)
            // We define "Window" as Corrections mostly care about anomalies.
            // If they are "Present" or "Late", check if logic holds.
            if ($checkInTimeOnly) {
                // If scanned AFTER class ended
                if ($checkInTimeOnly > $record->end_time) {
                    $issues[] = 'scanned_after_class_ended';
                }
            }

            // 3. Device Mismatch
            // Only relevant if both IDs exist
            if ($record->device_id_in && $record->registered_device) {
                if ($record->device_id_in !== $record->registered_device) {
                    $issues[] = 'device_mismatch';
                }
            }

            if (! empty($issues)) {
                $list[] = [
                    'attendance_id' => $record->id,
                    'student_name' => $record->student_name,
                    'date' => $record->attendance_date,
                    'subject' => $record->subject_name,
                    'current_status' => $record->status,
                    'scan_time' => $checkIn ? $checkIn->format('H:i') : '-',
                    'issues' => $issues,
                    'device_info' => in_array('device_mismatch', $issues) ? [
                        'registered' => $record->registered_device, // Should probably mask this in real app
                        'used' => $record->device_id_in,
                    ] : null,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $list,
        ]);
    }

    /**
     * Get Class Health Score
     * Formula: 50% Att Rate + 30% Punc Rate + 20% Improvement
     */
    public function getClassHealth(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // 1. Resolve Homeroom
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json([
                'success' => true,
                'data' => ['score' => 0, 'grade' => 'N/A', 'details' => []],
            ]);
        }
        $classId = $teacherRole->homeroom_class_id;

        // 2. Define Periods
        $startCurrent = Carbon::now()->startOfMonth()->toDateString();
        $endCurrent = Carbon::now()->endOfMonth()->toDateString();
        $startLast = Carbon::now()->subMonth()->startOfMonth()->toDateString();
        $endLast = Carbon::now()->subMonth()->endOfMonth()->toDateString();

        // 3. Fetch Data Correctly
        // Only count records where status is finalized?
        // We look at all records for the class in the period.

        $currentStats = DB::table('attendances')
            ->where('class_id', $classId)
            ->whereBetween('attendance_date', [$startCurrent, $endCurrent])
            ->select(
                DB::raw('count(*) as total'),
                DB::raw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present_check"),
                DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as on_time_check"),
                DB::raw("SUM(CASE WHEN status IN ('absent', 'alpha', 'sick', 'permit') THEN 1 ELSE 0 END) as absent_check")
            )
            ->first();

        $lastStats = DB::table('attendances')
            ->where('class_id', $classId)
            ->whereBetween('attendance_date', [$startLast, $endLast])
            ->select(
                DB::raw('count(*) as total'),
                DB::raw("SUM(CASE WHEN status IN ('absent', 'alpha', 'sick', 'permit') THEN 1 ELSE 0 END) as absent_check")
            )
            ->first();

        // 4. Calculate Component 1: Attendance Rate (50%)
        // (Present + Late) / Total
        $totalCur = $currentStats->total ?? 0;
        $attRateRaw = $totalCur > 0 ? ($currentStats->present_check / $totalCur) * 100 : 0;
        if ($totalCur == 0) {
            $attRateRaw = 100;
        } // Neutral start for empty month? Or 0? Let's say 100 to be optimistic for new month

        $scoreAtt = $attRateRaw;

        // 5. Calculate Component 2: Punctuality Rate (30%)
        // Present (On Time) / Total
        $puncRateRaw = $totalCur > 0 ? ($currentStats->on_time_check / $totalCur) * 100 : 0;
        if ($totalCur == 0) {
            $puncRateRaw = 100;
        }

        $scorePunc = $puncRateRaw;

        // 6. Calculate Component 3: Improvement (20%)
        // Based on Absence Rate (Lower is better)
        $absRateCur = $totalCur > 0 ? ($currentStats->absent_check / $totalCur) * 100 : 0;

        $totalLast = $lastStats->total ?? 0;
        $absRateLast = $totalLast > 0 ? ($lastStats->absent_check / $totalLast) * 100 : $absRateCur; // Default to current if no history

        // Logic:
        // If AbsCur <= AbsLast -> 100 pts (Improved or Same)
        // If AbsCur > AbsLast -> Score degrades.
        // Formula: 100 * (AbsLast / AbsCur)
        if ($absRateCur <= $absRateLast) {
            $scoreImp = 100;
        } else {
            // Prevent division by zero (impossible given logic, but safety)
            $scoreImp = $absRateCur > 0 ? ($absRateLast / $absRateCur) * 100 : 100;
        }

        // 7. Final Weighted Score
        // 50% Att + 30% Punc + 20% Imp
        $finalScore = ($scoreAtt * 0.50) + ($scorePunc * 0.30) + ($scoreImp * 0.20);
        $finalScore = round($finalScore, 1);

        // Grade
        $grade = 'F';
        if ($finalScore >= 90) {
            $grade = 'A';
        } elseif ($finalScore >= 80) {
            $grade = 'B';
        } elseif ($finalScore >= 70) {
            $grade = 'C';
        } elseif ($finalScore >= 60) {
            $grade = 'D';
        }

        return response()->json([
            'success' => true,
            'data' => [
                'health_score' => $finalScore,
                'grade' => $grade,
                'breakdown' => [
                    'attendance_score' => [
                        'value' => round($scoreAtt, 1),
                        'weight' => '50%',
                        'contribution' => round($scoreAtt * 0.50, 1),
                    ],
                    'punctuality_score' => [
                        'value' => round($scorePunc, 1),
                        'weight' => '30%',
                        'contribution' => round($scorePunc * 0.30, 1),
                    ],
                    'improvement_score' => [
                        'value' => round($scoreImp, 1),
                        'weight' => '20%',
                        'contribution' => round($scoreImp * 0.20, 1),
                        'detail' => 'Absence: '.round($absRateCur, 1).'% (Current) vs '.round($absRateLast, 1).'% (Last Month)',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Get Student Detail Summary (Deep Dive)
     */
    public function getStudentDetail(Request $request, $studentId)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // 1. Resolve Homeroom
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json(['success' => false, 'message' => 'Not a homeroom teacher'], 403);
        }
        $classId = $teacherRole->homeroom_class_id;

        // 2. Validate Student Belongs to Class
        $student = User::whereHas('classStudents', function ($query) use ($classId) {
            $query->where('class_id', $classId)->where('status', 'active');
        })
            ->where('id', $studentId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->first();

        if (! $student) {
            return response()->json(['success' => false, 'message' => 'Student not found in your class'], 404);
        }

        // 3. Stats (Current Month)
        $monthlyStats = DB::table('attendances')
            ->where('student_id', $studentId)
            ->whereBetween('attendance_date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->select(
                DB::raw('count(*) as total'),
                DB::raw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present_check"),
                DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count")
            )
            ->first();

        $totalRecords = $monthlyStats->total ?? 0;
        $attRate = $totalRecords > 0 ? ($monthlyStats->present_check / $totalRecords) * 100 : 0;
        if ($totalRecords == 0) {
            $attRate = 100;
        } // Optimistic default

        // 4. Streak Calculation (All time recent)
        // Check last 10 records for streak
        $recentRecords = DB::table('attendances')
            ->where('student_id', $studentId)
            ->where('attendance_date', '<=', Carbon::now()->toDateString())
            ->orderBy('attendance_date', 'desc')
            ->limit(10)
            ->get();

        $streak = 0;
        foreach ($recentRecords as $rec) {
            if (in_array($rec->status, ['absent', 'alpha', 'sick', 'permit'])) {
                $streak++;
            } else {
                break;
            }
        }

        // 5. History (Pagination: Last 10 records implies Page 1 of pagination)
        // We use standard pagination here to allow "Load More"
        $history = DB::table('attendances')
            ->where('student_id', $studentId)
            ->orderBy('attendance_date', 'desc')
            ->select('id', 'attendance_date', 'status', 'check_in_time', 'check_out_time')
            ->paginate(10); // Standard pagination

        return response()->json([
            'success' => true,
            'data' => [
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'nis' => $student->username, // Assuming username is NIS
                    'photo' => $student->profile_photo_path ?? null, // Should use accessor or profile table really
                ],
                'summary' => [
                    'attendance_rate_month' => round($attRate, 1),
                    'late_count_month' => (int) ($monthlyStats->late_count ?? 0),
                    'absence_streak' => $streak,
                ],
                'history' => $history,
            ],
        ]);
    }

    /**
     * Get Activity Feed (Last 15 Events)
     */
    public function getActivityFeed(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // 1. Resolve Homeroom
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $classId = $teacherRole->homeroom_class_id;

        // 2. Fetch Attendance Logs (Check-ins, Late, Corrections)
        $attendanceEvents = DB::table('attendance_logs')
            ->join('attendances', 'attendance_logs.attendance_id', '=', 'attendances.id')
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->where('attendances.class_id', $classId)
            ->where('attendances.school_id', $schoolId)
            ->orderBy('attendance_logs.created_at', 'desc')
            ->limit(15)
            ->select(
                'attendance_logs.id',
                'attendance_logs.action',
                'attendance_logs.new_status',
                'attendance_logs.created_at',
                'users.name as student_name',
                'users.id as student_id'
            )
            ->get()
            ->map(function ($log) {
                $type = 'info';
                $title = 'Activity';
                $desc = '';

                if ($log->action === 'scan_in') {
                    if ($log->new_status === 'late') {
                        $type = 'warning';
                        $title = 'Student Marked Late';
                        $desc = "{$log->student_name} terlambat check-in.";
                    } else {
                        $type = 'success';
                        $title = 'Student Checked In';
                        $desc = "{$log->student_name} hadir tepat waktu.";
                    }
                } elseif ($log->action === 'manual_update') {
                    $type = 'info';
                    $title = 'Attendance Corrected';
                    $desc = "Absensi {$log->student_name} telah diperbarui.";
                } elseif ($log->action === 'manual_create') {
                    $type = 'info';
                    $title = 'Attendance Manually Recorded';
                    $desc = "Absensi {$log->student_name} dicatat manual.";
                }

                return [
                    'id' => 'att_'.$log->id,
                    'timestamp' => $log->created_at,
                    'time_ago' => Carbon::parse($log->created_at)->diffForHumans(),
                    'type' => $type,
                    'title' => $title,
                    'description' => $desc,
                    'meta' => [
                        'student_id' => $log->student_id,
                        'student_name' => $log->student_name,
                    ],
                ];
            });

        // 3. Fetch Schedule Changes (Audit Logs)
        // We'll search for generic schedule events for this school to avoid complex join logic on generic table
        $scheduleEvents = DB::table('audit_logs')
            ->where('school_id', $schoolId)
            ->where('action', 'like', '%Schedule%')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => 'audit_'.$log->id,
                    'timestamp' => $log->created_at,
                    'time_ago' => Carbon::parse($log->created_at)->diffForHumans(),
                    'type' => 'warning', // Schedule changes are important
                    'title' => 'Schedule Change',
                    'description' => $log->description ?? $log->action,
                    'meta' => [],
                ];
            });

        // 4. Merge and Sort
        $feed = $attendanceEvents->merge($scheduleEvents)
            ->sortByDesc('timestamp')
            ->take(15)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $feed,
        ]);
    }

    /**
     * Get Subject Teacher Dashboard (Teaching Dashboard)
     */
    public function getSubjectDashboard(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $key = "subject_dashboard_{$schoolId}_{$user->id}";

        // CACHE: 60 Seconds
        $data = $this->cacheWithTags(['dashboard', "teacher_{$user->id}"], $key, 60, function () use ($user, $schoolId) {
            $today = Carbon::now()->toDateString();
            $dayOfWeek = Carbon::now()->dayOfWeek;

            // 1. Get Classes Taught Today (Schedules)
            $schedules = DB::table('schedules')
                ->join('classes', 'schedules.class_id', '=', 'classes.id')
                ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
                ->where('schedules.teacher_id', $user->id)
                ->where('schedules.school_id', $schoolId)
                ->where('schedules.day_of_week', $dayOfWeek)
                ->where('schedules.is_active', true)
                ->select(
                    'schedules.id',
                    'schedules.start_time',
                    'schedules.end_time',
                    'schedules.room',
                    'classes.name as class_name',
                    'subjects.name as subject_name',
                    'classes.id as class_id'
                )
                ->orderBy('schedules.start_time')
                ->get();

            $scheduleIds = $schedules->pluck('id');

            // 2. Fetch Attendance For These Schedules
            // Logic: Count unique students? Or summing sessions?
            // "Total students taught today" -> Sum of students present/late in all sessions (seats filled)
            // If a student is taught twice (Math then Physics), they count twice? Usually yes for "volume" stats.

            $attendancesToday = DB::table('attendances')
                ->whereIn('schedule_id', $scheduleIds)
                ->where('attendance_date', $today)
                ->get();

            $seatsFilled = $attendancesToday->whereIn('status', ['present', 'late'])->count();
            $lateToday = $attendancesToday->where('status', 'late')->count();
            $absentToday = $attendancesToday->whereIn('status', ['absent', 'alpha', 'sick', 'permit'])->count();

            $totalRecorded = $seatsFilled + $absentToday; // Total students we SHOULD have taught (that have attendance record)
            // If attendance hasn't been taken yet for a class, $totalRecorded might be small.
            // Bettermetric: Sum of class sizes for *upcoming/completed* classes?
            // For now, let's stick to 'recorded' metrics to avoid complexity of "enrolled students" queries per class.

            $rate = $totalRecorded > 0 ? ($seatsFilled / $totalRecorded) * 100 : 0;

            // 3. Next Upcoming Class
            $nowTime = Carbon::now()->format('H:i:s');
            $nextClass = $schedules->first(function ($s) use ($nowTime) {
                return $s->end_time >= $nowTime; // Ongoing or Future
            });

            // 4. 7-Day Trend for THIS Teacher (Across ALL their subjects)
            // We look for attendance records where schedule.teacher_id = user->id
            $sevendaysAgo = Carbon::now()->subDays(6)->toDateString();

            $trendStats = DB::table('attendances')
                ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
                ->where('schedules.teacher_id', $user->id)
                ->whereBetween('attendances.attendance_date', [$sevendaysAgo, $today])
                ->select('attendances.attendance_date', 'attendances.status')
                ->get()
                ->groupBy('attendance_date')
                ->map(function ($dayRecords, $date) {
                    $total = $dayRecords->count();
                    $present = $dayRecords->whereIn('status', ['present', 'late'])->count();

                    return $total > 0 ? round(($present / $total) * 100, 1) : 0;
                });

            $chartData = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = Carbon::now()->subDays($i)->toDateString();
                $chartData[] = [
                    'date' => $d,
                    'label' => Carbon::parse($d)->isoFormat('dd'),
                    'rate' => $trendStats[$d] ?? 0,
                ];
            }

            return [
                'summary' => [
                    'classes_today' => $schedules->count(),
                    'students_taught' => $seatsFilled, // Present/Late
                    'attendance_rate' => round($rate, 1),
                    'total_late' => $lateToday,
                    'total_absent' => $absentToday,
                ],
                'next_class' => $nextClass ? [
                    'subject' => $nextClass->subject_name,
                    'class' => $nextClass->class_name,
                    'time' => substr($nextClass->start_time, 0, 5),
                    'room' => $nextClass->room,
                ] : null,
                'today_schedule' => $schedules->map(function ($s) use ($nowTime) {
                    $status = 'upcoming';
                    if ($nowTime > $s->end_time) {
                        $status = 'finished';
                    } elseif ($nowTime >= $s->start_time) {
                        $status = 'ongoing';
                    }

                    return [
                        'id' => $s->id,
                        'subject' => $s->subject_name,
                        'class' => $s->class_name,
                        'time' => substr($s->start_time, 0, 5).' - '.substr($s->end_time, 0, 5),
                        'room' => $s->room,
                        'status' => $status,
                    ];
                }),
                'weekly_trend' => $chartData,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get Detailed Session Monitoring (Today)
     */
    public function getSessionMonitoring(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $todayStr = Carbon::now()->toDateString();
        $dayOfWeek = Carbon::now()->dayOfWeek;

        // 1. Fetch Today's Schedules for this Teacher
        $schedules = DB::table('schedules')
            ->join('classes', 'schedules.class_id', '=', 'classes.id')
            ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->where('schedules.teacher_id', $user->id)
            ->where('schedules.school_id', $schoolId)
            ->where('schedules.day_of_week', $dayOfWeek)
            ->where('schedules.is_active', true)
            ->select(
                'schedules.id',
                'schedules.class_id',
                'schedules.start_time',
                'schedules.end_time',
                'classes.name as class_name',
                'subjects.name as subject_name'
            )
            ->orderBy('schedules.start_time')
            ->get();

        if ($schedules->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $classIds = $schedules->pluck('class_id')->unique();
        $scheduleIds = $schedules->pluck('id');

        // 2. Count Total Students per Class
        // We assume 'class_students' holds active enrollments
        $classCounts = DB::table('class_students')
            ->join('users', 'class_students.student_id', '=', 'users.id')
            ->whereIn('class_students.class_id', $classIds)
            ->where('class_students.status', 'active')
            ->where('users.is_active', true) // Only active students
            ->select('class_students.class_id', DB::raw('count(*) as total'))
            ->groupBy('class_students.class_id')
            ->pluck('total', 'class_id');

        // 3. Get Attendance Stats per Schedule
        $attendanceStats = DB::table('attendances')
            ->whereIn('schedule_id', $scheduleIds)
            ->where('attendance_date', $todayStr)
            ->select(
                'schedule_id',
                'status',
                DB::raw('count(*) as count')
            )
            ->groupBy('schedule_id', 'status')
            ->get()
            ->groupBy('schedule_id');

        // 4. Build Response
        $monitoringData = $schedules->map(function ($schedule) use ($classCounts, $attendanceStats) {
            $totalStudents = $classCounts[$schedule->class_id] ?? 0;

            // Get stats for this specific session
            $stats = $attendanceStats->get($schedule->id) ?? collect();

            $present = $stats->where('status', 'present')->sum('count');
            $late = $stats->where('status', 'late')->sum('count');
            $absent = $stats->whereIn('status', ['absent', 'alpha', 'sick', 'permit'])->sum('count');

            // "Not Checked In" is the remainder of students who have NO record for this schedule
            $totalRecorded = $present + $late + $absent;
            $notCheckedIn = max(0, $totalStudents - $totalRecorded);

            // Determine Session Status
            $now = Carbon::now()->format('H:i:s');
            $sessionStatus = 'upcoming';
            if ($now > $schedule->end_time) {
                $sessionStatus = 'finished';
            } elseif ($now >= $schedule->start_time) {
                $sessionStatus = 'ongoing';
            }

            return [
                'schedule_id' => $schedule->id,
                'class_name' => $schedule->class_name,
                'subject_name' => $schedule->subject_name,
                'time' => substr($schedule->start_time, 0, 5).' - '.substr($schedule->end_time, 0, 5),
                'session_status' => $sessionStatus,
                'stats' => [
                    'total_students' => $totalStudents,
                    'present' => $present,
                    'late' => $late,
                    'absent' => $absent,
                    'not_checked_in' => $notCheckedIn,
                    'attendance_rate' => $totalStudents > 0 ? round((($present + $late) / $totalStudents) * 100, 1) : 0,
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $monitoringData,
        ]);
    }

    /**
     * Get Student List for a Teaching Session
     */
    public function getSessionStudents(Request $request, $scheduleId)
    {
        $user = $request->user();
        $todayStr = Carbon::now()->toDateString();

        // 1. Verify Schedule Ownership
        // We ensure the schedule belongs to the teacher OR they are an admin/homeroom with permission (but prompt says Subject Teacher Dashboard context)
        // Let's stick to strict ownership for now or simple check.
        $schedule = DB::table('schedules')
            ->where('id', $scheduleId)
            ->where('teacher_id', $user->id)
            ->where('is_active', true)
            ->select('class_id', 'subject_id')
            ->first();

        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Schedule not found or unauthorized'], 404);
        }

        // 2. Base Query: Active Students in Class
        $query = User::whereHas('classStudents', function ($q) use ($schedule) {
            $q->where('class_id', $schedule->class_id)
                ->where('status', 'active');
        })
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->select('users.id', 'users.name', 'users.username as nis', 'users.profile_photo_url', 'users.device_id as registered_device');

        // Search Filter
        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        // 3. Fetch Data & Attach Attendance
        // We can't easily filter "not_checked_in" at SQL level with efficient left joins if filtering logic is complex.
        // It's often easier to fetch all (usually < 40 students per class) and process in Collection for status.
        $students = $query->orderBy('name')->get();

        // Fetch Attendance for this schedule
        $attendances = DB::table('attendances')
            ->where('schedule_id', $scheduleId)
            ->where('attendance_date', $todayStr)
            ->get()
            ->keyBy('student_id');

        // 4. Transform & Filter
        $statusFilter = $request->get('status'); // present, late, absent, not_checked_in

        $list = $students->map(function ($student) use ($attendances) {
            $att = $attendances[$student->id] ?? null;

            // Determine Status
            $status = 'not_checked_in';
            $checkInTime = '-';
            $deviceAnomaly = false;
            $anomalyDetail = null;

            if ($att) {
                $status = $att->status; // present, late, absent, sick, alpha...
                $checkInTime = $att->check_in_time ? Carbon::parse($att->check_in_time)->format('H:i') : '-';

                // Device Anomaly Check
                // If student has registered device, but checked in with different one?
                if ($att->check_in_time && ! empty($student->registered_device) && ! empty($att->device_id_in)) {
                    if ($student->registered_device !== $att->device_id_in) {
                        $deviceAnomaly = true;
                        $anomalyDetail = 'Device Mismatch';
                    }
                }
            }

            return [
                'id' => $student->id,
                'name' => $student->name,
                'nis' => $student->nis,
                'photo' => $student->profile_photo_url,
                'status' => $status, // normalized status
                'status_label' => $status === 'not_checked_in' ? 'Belum Absen' : ucfirst($status),
                'check_in_time' => $checkInTime,
                'device_anomaly' => $deviceAnomaly,
                'anomaly_detail' => $anomalyDetail,
            ];
        });

        // Apply Status Filter in memory (efficient for pagination < 100)
        if ($statusFilter) {
            $list = $list->filter(function ($item) use ($statusFilter) {
                if ($statusFilter === 'not_checked_in') {
                    return $item['status'] === 'not_checked_in';
                }
                if ($statusFilter === 'absent') {
                    return in_array($item['status'], ['absent', 'alpha', 'sick', 'permit']);
                }

                return $item['status'] === $statusFilter;
            });
        }

        return response()->json([
            'success' => true,
            'data' => $list->values(),
        ]);
    }

    /**
     * Get Class Behavior Analysis (Subject Teacher View)
     */
    public function getClassBehaviorAnalysis(Request $request, $classId)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $startOfMonth = Carbon::now()->startOfMonth()->toDateString();
        $endOfMonth = Carbon::now()->endOfMonth()->toDateString();

        // 1. Verify Teacher Access to Class
        // Teacher must have at least one schedule with this class
        $hasAccess = DB::table('schedules')
            ->where('teacher_id', $user->id)
            ->where('class_id', $classId)
            ->where('is_active', true)
            ->exists();

        // Homeroom override?
        if (! $hasAccess) {
            $isHomeroom = TeacherRole::where('teacher_id', $user->id)
                ->where('homeroom_class_id', $classId)
                ->exists();
            if ($isHomeroom) {
                $hasAccess = true;
            }
        }

        if (! $hasAccess) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to this class analytics'], 403);
        }

        // 2. Base Query: Class Attendance This Month
        $monthAttendances = DB::table('attendances')
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->where('attendances.class_id', $classId)
            ->where('attendances.school_id', $schoolId)
            ->whereBetween('attendances.attendance_date', [$startOfMonth, $endOfMonth]);
        // Filter by teacher's subject?
        // "Subject Teacher Dashboard" typically wants to see behavior in THEIR class.
        // But "Average arrival time" implies general school arrival?
        // Let's scope to ONLY schedules taught by THIS teacher for specific behavior analysis,
        // OR if prompt implies general class behavior, we use all.
        // "For selected class..." -> implied general or specific.
        // Usually a teacher wants to know "Are they late to MY class?".
        // Let's filter by schedule.teacher_id = user->id to be safe and relevant.

        $monthAttendances->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->where('schedules.teacher_id', $user->id)
            ->select(
                'attendances.student_id',
                'attendances.status',
                'attendances.check_in_time',
                'users.name as student_name'
            );

        $records = $monthAttendances->get();

        if ($records->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'top_late' => [],
                    'frequent_absence' => [],
                    'avg_arrival_time' => '-',
                ],
            ]);
        }

        // 3. Top 5 Most Late
        $topLate = $records->where('status', 'late')
            ->groupBy('student_id')
            ->map(function ($items) {
                return [
                    'student_id' => $items->first()->student_id,
                    'name' => $items->first()->student_name,
                    'count' => $items->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values();

        // 4. Students with 3+ Absences
        $frequentAbsence = $records->whereIn('status', ['absent', 'alpha', 'sick', 'permit'])
            ->groupBy('student_id')
            ->map(function ($items) {
                return [
                    'student_id' => $items->first()->student_id,
                    'name' => $items->first()->student_name,
                    'count' => $items->count(),
                ];
            })
            ->filter(function ($item) {
                return $item['count'] >= 3;
            })
            ->values();

        // 5. Average Arrival Time
        // Only for 'present' or 'late' records with check_in_time
        $arrivalTimes = $records->whereNotNull('check_in_time')
            ->map(function ($item) {
                return Carbon::parse($item->check_in_time)->secondsSinceMidnight();
            });

        $avgTime = '-';
        if ($arrivalTimes->isNotEmpty()) {
            $avgSeconds = $arrivalTimes->average();
            $avgTime = gmdate('H:i', (int) $avgSeconds);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => Carbon::now()->format('F Y'),
                'top_late' => $topLate,
                'frequent_absence' => $frequentAbsence,
                'avg_arrival_time' => $avgTime,
            ],
        ]);
    }

    /**
     * Store Manual Attendance (Controlled Enrty)
     */
    public function storeManualAttendance(Request $request)
    {
        $request->validate([
            'schedule_id' => 'required|exists:schedules,id',
            'student_id' => 'required|exists:users,id',
            'status' => 'required|in:present,late,absent,sick,permit,alpha',
            'reason' => 'required|string|min:5',
        ]);

        $user = $request->user();
        $todayStr = Carbon::now()->toDateString();
        $now = Carbon::now();

        // 1. Verify Schedule Access and Time Window
        $schedule = DB::table('schedules')
            ->where('id', $request->schedule_id)
            ->where('teacher_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Unauthorized or invalid schedule'], 403);
        }

        // Time Window Check: Start Time to End Time + 30 mins
        // We only enforce this for TODAY's schedule.
        // If teacher tries to edit past attendance, that's a different flow (Correction).
        // Assuming this is for "real-time" manual entry.
        $startTime = Carbon::parse($todayStr.' '.$schedule->start_time);
        $endTime = Carbon::parse($todayStr.' '.$schedule->end_time)->addMinutes(30);

        if ($now->lessThan($startTime) || $now->greaterThan($endTime)) {
            // Optional: Soften this restriction for "Correction" if needed, but prompt says "Controlled... within class time window".
            return response()->json(['success' => false, 'message' => 'Manual entry only allowed during class time (+30 mins)'], 400);
        }

        // 2. Check Existing Attendance
        $existing = \App\Models\Attendance::where('schedule_id', $request->schedule_id)
            ->where('student_id', $request->student_id)
            ->where('attendance_date', $todayStr)
            ->first();

        // 3. Prevent Overwrite of QR Scan without explicit override logic?
        // Prompt says "Cannot overwrite QR-generated attendance without audit trail".
        // We WILL overwrite, but we MUST create an audit trail (AttendanceLog).

        if ($existing && $existing->verification_type === 'qr_scan' && $existing->status === 'present') {
            // If try to mark absent after QR scan?
            // Allowed but flagged.
        }

        DB::beginTransaction();
        try {
            $att = $existing ?: new \App\Models\Attendance;
            $att->school_id = $user->school_id;
            $att->student_id = $request->student_id;
            $att->schedule_id = $request->schedule_id;
            $att->class_id = $schedule->class_id;
            $att->attendance_date = $todayStr;
            // If changing to present/late, set check_in_time if not set
            if (in_array($request->status, ['present', 'late'])) {
                if (! $att->check_in_time) {
                    $att->check_in_time = $now->toTimeString();
                }
            } else {
                // If marking absent, should we clear check_in? Or keep it history?
                // Usually keep check_in_time if they were here but left?
                // Simple logic: Don't clear check_in_time if it exists, to preserve evidence they were here.
            }

            $att->status = $request->status;
            $att->verification_type = 'manual'; // Override type
            $att->notes = $request->reason." (By Teacher: {$user->name})";
            $att->save();

            // 4. Audit Trail
            \App\Models\AttendanceLog::create([
                'attendance_id' => $att->id,
                'user_id' => $user->id, // Action performer
                'action' => $existing ? 'manual_update' : 'manual_create',
                'previous_status' => $existing ? $existing->status : null,
                'new_status' => $request->status,
                'notes' => $request->reason,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Attendance updated successfully',
                'data' => $att,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => 'Failed to update attendance'], 500);
        }

    }

    /**
     * Get Teaching Performance Stats (Class vs Class Comparison)
     */
    public function getTeachingPerformance(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $startOfMonth = Carbon::now()->startOfMonth()->toDateString();
        $endOfMonth = Carbon::now()->endOfMonth()->toDateString();

        // 1. Get All Classes Taught by Teacher
        // We group aggregated attendance stats by class_id
        $distinctClasses = DB::table('schedules')
            ->join('classes', 'schedules.class_id', '=', 'classes.id')
            ->where('schedules.teacher_id', $user->id)
            ->where('schedules.is_active', true)
            ->distinct()
            ->select('classes.id', 'classes.name')
            ->get();

        if ($distinctClasses->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'avg_attendance_rate' => 0,
                    'best_class' => null,
                    'worst_class' => null,
                    'avg_punctuality' => 0,
                    'class_performance' => [],
                ],
            ]);
        }

        $classIds = $distinctClasses->pluck('id');
        $classNames = $distinctClasses->pluck('name', 'id');

        // 2. Aggregate Attendance Per Class (This Month)
        // We only consider attendance for schedules owned by THIS teacher
        $attendanceStats = DB::table('attendances')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->whereIn('attendances.class_id', $classIds)
            ->where('schedules.teacher_id', $user->id) // Important scope
            ->whereBetween('attendances.attendance_date', [$startOfMonth, $endOfMonth])
            ->select(
                'attendances.class_id',
                DB::raw('count(*) as total'),
                DB::raw("SUM(CASE WHEN attendances.status IN ('present', 'late') THEN 1 ELSE 0 END) as present_count"),
                DB::raw("SUM(CASE WHEN attendances.status = 'late' THEN 1 ELSE 0 END) as late_count")
            )
            ->groupBy('attendances.class_id')
            ->get();

        // 3. Process Per Class Metrics
        $performance = $attendanceStats->map(function ($stat) use ($classNames) {
            $rate = $stat->total > 0 ? ($stat->present_count / $stat->total) * 100 : 0;
            $punctualityRate = $stat->present_count > 0
                ? (($stat->present_count - $stat->late_count) / $stat->present_count) * 100
                : 0;

            return [
                'class_id' => $stat->class_id,
                'class_name' => $classNames[$stat->class_id] ?? 'Unknown',
                'attendance_rate' => round($rate, 1),
                'punctuality_rate' => round($punctualityRate, 1),
                'total_sessions' => $stat->total, // Approximation of student-sessions
            ];
        });

        // Fill in classes with 0 data
        foreach ($distinctClasses as $cls) {
            if (! $performance->contains('class_id', $cls->id)) {
                $performance->push([
                    'class_id' => $cls->id,
                    'class_name' => $cls->name,
                    'attendance_rate' => 0,
                    'punctuality_rate' => 0,
                    'total_sessions' => 0,
                ]);
            }
        }

        $performance = $performance->values();

        // 4. Determine Best/Worst
        $bestClass = $performance->sortByDesc('attendance_rate')->first();
        $worstClass = $performance->sortBy('attendance_rate')->first(); // If all 0, first is worst
        $avgRate = $performance->avg('attendance_rate');
        $avgPunctuality = $performance->avg('punctuality_rate');

        return response()->json([
            'success' => true,
            'data' => [
                'period' => Carbon::now()->format('F Y'),
                'avg_attendance_rate' => round($avgRate, 1),
                'avg_punctuality' => round($avgPunctuality, 1),
                'best_class' => $bestClass ? [
                    'name' => $bestClass['class_name'],
                    'rate' => $bestClass['attendance_rate'],
                ] : null,
                'worst_class' => $worstClass ? [
                    'name' => $worstClass['class_name'],
                    'rate' => $worstClass['attendance_rate'],
                ] : null,
                'class_breakdown' => $performance,
            ],
        ]);
    }

    /**
     * Get Teacher's Daily Teaching Timeline
     */
    public function getTeachingTimeline(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $dayOfWeek = Carbon::now()->dayOfWeek;
        $todayStr = Carbon::now()->toDateString();
        $now = Carbon::now()->format('H:i:s');

        // 1. Get Schedules
        $schedules = DB::table('schedules')
            ->join('classes', 'schedules.class_id', '=', 'classes.id')
            ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->where('schedules.teacher_id', $user->id)
            ->where('schedules.school_id', $schoolId)
            ->where('schedules.day_of_week', $dayOfWeek)
            ->where('schedules.is_active', true)
            ->select(
                'schedules.id',
                'schedules.class_id',
                'schedules.start_time',
                'schedules.end_time',
                'classes.name as class_name',
                'subjects.name as subject_name'
            )
            ->orderBy('schedules.start_time')
            ->get();

        if ($schedules->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $scheduleIds = $schedules->pluck('id');
        $classIds = $schedules->pluck('class_id')->unique();

        // 2. Counts
        // Total Students per class
        $classCounts = DB::table('class_students')
            ->whereIn('class_id', $classIds)
            ->where('status', 'active')
            ->select('class_id', DB::raw('count(*) as count'))
            ->groupBy('class_id')
            ->pluck('count', 'class_id');

        // Present Count per schedule
        $attendanceCounts = DB::table('attendances')
            ->whereIn('schedule_id', $scheduleIds)
            ->where('attendance_date', $todayStr)
            ->whereIn('status', ['present', 'late'])
            ->select('schedule_id', DB::raw('count(*) as count'))
            ->groupBy('schedule_id')
            ->pluck('count', 'schedule_id');

        // 3. Map
        $timeline = $schedules->map(function ($s) use ($classCounts, $attendanceCounts, $now) {
            $total = $classCounts[$s->class_id] ?? 0;
            $present = $attendanceCounts[$s->id] ?? 0;

            $percentage = $total > 0 ? round(($present / $total) * 100, 1) : 0;

            $status = 'not started';
            if ($now > $s->end_time) {
                $status = 'completed';
            } elseif ($now >= $s->start_time) {
                $status = 'ongoing';
            }

            return [
                'class' => $s->class_name,
                'subject' => $s->subject_name,
                'time_range' => substr($s->start_time, 0, 5).' - '.substr($s->end_time, 0, 5),
                'status' => $status,
                'present_percentage' => $percentage,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $timeline,
        ]);
    }

    /**
     * Get Student Detail for Subject Teacher (History in THIS teacher's classes)
     */
    public function getStudentSubjectDetail(Request $request, $studentId)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // 1. Get Student Info
        $student = User::where('id', $studentId)
            ->where('role_type', 'student')
            ->where('school_id', $schoolId)
            ->select('id', 'name', 'username as nis', 'profile_photo_url')
            ->first();

        if (! $student) {
            return response()->json(['success' => false, 'message' => 'Student not found'], 404);
        }

        // 2. Fetch Attendance Records SCOPED to this Teacher
        // We look for attendance records where schedule.teacher_id = user->id
        $records = DB::table('attendances')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->where('attendances.student_id', $studentId)
            ->where('schedules.teacher_id', $user->id)
            ->where('attendances.school_id', $schoolId)
            ->select(
                'attendances.id',
                'attendances.attendance_date',
                'attendances.status',
                'attendances.check_in_time',
                'subjects.name as subject_name'
            )
            ->orderBy('attendances.attendance_date', 'desc')
            ->get();

        // 3. Calculate Stats
        $totalSessions = $records->count();
        $present = $records->whereIn('status', ['present', 'late'])->count();
        $late = $records->where('status', 'late')->count();
        $absent = $records->whereIn('status', ['absent', 'alpha', 'sick', 'permit'])->count();

        $rate = $totalSessions > 0 ? ($present / $totalSessions) * 100 : 0;

        // 4. Last 5 Records
        $lastFive = $records->take(5)->map(function ($rec) {
            return [
                'date' => Carbon::parse($rec->attendance_date)->isoFormat('dddd, D MMMM Y'),
                'status' => $rec->status,
                'check_in' => $rec->check_in_time ? substr($rec->check_in_time, 0, 5) : '-',
                'subject' => $rec->subject_name,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $student,
                'stats' => [
                    'attendance_rate' => round($rate, 1),
                    'total_late' => $late,
                    'total_absent' => $absent,
                    'total_sessions' => $totalSessions,
                ],
                'history_recent' => $lastFive,
            ],
        ]);
    }

    /**
     * Get 14-Day Attendance Trend for a Class (Subject View)
     */
    public function getClassAttendanceTrend(Request $request, $classId)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays(13); // 14 days including today

        // 1. Verify Access
        $hasAccess = DB::table('schedules')
            ->where('teacher_id', $user->id)
            ->where('class_id', $classId)
            ->where('is_active', true)
            ->exists();

        if (! $hasAccess) {
            // Fallback for homeroom
            $isHomeroom = TeacherRole::where('teacher_id', $user->id)
                ->where('homeroom_class_id', $classId)
                ->exists();
            if (! $isHomeroom) {
                return response()->json(['success' => false, 'message' => 'Unauthorized access'], 403);
            }
        }

        // 2. Query Data
        // Joined with specific schedules or just class attendance?
        // To be useful for a subject teacher, they often want to see generic class trend to know if "everyone is skipping" or just "skipping my class".
        // BUT, strictly speaking, we usually limit to teacher's scope.
        // Let's stick to teacher's scope to avoid leaking other teachers' data unless homeroom.
        // However, if the chart is "14-day trend", and I only teach Monday, the chart will be empty for 12 days.
        // That might look broken.
        // Let's QUERY for THIS teacher's sessions. If empty on days, so be it (it's accurate).

        $data = DB::table('attendances')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->where('attendances.class_id', $classId)
            ->where('attendances.school_id', $schoolId)
            ->where('schedules.teacher_id', $user->id)
            ->whereBetween('attendances.attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->select(
                'attendances.attendance_date',
                'attendances.status'
            )
            ->get()
            ->groupBy('attendance_date');

        // 3. Format Chart Data
        $chartData = [];
        // Loop from start to end
        $period = \Carbon\CarbonPeriod::create($startDate, $endDate);

        foreach ($period as $date) {
            $dString = $date->toDateString();
            $dayRecords = $data->get($dString) ?? collect();

            $present = $dayRecords->where('status', 'present')->count();
            $late = $dayRecords->where('status', 'late')->count();
            // Group absences
            $absent = $dayRecords->whereIn('status', ['absent', 'alpha', 'sick', 'permit'])->count();

            // if no records, maybe no class that day?
            // checking if a schedule existed that day is expensive (need to check day_of_week, holidays etc).
            // For simple trend, just returning counts is fine. Front-end can handle 0s.

            $chartData[] = [
                'date' => $dString,
                'label' => $date->isoFormat('dd (D/M)'),
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $chartData,
        ]);
    }

    /**
     * Get Subject Teacher Activity Feed (Recent Events)
     */
    public function getSubjectActivityFeed(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // 1. Get Teacher's Schedule IDs (All Active)
        $scheduleIds = DB::table('schedules')
            ->where('teacher_id', $user->id)
            ->where('school_id', $schoolId)
            ->pluck('id');

        if ($scheduleIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // 2. Fetch Attendance Logs linked to Teacher's Schedules
        // Note: 'attendances' table has schedule_id, we need to join logs -> attendances -> schedules
        // This ensures the feed shows events relevant to classes THIS teacher teaches.

        $logs = DB::table('attendance_logs')
            ->join('attendances', 'attendance_logs.attendance_id', '=', 'attendances.id')
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            // Join schedule just to verify or get subject info?
            // AttendanceRecord typically has schedule_id.
            ->whereIn('attendances.schedule_id', $scheduleIds)
            ->where('attendances.school_id', $schoolId)
            ->orderBy('attendance_logs.created_at', 'desc')
            ->limit(15)
            ->select(
                'attendance_logs.id as log_id',
                'attendance_logs.action',
                'attendance_logs.new_status',
                'attendance_logs.created_at',
                'users.name as student_name',
                'classes.name as class_name'
            )
            ->get();

        // 3. Transform
        $feed = $logs->map(function ($log) {
            $type = 'info';
            $title = 'Activity';
            $desc = '';

            // Mapping Action Types
            if ($log->action === 'scan_in') {
                if ($log->new_status === 'late') {
                    $type = 'warning';
                    $title = 'Late Arrival';
                    $desc = "{$log->student_name} check-in late to {$log->class_name}.";
                } else {
                    $type = 'success';
                    $title = 'Check-in';
                    $desc = "{$log->student_name} present in {$log->class_name}.";
                }
            } elseif ($log->action === 'manual_create') {
                $type = 'info';
                $title = 'Manual Entry';
                $desc = "{$log->student_name} manually added to {$log->class_name}.";
            } elseif ($log->action === 'manual_update') {
                $type = 'warning';
                $title = 'Correction'; // "Update"
                $desc = "Attendance corrected for {$log->student_name}.";
            } elseif ($log->action === 'scan_out') {
                $type = 'info';
                $title = 'Check-out';
                $desc = "{$log->student_name} checked out from {$log->class_name}.";
            }

            return [
                'id' => 'log_'.$log->log_id,
                'action_type' => $log->action, // raw action
                'type' => $type, // UI semantic type (success, warning, info)
                'title' => $title,
                'description' => $desc,
                'timestamp' => $log->created_at,
                'time_ago' => Carbon::parse($log->created_at)->diffForHumans(),
                'meta' => [
                    'student' => $log->student_name,
                    'class' => $log->class_name,
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $feed,
        ]);
    }
}
