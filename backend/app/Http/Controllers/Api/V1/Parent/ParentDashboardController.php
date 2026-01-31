<?php

namespace App\Http\Controllers\Api\V1\Parent;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Http\Request;

class ParentDashboardController extends Controller
{
    use \App\Traits\UsesCacheTags;

    /**
     * Get list of children associated with the logged-in parent
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Get children with their profile and latest attendance
        $children = $user->children()
            ->with(['profile', 'classStudent.class_model', 'attendances' => function ($query) {
                $query->latest()->limit(1);
            }])
            ->get()
            ->map(function ($child) {
                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'nis' => $child->username, // Assuming username is NIS
                    'photo_url' => $child->profile?->photo_url,
                    'class_name' => $child->classStudent?->class_model?->name ?? '-', // Need to check ClassStudent relationship
                    'latest_attendance' => $child->attendances->first(),
                    'relationship' => $child->pivot->relationship,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $children,
        ]);
    }

    public function getDashboardStats(Request $request, $childId)
    {
        $user = $request->user();

        // 1. Verify Parent-Child Relationship
        $child = $user->children()->where('student_parents.student_id', $childId)->first();

        if (! $child) {
            return response()->json([
                'success' => false,
                'message' => 'Child not found or unauthorized access.',
            ], 403);
        }

        $schoolId = $child->school_id;
        $key = "parent_child_dashboard_{$schoolId}_{$childId}";

        // CACHE: 60 seconds
        $data = $this->cacheWithTags(['parent_dashboard', "student_{$childId}"], $key, 60, function () use ($child, $schoolId) {
            $currentDate = \Carbon\Carbon::now();
            $todayStr = $currentDate->toDateString();
            $startOfMonth = $currentDate->copy()->startOfMonth()->toDateString();
            $endOfMonth = $currentDate->copy()->endOfMonth()->toDateString();

            // A. Identity & Class (Fetched via relationship in index, but explicit here)
            // Need to load class relationship if not eager loaded, or query directly
            $classInfo = \Illuminate\Support\Facades\DB::table('users')
                ->join('class_students', 'users.id', '=', 'class_students.student_id')
                ->join('classes', 'class_students.class_id', '=', 'classes.id')
                ->where('users.id', $child->id)
                ->where('class_students.status', 'active')
                ->select('classes.name as class_name', 'users.name')
                ->first();

            $childName = $classInfo->name ?? $child->name;
            $className = $classInfo->class_name ?? '-';

            // B. Today's Status
            $todayRecord = \Illuminate\Support\Facades\DB::table('attendances')
                ->where('student_id', $child->id)
                ->where('attendance_date', $todayStr)
                ->first();

            $statusToday = $todayRecord ? $todayRecord->status : 'not_checked_in';
            $checkInTime = $todayRecord && $todayRecord->check_in_time 
                ? \Carbon\Carbon::parse($todayRecord->check_in_time)->format('H:i') 
                : '-';

            // Override status logic if needed (e.g. absent if not checked in by X time? - Keep simple for now)
            if (!$todayRecord) {
                // Check if it's a holiday or weekend? Assuming standard "Not yet"
                $statusToday = 'not_checked_in';
            }

            // C. Monthly Stats
            $monthlyStats = \Illuminate\Support\Facades\DB::table('attendances')
                ->where('student_id', $child->id)
                ->whereBetween('attendance_date', [$startOfMonth, $endOfMonth])
                ->select(
                    \Illuminate\Support\Facades\DB::raw('count(*) as total'),
                    \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present_count"),
                    \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN status IN ('absent', 'alpha', 'sick', 'permit') THEN 1 ELSE 0 END) as absent_count")
                )
                ->first();

            $totalRecords = $monthlyStats->total ?? 0;
            $attRate = $totalRecords > 0 ? round(($monthlyStats->present_count / $totalRecords) * 100, 1) : 0;
            if ($totalRecords == 0) $attRate = 100; // Default optimistic

            $absencesMonth = (int) ($monthlyStats->absent_count ?? 0);

            // D. Streak (Consecutive Absence)
            $recentRecords = \Illuminate\Support\Facades\DB::table('attendances')
                ->where('student_id', $child->id)
                ->where('attendance_date', '<=', $todayStr)
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

            // E. 7-Day Trend
            $sevenDaysAgo = $currentDate->copy()->subDays(6)->toDateString();
            $trendData = \Illuminate\Support\Facades\DB::table('attendances')
                ->where('student_id', $child->id)
                ->whereBetween('attendance_date', [$sevenDaysAgo, $todayStr])
                ->pluck('status', 'attendance_date');

            $chartData = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = $currentDate->copy()->subDays($i)->toDateString();
                $dayLabel = \Carbon\Carbon::parse($d)->isoFormat('dd');
                $status = $trendData[$d] ?? 'no_data';
                
                // Map status to value for simple chart (1=Present, 0.5=Late, 0=Absent)
                $chartValue = 0;
                if ($status === 'present') $chartValue = 1;
                elseif ($status === 'late') $chartValue = 0.5;
                
                $chartData[] = [
                    'date' => $d,
                    'label' => $dayLabel,
                    'status' => $status,
                    'value' => $chartValue
                ];
            }

            // F. Risk Indicator
            // Green >= 90%
            // Yellow 75-89%
            // Red < 75% or 3+ days streak
            $riskLevel = 'green';
            if ($attRate < 75 || $streak >= 3) {
                $riskLevel = 'red';
            } elseif ($attRate < 90) {
                $riskLevel = 'yellow';
            }

            return [
                'student_profile' => [
                    'name' => $childName,
                    'class' => $className,
                    'photo' => $child->profile_photo_url // Mock or check property
                ],
                'today_status' => [
                    'status' => $statusToday,
                    'check_in_time' => $checkInTime,
                    'is_late' => $statusToday === 'late'
                ],
                'monthly_insight' => [
                    'attendance_rate' => $attRate,
                    'total_absences' => $absencesMonth,
                    'streak_count' => $streak
                ],
                'risk_level' => $riskLevel,
                'weekly_trend' => $chartData
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get attendance history for a specific child
     */
    /**
     * Get attendance history for a specific child
     */
    public function childAttendance(Request $request, $childId)
    {
        $user = $request->user();

        // Verify that this child belongs to the parent
        $child = $user->children()->where('student_parents.student_id', $childId)->first();

        if (! $child) {
            return response()->json([
                'success' => false,
                'message' => 'Siswa tidak ditemukan atau bukan anak Anda.',
            ], 403);
        }

        $query = Attendance::with([
            'schedule.subject:id,name', 
            'schedule.teacher:id,name'
        ])
        ->where('student_id', $childId);

        // Apply Filters
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('attendance_date', [$request->input('start_date'), $request->input('end_date')]);
        } elseif ($request->filled('period')) {
            $period = $request->input('period');
            if ($period === '7_days') {
                $query->where('attendance_date', '>=', \Carbon\Carbon::now()->subDays(7)->toDateString());
            } elseif ($period === '30_days') {
                $query->where('attendance_date', '>=', \Carbon\Carbon::now()->subDays(30)->toDateString());
            }
        }

