<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeacherRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiskDashboardController extends Controller
{
    /**
     * Get Role-Based Risk Dashboard
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. SUPER ADMIN: Aggregate by School
        if ($user->hasRole('super_admin')) {
            return $this->getSuperAdminStats();
        }

        // 2. SCHOOL CONTEXT (Admin or Teacher)
        $schoolId = $user->school_id;
        
        // Base Query for LATEST risks
        // We select the latest risk entry for each student in the school
        // Strategy: Subquery to get max ID per student
        $latestRiskIds = DB::table('student_attendance_risk')
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('student_id');

        $query = DB::table('student_attendance_risk')
            ->joinSub($latestRiskIds, 'latest_risks', function ($join) {
                $join->on('student_attendance_risk.id', '=', 'latest_risks.id');
            })
            ->join('users', 'student_attendance_risk.student_id', '=', 'users.id')
            ->where('users.school_id', $schoolId)
            ->where('users.is_active', true);

        // 3. FILTER BY ROLE
        $roleLabel = 'school_admin';
        
        if ($user->hasRole('teacher')) {
            // Check Homeroom
            $academicYearId = DB::table('academic_years')
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->value('id') ?? 1;

            $teacherRole = TeacherRole::where('teacher_id', $user->id)
                ->where('academic_year_id', $academicYearId)
                ->first();

            if (!$teacherRole || !$teacherRole->is_homeroom_teacher) {
                return response()->json([
                    'status' => 'success',
                    'role' => 'teacher',
                    'message' => 'Not a homeroom teacher.',
                    'data' => [
                        'summary' => ['high' => 0, 'medium' => 0, 'low' => 0],
                        'students' => []
                    ]
                ]);
            }

            $classId = $teacherRole->homeroom_class_id;
            $roleLabel = 'homeroom_teacher';

            // Filter by Class
            $query->join('class_students', 'users.id', '=', 'class_students.student_id')
                  ->where('class_students.class_id', $classId)
                  ->where('class_students.status', 'active');
        }

        // Select Fields
        $results = $query->select(
            'users.id as student_id',
            'users.name as student_name',
            'users.profile_photo_url',
            'student_attendance_risk.risk_score',
            'student_attendance_risk.risk_level',
            'student_attendance_risk.calculated_at',
            'student_attendance_risk.factors_json'
        )->get();

        // 4. PREPARE RESPONSE
        
        // Summary Counts
        $high = $results->where('risk_level', 'High')->count(); // Case sensitive match from service? Service uses 'High', 'Medium', 'Low'
        // Actually Service uses 'High' (capitalized). DB migration string.
        // Let's normalize just in case.
        $summary = [
            'high' => $results->filter(fn($r) => strtolower($r->risk_level) === 'high')->count(),
            'medium' => $results->filter(fn($r) => strtolower($r->risk_level) === 'medium')->count(),
            'low' => $results->filter(fn($r) => strtolower($r->risk_level) === 'low')->count(),
        ];

        // Format Students List
        $students = $results->map(function ($row) {
             return [
                 'id' => $row->student_id,
                 'name' => $row->student_name,
                 'photo' => $row->profile_photo_url,
                 'risk' => [
                     'score' => $row->risk_score,
                     'level' => $row->risk_level, // Keep original casing
                     'last_updated' => $row->calculated_at,
                     'factors_count' => count(json_decode($row->factors_json) ?? [])
                 ]
             ];
        })->sortByDesc('risk.score')->values();

        return response()->json([
            'status' => 'success',
            'role' => $roleLabel,
            'data' => [
                'summary' => $summary,
                'students' => $students
            ]
        ]);
    }

    private function getSuperAdminStats()
    {
        // Aggregate risk stats per school
        $stats = DB::table('schools')
            ->select('schools.id', 'schools.name')
            // This is a complex aggregation if we want "Latest" risks per student per school.
            // Simplified approach: Join users, join risks.
            // CAUTION: This counts ALL risk history if we aren't careful.
            // We need "Latest Risk Per Student".
            
            // Subquery for latest risks
            ->leftJoin('users', 'schools.id', '=', 'users.school_id')
            ->leftJoin('student_attendance_risk', function($join) {
                $join->on('users.id', '=', 'student_attendance_risk.student_id')
                     // Ensure we only get the latest record? 
                     // Row_number() is best but complex in basic Eloquent.
                     // Let's use a WHERE In subquery approach or just join the latest ID subquery again.
                     ->whereRaw('student_attendance_risk.id = (SELECT MAX(id) FROM student_attendance_risk as sar WHERE sar.student_id = users.id)');
            })
            ->where('users.role_type', 'student')
            ->select(
                'schools.id',
                'schools.name',
                DB::raw("COUNT(CASE WHEN student_attendance_risk.risk_level = 'High' THEN 1 END) as high_risk"),
                DB::raw("COUNT(CASE WHEN student_attendance_risk.risk_level = 'Medium' THEN 1 END) as medium_risk")
            )
            ->groupBy('schools.id', 'schools.name')
            ->get();

        return response()->json([
            'status' => 'success',
            'role' => 'super_admin',
            'data' => $stats
        ]);
    }
    /**
     * Analyze student arrival (punctuality) patterns.
     */
    public function getStudentArrivalAnalytics(Request $request, $studentId)
    {
        $user = $request->user();
        
        // Ensure access (School Admin or Teacher)
        // Basic check: must be same school
        $targetStudent = User::where('id', $studentId)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        // 30 Days Config
        $startDate = \Carbon\Carbon::now()->subDays(30)->toDateString();
        $records = DB::table('attendances')
            ->where('student_id', $studentId)
            ->where('attendance_date', '>=', $startDate)
            ->whereNotNull('check_in_time')
            ->select('attendance_date', 'check_in_time', 'status')
            ->get();

        if ($records->isEmpty()) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'avg_arrival' => 'N/A',
                    'most_frequent_range' => 'N/A',
                    'weekday_breakdown' => []
                ]
            ]);
        }

        // 1. Average Arrival Time
        $totalSeconds = 0;
        $count = 0;
        
        // 2. Frequency Buckets (10 mins)
        // Ranges: 06:00-06:10, etc.
        $buckets = [];

        // 3. Late per Weekday
        $weekdays = [
            'Monday' => ['total' => 0, 'late' => 0],
            'Tuesday' => ['total' => 0, 'late' => 0],
            'Wednesday' => ['total' => 0, 'late' => 0],
            'Thursday' => ['total' => 0, 'late' => 0],
            'Friday' => ['total' => 0, 'late' => 0],
            'Saturday' => ['total' => 0, 'late' => 0],
            'Sunday' => ['total' => 0, 'late' => 0],
        ];

        foreach ($records as $rec) {
            $time = \Carbon\Carbon::parse($rec->check_in_time);
            $seconds = $time->secondsSinceMidnight();
            
            // Avg calc
            $totalSeconds += $seconds;
            $count++;

            // Bucket
            // Round down to nearest 10 min
            $minute = $time->minute;
            $bucketStartMinute = floor($minute / 10) * 10;
            $bucketTime = $time->copy()->setMinute($bucketStartMinute)->setSecond(0);
            $bucketLabel = $bucketTime->format('H:i') . ' - ' . $bucketTime->addMinutes(10)->format('H:i');
            
            if (!isset($buckets[$bucketLabel])) {
                $buckets[$bucketLabel] = 0;
            }
            $buckets[$bucketLabel]++;

            // Weekday
            $dayName = \Carbon\Carbon::parse($rec->attendance_date)->englishDayOfWeek;
            if (isset($weekdays[$dayName])) {
                $weekdays[$dayName]['total']++;
                if ($rec->status === 'late') {
                    $weekdays[$dayName]['late']++;
                }
            }
        }

        // Final Calculations
        $avgSeconds = $count > 0 ? $totalSeconds / $count : 0;
        $avgArrival = gmdate('H:i:s', $avgSeconds);

        // Sort Buckets & Pick Top
        arsort($buckets);
        $topRange = array_key_first($buckets);

        // Probability
        $breakdown = [];
        foreach ($weekdays as $day => $stats) {
            if ($stats['total'] > 0) {
                $prob = round(($stats['late'] / $stats['total']) * 100, 1);
                $breakdown[$day] = [
                    'late_probability' => $prob,
                    'total_visits' => $stats['total'],
                    'late_count' => $stats['late']
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'avg_arrival' => $avgArrival,
                'most_frequent_range' => $topRange ?? 'N/A',
                'weekday_breakdown' => $breakdown
            ]
        ]);
    }
    /**
     * Detect time-based risk patterns (Morning vs Afternoon).
     */
    public function getTimeBasedRiskAnalytics(Request $request, $studentId)
    {
        $user = $request->user();

        $targetStudent = User::where('id', $studentId)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        // 30 Days Lookback
        $startDate = \Carbon\Carbon::now()->subDays(30)->toDateString();
        
        // We need schedule start times. Join attendances with schedules.
        // If schedule_id is null, we can't determine "planned" time for absences easily.
        // Assuming session-based attendance where schedule_id is populated.
        
        $records = DB::table('attendances')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->where('attendances.student_id', $studentId)
            ->where('attendances.attendance_date', '>=', $startDate)
            ->select(
                'attendances.status',
                'schedules.start_time',
                'schedules.end_time'
            )
            ->get();

        // Initialize Buckets
        $morningStats = ['total' => 0, 'late' => 0];
        $afternoonStats = ['total' => 0, 'absent' => 0];

        foreach ($records as $rec) {
            $startTime = $rec->start_time; // HH:mm:ss
            
            // Morning: Starts before 09:00
            if ($startTime < '09:00:00') {
                $morningStats['total']++;
                if ($rec->status === 'late') {
                    $morningStats['late']++;
                }
            } 
            // Afternoon: Starts after 12:00
            elseif ($startTime >= '12:00:00') {
                $afternoonStats['total']++;
                if (in_array($rec->status, ['absent', 'alpha', 'sick', 'permit'])) {
                    // Treating all 'not present' as absence for disengagement check? 
                    // Prompt says "Absent", usually implies Alpha/Absent. 
                    // Let's stick to strict Absent/Alpha for "Disengagement".
                    if (in_array($rec->status, ['absent', 'alpha'])) {
                        $afternoonStats['absent']++;
                    }
                }
            }
        }

        // Calculate Rates & Flags
        $flags = [];

        // 1. Morning Late Analysis
        $morningLateRate = 0;
        if ($morningStats['total'] > 0) {
            $morningLateRate = round(($morningStats['late'] / $morningStats['total']) * 100, 1);
        }
        
        if ($morningLateRate >= 20 || $morningStats['late'] >= 3) {
            $flags[] = [
                'pattern' => 'morning_discipline',
                'label' => 'Morning Discipline Issue',
                'detail' => "Frequently late in morning sessions (< 09:00). Rate: {$morningLateRate}% ({$morningStats['late']} times)"
            ];
        }

        // 2. Afternoon Absence Analysis
        $afternoonAbsenceRate = 0;
        if ($afternoonStats['total'] > 0) {
            $afternoonAbsenceRate = round(($afternoonStats['absent'] / $afternoonStats['total']) * 100, 1);
        }

        if ($afternoonAbsenceRate >= 15 || $afternoonStats['absent'] >= 2) {
             $flags[] = [
                'pattern' => 'afternoon_disengagement',
                'label' => 'Afternoon Disengagement',
                'detail' => "Frequently absent after lunch (> 12:00). Rate: {$afternoonAbsenceRate}% ({$afternoonStats['absent']} times)"
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'morning_session' => [
                    'total' => $morningStats['total'],
                    'late_count' => $morningStats['late'],
                    'late_rate' => $morningLateRate
                ],
                'afternoon_session' => [
                    'total' => $afternoonStats['total'],
                    'absent_count' => $afternoonStats['absent'],
                    'absent_rate' => $afternoonAbsenceRate
                ],
                'risk_flags' => $flags
            ]
        ]);
    }

    /**
     * Detect sudden behavior changes (Attendance drop vs previous month).
     */
    public function getBehaviorChangeAnalytics(Request $request, $studentId)
    {
        $user = $request->user();

        // 1. Periods
        $now = \Carbon\Carbon::now();
        $currentStart = $now->copy()->subDays(30)->toDateString();
        $prevStart = $now->copy()->subDays(60)->toDateString();
        $prevEnd = $now->copy()->subDays(31)->toDateString(); // Non-overlapping

        // 2. Fetch Aggregates
        // Helper to get stats
        $getStats = function ($start, $end) use ($studentId) {
            $stats = DB::table('attendances')
                ->where('student_id', $studentId)
                ->whereBetween('attendance_date', [$start, $end])
                ->selectRaw("
                    count(*) as total,
                    sum(case when status in ('present', 'late') then 1 else 0 end) as present,
                    sum(case when status = 'late' then 1 else 0 end) as late
                ")
                ->first();
            
            $rate = ($stats->total > 0) ? round(($stats->present / $stats->total) * 100, 1) : 0;
            // Handle edge case: if no records in prev period, assume 100% rate to detect drops if they suddenly start skipping?
            // Or assume 0? Usually 100 is safer baseline to detect "Drop" if they were previously good (or unrecorded).
            // But if total is 0, it means no data. Let's keep 0 and handle comparisons carefully.
            
            return [
                'rate' => $rate,
                'late' => (int) $stats->late,
                'total_records' => $stats->total
            ];
        };

        $current = $getStats($currentStart, $now->toDateString());
        $previous = $getStats($prevStart, $prevEnd);

        // 3. Compare Rules
        $flags = [];

        // Rule 1: Drop in attendance >= 20%
        // Only valid if previous period had data or assumed good.
        // If previous has 0 records, we can't truly say it dropped unless we assume they attended.
        // Let's only flag if previous had records.
        $attendanceDrop = 0;
        if ($previous['total_records'] > 5) {
            $attendanceDrop = $previous['rate'] - $current['rate'];
            if ($attendanceDrop >= 20) {
                $flags[] = 'ATTENDANCE_PLUMMET';
            }
        }

        // Rule 2: Increase in late freq >= 50%
        // Metric: Late Count Increase %
        $lateIncreasePct = 0;
        if ($previous['late'] > 0) {
            $lateIncreasePct = (($current['late'] - $previous['late']) / $previous['late']) * 100;
        } elseif ($current['late'] > 0) {
            // 0 -> X is infinite increase
            $lateIncreasePct = 100; // Treat as 100% for flag Logic
        }

        // Add filter: Significance threshold (e.g., must have at least 3 lates now to care about 50% increase)
        if ($lateIncreasePct >= 50 && $current['late'] >= 3) {
            $flags[] = 'LATE_SPIKE';
        }

        // Determine Primary Flag
        $behaviorFlag = null;
        if (!empty($flags)) {
            // Priority: Plummet > Spike
            if (in_array('ATTENDANCE_PLUMMET', $flags)) {
                $behaviorFlag = 'ATTENDANCE_PLUMMET';
            } else {
                $behaviorFlag = 'LATE_SPIKE';
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'current_period' => $current,
                'previous_period' => $previous,
                'changes' => [
                    'attendance_drop_points' => $attendanceDrop,
                    'late_increase_pct' => round($lateIncreasePct, 1)
                ],
                'behavior_change_flag' => $behaviorFlag,
                'details' => $flags // Full list
            ]
        ]);
    }

    /**
     * Predict late arrival risk for next week based on historical trends.
     */
    public function getLateArrivalPrediction(Request $request, $studentId)
    {
        $user = $request->user();

        // 4 Weeks Lookback
        $startDate = \Carbon\Carbon::now()->subWeeks(4)->toDateString();

        $records = DB::table('attendances')
            ->where('student_id', $studentId)
            ->where('attendance_date', '>=', $startDate)
            ->select('attendance_date', 'status')
            ->get();

        $weekdays = [
            'Monday' => ['total' => 0, 'late' => 0],
            'Tuesday' => ['total' => 0, 'late' => 0],
            'Wednesday' => ['total' => 0, 'late' => 0],
            'Thursday' => ['total' => 0, 'late' => 0],
            'Friday' => ['total' => 0, 'late' => 0],
            'Saturday' => ['total' => 0, 'late' => 0], // If applicable
            'Sunday' => ['total' => 0, 'late' => 0],   // If applicable
        ];

        foreach ($records as $rec) {
            $dayName = \Carbon\Carbon::parse($rec->attendance_date)->englishDayOfWeek;
            if (isset($weekdays[$dayName])) {
                $weekdays[$dayName]['total']++;
                if ($rec->status === 'late') {
                    $weekdays[$dayName]['late']++;
                }
            }
        }

        $predictions = [];
        $breakdown = [];

        foreach ($weekdays as $day => $stats) {
            if ($stats['total'] > 0) {
                $lateRate = ($stats['late'] / $stats['total']) * 100;
                $breakdown[$day] = round($lateRate, 1);

                if ($lateRate > 40) {
                    $predictions[] = [
                        'day' => $day,
                        'risk' => 'High',
                        'message' => "High {$day} Late Risk (> 40% probability)"
                    ];
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'predictions' => $predictions,
                'weekday_late_probabilities' => $breakdown
            ]
        ]);
    }

    /**
     * Generate comprehensive behavior insight summary for a student.
     */
    public function getStudentBehaviorSummary(Request $request, $studentId)
    {
        $user = $request->user();

        // Access Check
        $targetStudent = User::where('id', $studentId)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        // 1. DATA: Last 60 Days
        $sixtyDaysAgo = \Carbon\Carbon::now()->subDays(60)->toDateString();
        
        $records = DB::table('attendances')
            ->leftJoin('schedules', 'attendances.schedule_id', '=', 'schedules.id') // Join optional
            ->leftJoin('subjects', 'schedules.subject_id', '=', 'subjects.id')     // Join optional
            ->where('attendances.student_id', $studentId)
            ->where('attendances.attendance_date', '>=', $sixtyDaysAgo)
            ->select(
                'attendances.attendance_date',
                'attendances.status',
                'attendances.check_in_time',
                'subjects.name as subject_name',
                'schedules.subject_id'
            )
            ->get();

        // 2. ANALYZE: Most Frequent Late Day
        $latesByDay = [];
        $absencesBySubject = [];
        $totalPresentLate = 0;
        $varianceSum = 0;
        $times = [];

        foreach ($records as $rec) {
            $day = \Carbon\Carbon::parse($rec->attendance_date)->englishDayOfWeek;

            // Late Day
            if ($rec->status === 'late') {
                if (!isset($latesByDay[$day])) $latesByDay[$day] = 0;
                $latesByDay[$day]++;
            }

            // Problematic Subject (Absence/Alpha/Late?)
            // Usually "Problematic" implies missing class.
            if (in_array($rec->status, ['absent', 'alpha', 'late']) && $rec->subject_name) {
                $sub = $rec->subject_name;
                if (!isset($absencesBySubject[$sub])) $absencesBySubject[$sub] = 0;
                $absencesBySubject[$sub]++;
            }

            // Consistency (Arrival Time Variance)
            if ($rec->check_in_time) {
                $times[] = \Carbon\Carbon::parse($rec->check_in_time)->secondsSinceMidnight();
                $totalPresentLate++;
            }
        }

        // A. Frequent Late Day
        $mostFrequentLateDay = 'None';
        if (!empty($latesByDay)) {
            arsort($latesByDay);
            $mostFrequentLateDay = array_key_first($latesByDay) . " (" . current($latesByDay) . ")";
        }

        // B. Problematic Subject
        $mostProblematicSubject = 'None';
        if (!empty($absencesBySubject)) {
            arsort($absencesBySubject);
            $mostProblematicSubject = array_key_first($absencesBySubject) . " (" . current($absencesBySubject) . " issues)";
        }

        // C. Consistency Score (Standard Deviation of Arrival Time)
        // Lower std dev = Higher consistency.
        // Map 0-30m std dev to 0-100 score? 
        // 0 variance = 100 score. 60 min variance = 0 score.
        $consistencyScore = 100; // Default perfect
        if (count($times) > 1) {
            $mean = array_sum($times) / count($times);
            foreach ($times as $t) {
                $varianceSum += pow($t - $mean, 2);
            }
            $stdDevSeconds = sqrt($varianceSum / count($times));
            $stdDevMinutes = $stdDevSeconds / 60;
            
            // Formula: 100 - (StdDevMins * 2). If deviation is 10 mins, score 80. 30 mins, score 40.
            $consistencyScore = max(0, 100 - ($stdDevMinutes * 2));
        } elseif (count($times) == 0 && $records->count() > 0) {
            // Absent all time? 0 consistency
            $consistencyScore = 0;
        }

        // D. Behavior Change Flag reuse (Call internal logic or re-implement simple version)
        // Simple version: 30 vs 30 days Late Count
        $days30 = \Carbon\Carbon::now()->subDays(30);
        $recentLates = $records->where('attendance_date', '>=', $days30->toDateString())->where('status', 'late')->count();
        $oldLates = $records->where('attendance_date', '<', $days30->toDateString())->where('status', 'late')->count();
        
        $changeFlag = 'Stable';
        if ($recentLates > $oldLates && $recentLates >= 3) {
            $changeFlag = 'Degrading (More Lates)';
        } elseif ($recentLates < $oldLates && $oldLates >= 3) {
             $changeFlag = 'Improving';
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'student_id' => $studentId,
                'name' => $targetStudent->name,
                'most_frequent_late_day' => $mostFrequentLateDay,
                'most_problematic_subject' => $mostProblematicSubject,
                'consistency_score' => round($consistencyScore, 1),
                'consistency_label' => $this->getConsistencyLabel($consistencyScore),
                'behavior_trend' => $changeFlag
            ]
        ]);
    }

    private function getConsistencyLabel($score) {
        if ($score >= 90) return 'Very Consistent';
        if ($score >= 75) return 'Consistent';
        if ($score >= 50) return 'Variable';
        return 'Erratic';
    }
    /**
     * Get School-Wide Behavior Analytics (Admin)
     */
    public function getSchoolWideAnalytics(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $thirtyDaysAgo = \Carbon\Carbon::now()->subDays(30)->toDateString();

        // 1. Most Common Late Arrival Time (Bucket logic across whole school)
        $lateTimes = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->where('attendance_date', '>=', $thirtyDaysAgo)
            ->where('status', 'late')
            ->whereNotNull('check_in_time')
            ->pluck('check_in_time');

        $buckets = [];
        foreach ($lateTimes as $timeStr) {
            $time = \Carbon\Carbon::parse($timeStr);
            $minute = $time->minute;
            $bucketStartMinute = floor($minute / 10) * 10;
            $bucketTime = $time->copy()->setMinute($bucketStartMinute)->setSecond(0);
            $bucketLabel = $bucketTime->format('H:i') . ' - ' . $bucketTime->addMinutes(10)->format('H:i');
            
            if (!isset($buckets[$bucketLabel])) $buckets[$bucketLabel] = 0;
            $buckets[$bucketLabel]++;
        }
        arsort($buckets);
        $commonLateTime = !empty($buckets) ? array_key_first($buckets) . " (".current($buckets)." students)" : "N/A";

        // 2. Weekday with Highest Absence Rate
        // Logic: Count absences per weekday / Total records per weekday
        // This is strictly based on 'attendances' table records (scan/mark).
        $absencesByDay = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->where('attendance_date', '>=', $thirtyDaysAgo)
            ->selectRaw("
                DAYNAME(attendance_date) as day_name,
                count(*) as total,
                sum(case when status in ('absent', 'alpha', 'sick') then 1 else 0 end) as absent_count
            ")
            ->groupBy('day_name') // Function depends on DB driver. DAYNAME() is MySQL.
            // Safe fallback if not MySQL: get all and process PHP side? 
            // Assuming MySQL/MariaDB for this specific project context usually.
            // If SQLite (testing), this fails. Let's use PHP aggregation for safety.
            ->get();
            
        // Use PHP aggregation instead of DAYNAME to be safe
        $dayStats = [];
        $records = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->where('attendance_date', '>=', $thirtyDaysAgo)
            ->select('attendance_date', 'status')
            ->get(); // Could be large, but "get" is memory heavy if huge school.
                     // But for "school-wide" usually acceptable. 
        
        foreach ($records as $rec) {
             $day = \Carbon\Carbon::parse($rec->attendance_date)->englishDayOfWeek;
             if (!isset($dayStats[$day])) $dayStats[$day] = ['total' => 0, 'absent' => 0];
             $dayStats[$day]['total']++;
             if (in_array($rec->status, ['absent', 'alpha', 'sick'])) {
                 $dayStats[$day]['absent']++;
             }
        }
        
        $highestAbsenceDay = 'N/A';
        $maxRate = -1;
        
        foreach ($dayStats as $day => $s) {
            if ($s['total'] > 0) {
                $rate = ($s['absent'] / $s['total']) * 100;
                if ($rate > $maxRate) {
                    $maxRate = $rate;
                    $highestAbsenceDay = $day . ' (' . round($rate, 1) . '%)';
                }
            }
        }

        // 3. Subjects with Lowest Attendance Rate
        // Join Schedules -> Subjects
        $subjectStats = DB::table('attendances')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->where('attendances.school_id', $schoolId)
            ->where('attendances.attendance_date', '>=', $thirtyDaysAgo)
            ->selectRaw("
                subjects.name as subject,
                count(*) as total,
                sum(case when attendances.status = 'present' then 1 else 0 end) as present
            ")
            ->groupBy('subjects.name')
            ->orderByRaw("(sum(case when attendances.status = 'present' then 1 else 0 end) / count(*)) ASC")
            ->limit(5)
            ->get();
            
        $lowestSubjects = $subjectStats->map(function($s) {
            $rate = $s->total > 0 ? ($s->present / $s->total) * 100 : 0;
            return [
                'subject' => $s->subject,
                'rate' => round($rate, 1)
            ];
        });

        // 4. Classes with Highest Behavior Change Flags (Degrading)
        // This requires invoking the "Behavior Change" logic for MANY students. Expensive on fly.
        // Simplified approach: Use 'student_attendance_risk' table and count Risk 'High' or 'Medium' increases?
        // Or check 'late' trends per class using aggregate query.
        // Let's count "Total Lates Last 30 Days" per Class vs "Total Lates 31-60 Days Ago".
        
        $classLates = DB::table('class_students')
            ->join('users', 'class_students.student_id', '=', 'users.id')
            ->join('classes', 'class_students.class_id', '=', 'classes.id')
            ->join('attendances', 'users.id', '=', 'attendances.student_id')
            ->where('users.school_id', $schoolId)
            ->where('class_students.status', 'active')
            ->selectRaw("
                classes.name as class_name,
                sum(case when attendances.attendance_date >= ? and attendances.status = 'late' then 1 else 0 end) as recent_lates,
                sum(case when attendances.attendance_date < ? and attendances.attendance_date >= ? and attendances.status = 'late' then 1 else 0 end) as old_lates
            ", [$thirtyDaysAgo, $thirtyDaysAgo, \Carbon\Carbon::now()->subDays(60)->toDateString()])
            ->groupBy('classes.name')
            ->havingRaw('recent_lates > old_lates') // Only degrading
            ->orderByRaw('(recent_lates - old_lates) DESC')
            ->limit(5)
            ->get();

        $degradingClasses = $classLates->map(function($c) {
            $diff = $c->recent_lates - $c->old_lates;
            return [
                'class' => $c->class_name,
                'late_increase' => "+{$diff} lates"
            ];
        });

        // If no records found but logic ran
        if ($degradingClasses->isEmpty()) {
             // Fallback to top classes by Raw Late count if no increase found?
             // Prompt asks for "Behavior Change Flags".
             // If no degrading classes, return empty.
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'common_late_time' => $commonLateTime,
                'highest_absence_day' => $highestAbsenceDay,
                'lowest_attendance_subjects' => $lowestSubjects,
                'classes_with_behavior_issues' => $degradingClasses
            ]
        ]);
    }
}