        $history = $query->latest('attendance_date')
            ->select('id', 'attendance_date', 'status', 'check_in_time', 'notes', 'schedule_id')
            ->paginate(20)
            ->through(function ($att) {
                return [
                    'id' => $att->id,
                    'date' => $att->attendance_date,
                    'status' => ucfirst($att->status),
                    'check_in_time' => $att->check_in_time ? \Carbon\Carbon::parse($att->check_in_time)->format('H:i') : '-',
                    'subject' => $att->schedule->subject->name ?? '-',
                    'teacher' => $att->schedule->teacher->name ?? '-',
                    'notes' => $att->notes
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Get Late Arrival Analysis
     */
    public function getLateAnalysis(Request $request, $childId)
    {
        $user = $request->user();

        $child = $user->children()->where('student_parents.student_id', $childId)->first();
        if (!$child) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $startOfMonth = \Carbon\Carbon::now()->startOfMonth()->toDateString();
        $endOfMonth = \Carbon\Carbon::now()->endOfMonth()->toDateString();
        // Look back 3 months for pattern detection
        $startPattern = \Carbon\Carbon::now()->subMonths(3)->startOfMonth()->toDateString();

        // 1. Total Late Arrivals (This Month)
        $lateCount = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('student_id', $childId)
            ->where('status', 'late')
            ->whereBetween('attendance_date', [$startOfMonth, $endOfMonth])
            ->count();

        // 2. Average Arrival Time (This Month)
        // We only consider records where check_in_time is present
        $arrivalTimes = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('student_id', $childId)
            ->whereNotNull('check_in_time')
            ->whereBetween('attendance_date', [$startOfMonth, $endOfMonth])
            ->pluck('check_in_time');

        $avgTime = '-';
        if ($arrivalTimes->isNotEmpty()) {
            $avgSeconds = $arrivalTimes->map(function($time) {
                return \Carbon\Carbon::parse($time)->secondsSinceMidnight();
            })->average();
            $avgTime = gmdate('H:i', (int)$avgSeconds);
        }

        // 3. Most Frequent Late Day (Last 3 Months)
        $lateDays = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('student_id', $childId)
            ->where('status', 'late')
            ->whereBetween('attendance_date', [$startPattern, $endOfMonth])
            ->get()
            ->groupBy(function($item) {
                return \Carbon\Carbon::parse($item->attendance_date)->format('l'); // Monday, Tuesday...
            })
            ->map(function($group) {
                return $group->count();
            })
            ->sortDesc();

        $mostFrequentDay = $lateDays->keys()->first() ?? '-';
        $mostFrequentCount = $lateDays->first() ?? 0;

        return response()->json([
            'success' => true,
            'data' => [
                'period' => \Carbon\Carbon::now()->format('F Y'),
                'total_late' => $lateCount,
                'avg_arrival_time' => $avgTime,
                'most_frequent_late_day' => [
                    'day' => $mostFrequentDay,
                    'count' => $mostFrequentCount,
                    'analysis_period' => 'Last 3 Months'
                ]
            ]
        ]);
    }

    /**
     * Get Absence Alerts (Critical Notifications)
     */
    public function getAbsenceAlerts(Request $request, $childId)
    {
        $user = $request->user();

        // 1. Verify Relationship
        $child = $user->children()->where('student_parents.student_id', $childId)->first();
        if (!$child) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $todayStr = \Carbon\Carbon::now()->toDateString();
        $startOfMonth = \Carbon\Carbon::now()->startOfMonth()->toDateString();
        $endOfMonth = \Carbon\Carbon::now()->endOfMonth()->toDateString();
        $alerts = [];

        // 2. Check Triggers

        // Trigger A: Absent Today
        // We look for 'absent' or 'alpha'. 'sick'/'permit' might be considered "known" so maybe less critical?
        // Let's include all non-present statuses for visibility, or strictly unauthorized absence.
        // Prompt says "Absent today".
        $todayRecord = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('student_id', $childId)
            ->where('attendance_date', $todayStr)
            ->first();

        // If record exists and status is absent/alpha/sick/permit
        if ($todayRecord && in_array($todayRecord->status, ['absent', 'alpha', 'sick', 'permit'])) {
            $statusLabel = ucfirst($todayRecord->status);
            $alerts[] = [
                'type' => 'critical',
                'title' => "Absent Today ({$statusLabel})",
                'message' => "Ananda {$child->name} tercatat tidak hadir hari ini ({$statusLabel}).",
                'action' => 'contact_homeroom'
            ];
        }
        // If NO record exists, it might mean "Net yet checked in" or "Absent". 
        // We usually don't alert "Not yet checked in" as "Absent" until end of day, but let's stick to explicit records for now.

        // Trigger B: Absent 2+ Consecutive Days
        // Check last 5 days
        $recentRecords = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('student_id', $childId)
            ->where('attendance_date', '<=', $todayStr)
            ->orderBy('attendance_date', 'desc')
            ->limit(5)
            ->get();
        
        $streak = 0;
        foreach ($recentRecords as $rec) {
            if (in_array($rec->status, ['absent', 'alpha', 'sick', 'permit'])) {
                $streak++;
            } else {
                break;
            }
        }

        if ($streak >= 2) {
            $alerts[] = [
                'type' => 'warning',
                'title' => "{$streak} Days Consecutive Absence",
                'message' => "Ananda {$child->name} telah tidak hadir selama {$streak} hari berturut-turut.",
                'action' => 'check_details'
            ];
        }

        // Trigger C: Attendance Rate < 75% (This Month)
        $monthlyStats = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('student_id', $childId)
            ->whereBetween('attendance_date', [$startOfMonth, $endOfMonth])
            ->select(
                \Illuminate\Support\Facades\DB::raw('count(*) as total'),
                \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present_count")
            )
            ->first();

        $total = $monthlyStats->total ?? 0;
        $rate = $total > 0 ? ($monthlyStats->present_count / $total) * 100 : 100;

        if ($rate < 75 && $total > 3) { // Only trigger if sufficient data points
            $formattedRate = round($rate, 1);
            $alerts[] = [
                'type' => 'warning',
                'title' => "Low Attendance Rate ({$formattedRate}%)",
                'message' => "Tingkat kehadiran bulan ini di bawah 75%. Mohon perhatian orang tua.",
                'action' => 'review_monthly'
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $alerts
        ]);
    }

    /**
     * Get Today's Timeline (Schedule + Attendance)
     */
    public function getTodayTimeline(Request $request, $childId)
    {
        $user = $request->user();

        // 1. Verify Relationship
        $child = $user->children()->where('student_parents.student_id', $childId)->first();
        if (!$child) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $todayStr = \Carbon\Carbon::now()->toDateString();
        $dayOfWeek = \Carbon\Carbon::now()->dayOfWeek; // 0=Sunday, 1=Monday...

        // 2. Get Child's Class
        // We need the class ID to fetch schedules. Assuming single active class.
        $classStudent = \Illuminate\Support\Facades\DB::table('class_students')
            ->where('student_id', $childId)
            ->where('status', 'active')
            ->first();

        if (!$classStudent) {
            return response()->json([
                'success' => true, 
                'data' => [
                    'overview' => null,
                    'timeline' => []
                ]
            ]);
        }

        // 3. Fetch Today's Schedule
        $schedules = \Illuminate\Support\Facades\DB::table('schedules')
            ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->leftJoin('users as teachers', 'schedules.teacher_id', '=', 'teachers.id')
            ->where('schedules.class_id', $classStudent->class_id)
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

        if ($schedules->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'overview' => [
                        'date' => $todayStr,
                        'day' => \Carbon\Carbon::now()->format('l'),
                        'message' => 'No school schedule today'
                    ],
                    'timeline' => []
                ]
            ]);
        }

        // 4. Fetch Attendance Records for Today
        $attendances = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('student_id', $childId)
            ->where('attendance_date', $todayStr)
            ->get()
            ->keyBy('schedule_id');

        // 5. Build Timeline
        $timeline = $schedules->map(function($schedule) use ($attendances) {
            $att = $attendances[$schedule->id] ?? null;
            
            $status = 'upcoming'; 
            $currentTime = \Carbon\Carbon::now()->format('H:i:s');
            
            // Determine logical status if no attendance record
            if (!$att) {
                if ($currentTime > $schedule->end_time) {
                    $status = 'missing'; // Should have attended but no record
                } elseif ($currentTime >= $schedule->start_time) {
                    $status = 'ongoing';
                }
            } else {
                $status = $att->status; // present, late, absent, etc.
            }

            return [
                'schedule_id' => $schedule->id,
                'subject' => $schedule->subject_name,
                'teacher' => $schedule->teacher_name,
                'time_range' => substr($schedule->start_time, 0, 5) . ' - ' . substr($schedule->end_time, 0, 5),
                'status' => ucfirst($status),
                'status_raw' => $status,
                'check_in_time' => $att && $att->check_in_time ? \Carbon\Carbon::parse($att->check_in_time)->format('H:i') : null,
                'check_out_time' => $att && $att->check_out_time ? \Carbon\Carbon::parse($att->check_out_time)->format('H:i') : null,
            ];
        });

        // 6. Calculate Overview (First Check-in / Last Check-out)
        // Ensure we check ALL today's attendances, not just scheduled ones (in case of extras)
        $allTodayAttendances = $attendances->values(); // Collection
        $firstCheckIn = $allTodayAttendances->whereNotNull('check_in_time')->sortBy('check_in_time')->first();
        $lastCheckOut = $allTodayAttendances->whereNotNull('check_out_time')->sortByDesc('check_out_time')->first();

        $overview = [
            'date' => \Carbon\Carbon::now()->format('d F Y'),
            'first_check_in' => $firstCheckIn ? \Carbon\Carbon::parse($firstCheckIn->check_in_time)->format('H:i') : '-',
            'last_check_out' => $lastCheckOut ? \Carbon\Carbon::parse($lastCheckOut->check_out_time)->format('H:i') : '-',
            'total_classes' => $schedules->count(),
            'completed_classes' => $attendances->count()
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'overview' => $overview,
                'timeline' => $timeline
            ]
        ]);
    }

    /**
     * Get Monthly Attendance Report Summary
     */
    public function getMonthlyReport(Request $request, $childId)
    {
        $user = $request->user();

        // 1. Verify Relationship
        $child = $user->children()->where('student_parents.student_id', $childId)->first();
        if (!$child) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $startOfMonth = \Carbon\Carbon::now()->startOfMonth()->toDateString();
        $endOfMonth = \Carbon\Carbon::now()->endOfMonth()->toDateString();
        
        $startLastMonth = \Carbon\Carbon::now()->subMonth()->startOfMonth()->toDateString();
        $endLastMonth = \Carbon\Carbon::now()->subMonth()->endOfMonth()->toDateString();

        // Helper function to calculate stats for a period
        $calculateStats = function ($start, $end) use ($childId) {
            $records = \Illuminate\Support\Facades\DB::table('attendances')
                ->where('student_id', $childId)
                ->whereBetween('attendance_date', [$start, $end])
                ->get();
            
            // Group by date to determine daily status
            // Logic: 
            // - If ANY 'late' in a day -> Day is Late (if present)
            // - If ALL 'absent' -> Day is Absent
            // - Else if ANY 'present' -> Day is Present
            
            $days = $records->groupBy('attendance_date');
            
            $stats = [
                'total_days' => $days->count(),
                'days_present' => 0,
                'days_late' => 0, // Late is a type of present
                'days_absent' => 0
            ];

            foreach ($days as $date => $dayRecords) {
                $statusList = $dayRecords->pluck('status')->toArray();
                
                // Check Absence first
                $isAbsent = collect($statusList)->every(fn($s) => in_array($s, ['absent', 'alpha', 'sick', 'permit']));
                
                if ($isAbsent) {
                    $stats['days_absent']++;
                    continue;
                }

                // If not absent, they are present. Check if late.
                // We'll consider them "Late" for the day if the FIRST schedule was late, or ANY late? 
                // Usually "Late to school" is first entry. Let's look for ANY 'late' for simplicity or specific logic.
                // Let's assume ANY 'late' tag makes the day "Late".
                if (in_array('late', $statusList)) {
                    $stats['days_late']++;
                } else {
                    $stats['days_present']++;
                }
            }
            
            return $stats;
        };

        $currentStats = $calculateStats($startOfMonth, $endOfMonth);
        $lastStats = $calculateStats($startLastMonth, $endLastMonth);

        // Calculate Rate
        $totalSchoolDays = $currentStats['total_days'];
        $totalPresentDays = $currentStats['days_present'] + $currentStats['days_late'];
        
        $rate = $totalSchoolDays > 0 ? ($totalPresentDays / $totalSchoolDays) * 100 : 0;
        
        // Comparison
        $lastTotalPresent = $lastStats['days_present'] + $lastStats['days_late'];
        $lastTotalDays = $lastStats['total_days'];
        $lastRate = $lastTotalDays > 0 ? ($lastTotalPresent / $lastTotalDays) * 100 : 0;
        
        $diff = $rate - $lastRate;
        $trend = $diff >= 0 ? 'up' : 'down';

        return response()->json([
            'success' => true,
            'data' => [
                'month' => \Carbon\Carbon::now()->format('F Y'),
                'summary' => [
                    'total_school_days' => $totalSchoolDays,
                    'days_present' => $currentStats['days_present'], // strictly on time
                    'days_late' => $currentStats['days_late'],
                    'days_absent' => $currentStats['days_absent'],
                    'attendance_percentage' => round($rate, 1),
                ],
                'comparison' => [
                    'last_month_percentage' => round($lastRate, 1),
                    'difference' => abs(round($diff, 1)),
                    'trend' => $trend
                ]
            ]
        ]);
    }

    /**
     * Get Parent Notification Feed
     */
    public function getNotificationFeed(Request $request, $childId)
    {
        $user = $request->user();

        // 1. Verify Relationship
        $child = $user->children()->where('student_parents.student_id', $childId)->first();
        if (!$child) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // 2. Fetch Logs
        $logs = \Illuminate\Support\Facades\DB::table('attendance_logs')
            ->join('attendances', 'attendance_logs.attendance_id', '=', 'attendances.id')
            ->leftJoin('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->leftJoin('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->where('attendances.student_id', $childId)
            ->select(
                'attendance_logs.id',
                'attendance_logs.action',
                'attendance_logs.new_status',
                'attendance_logs.notes',
                'attendance_logs.created_at',
                'subjects.name as subject_name'
            )
            ->orderBy('attendance_logs.created_at', 'desc')
            ->limit(10)
            ->get();

        // 3. Transform to Notifications
        $feed = $logs->map(function ($log) use ($child) {
            $type = 'info';
            $title = 'Update';
            $message = '';

            $subject = $log->subject_name ?? 'School';

            switch ($log->action) {
                case 'scan_in':
                    if ($log->new_status === 'late') {
                        $type = 'alert';
                        $title = 'Late Arrival';
                        $message = "Ananda {$child->name} check-in terlambat untuk {$subject}.";
                    } else {
                        $type = 'success';
                        $title = 'Check In';
                        $message = "Ananda {$child->name} checked in tepat waktu untuk {$subject}.";
                    }
                    break;
                
                case 'manual_create':
                case 'manual_update':
                    if (in_array($log->new_status, ['absent', 'alpha', 'sick', 'permit'])) {
                        $type = 'warning';
                        $title = 'Absence Recorded';
                        $statusLabel = ucfirst($log->new_status);
                        $message = "Kehadiran Ananda dicatat sebagai: {$statusLabel}.";
                    } elseif ($log->notes) {
                        $type = 'note';
                        $title = 'Note Added';
                        $message = "Catatan wali kelas: \"{$log->notes}\"";
                    } else {
                        // Generic update
                        $type = 'info';
                        $title = 'Attendance Updated';
                        $message = "Status kehadiran diperbarui menjadi: " . ucfirst($log->new_status);
                    }
                    break;
                
                case 'scan_out':
                    $type = 'success';
                    $title = 'Check Out';
                    $message = "Ananda {$child->name} telah check-out dari {$subject}.";
                    break;
            }

            return [
                'id' => $log->id,
                'type' => $type,
                'timestamp' => \Carbon\Carbon::parse($log->created_at)->toDateTimeString(),
                'time_ago' => \Carbon\Carbon::parse($log->created_at)->diffForHumans(),
                'title' => $title,
                'message' => $message,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $feed
        ]);
    }

    /**
     * Get Student Profile for Parent View
     */
    public function getStudentProfile(Request $request, $childId)
    {
        $user = $request->user();

        // 1. Verify Relationship
        $child = $user->children()->where('student_parents.student_id', $childId)->first();
        if (!$child) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $schoolId = $child->school_id;
        $startOfMonth = \Carbon\Carbon::now()->startOfMonth()->toDateString();
        $endOfMonth = \Carbon\Carbon::now()->endOfMonth()->toDateString();

        // 2. Fetch Class & Homeroom Teacher
        // Active Academic Year
        $academicYearId = \Illuminate\Support\Facades\DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id');

        // Child's Active Class
        $classData = \Illuminate\Support\Facades\DB::table('class_students')
            ->join('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('class_students.student_id', $childId)
            ->where('class_students.status', 'active')
            ->select('classes.id', 'classes.name as class_name')
            ->first();

        $homeroomTeacherName = '-';
        if ($classData && $academicYearId) {
            $teacherRole = \App\Models\TeacherRole::where('homeroom_class_id', $classData->id)
                ->where('academic_year_id', $academicYearId)
                ->with('teacher:id,name')
                ->first();
            
            if ($teacherRole && $teacherRole->teacher) {
                $homeroomTeacherName = $teacherRole->teacher->name;
            }
        }

        // 3. Monthly Stats
        $monthlyStats = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('student_id', $childId)
            ->whereBetween('attendance_date', [$startOfMonth, $endOfMonth])
            ->select(
                \Illuminate\Support\Facades\DB::raw('count(*) as total'),
                \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present_count"),
                \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count")
            )
            ->first();

        $totalRecords = $monthlyStats->total ?? 0;
        $attRate = $totalRecords > 0 ? ($monthlyStats->present_count / $totalRecords) * 100 : 0;
        if ($totalRecords == 0) $attRate = 100; // Optimistic

        // 4. Last 10 Attendance Records
        $history = \App\Models\Attendance::with(['schedule.subject:id,name'])
            ->where('student_id', $childId)
            ->latest('attendance_date')
            ->limit(10)
            ->get()
            ->map(function ($att) {
                return [
                    'date' => $att->attendance_date,
                    'status' => ucfirst($att->status),
                    'check_in_time' => $att->check_in_time ? \Carbon\Carbon::parse($att->check_in_time)->format('H:i') : '-',
                    'subject' => $att->schedule->subject->name ?? '-'
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'profile' => [
                    'name' => $child->name,
                    'nis' => $child->username, // NIS usually username
                    'class_name' => $classData->class_name ?? '-',
                    'homeroom_teacher' => $homeroomTeacherName,
                    'photo' => $child->profile_photo_url,
                ],
                'stats' => [
                    'month' => \Carbon\Carbon::now()->format('F'),
                    'attendance_rate' => round($attRate, 1),
                    'late_count' => (int) ($monthlyStats->late_count ?? 0)
                ],
                'recent_activity' => $history
            ]
        ]);
    }
}
