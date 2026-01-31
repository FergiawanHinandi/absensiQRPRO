<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClassStudent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    /**
     * Get class leaderboard (Top 10 by Points & Streak).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Identify Student's Active Class
        // Assuming single active class for simplicity.
        $classMembership = ClassStudent::where('student_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$classMembership) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not assigned to any active class.',
            ], 404);
        }

        $classId = $classMembership->class_id;

        // 2. Query Top 10 by Total Points
        $topPoints = User::select('id', 'name', 'total_points', 'current_streak')
            ->whereHas('classStudents', function ($q) use ($classId) {
                $q->where('class_id', $classId)
                  ->where('status', 'active');
            })
            ->orderByDesc('total_points')
            ->limit(10)
            ->get();

        // 3. Query Top 10 by Streak
        $topStreak = User::select('id', 'name', 'total_points', 'current_streak')
            ->whereHas('classStudents', function ($q) use ($classId) {
                $q->where('class_id', $classId)
                  ->where('status', 'active');
            })
            ->orderByDesc('current_streak')
            ->limit(10)
            ->get();

        // 4. Get Current User Rank (Optional but good for UX)
        // Calculating rank efficiently: Count how many have more points.
        $myRankPoints = User::whereHas('classStudents', function ($q) use ($classId) {
                $q->where('class_id', $classId)->where('status', 'active');
            })
            ->where('total_points', '>', $user->total_points)
            ->count() + 1;

        $topPoints->each->append('level');
        $topStreak->each->append('level');

        return response()->json([
            'status' => 'success',
            'data' => [
                'class_id' => $classId,
                'my_stats' => [
                    'rank' => $myRankPoints,
                    'points' => $user->total_points,
                    'streak' => $user->current_streak,
                    'level' => $user->level,
                ],
                'leaderboard_points' => $topPoints,
                'leaderboard_streak' => $topStreak,
            ]
        ]);
    }
    /**
     * Get Class vs Class competition leaderboard.
     * Ranks classes within their grade level based on monthly attendance rate.
     */
    public function classCompetition(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        
        $startOfMonth = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();

        // 1. Calculate Stats per Class
        // We need: Class ID, Name, Grade, Attendance Rate
        
        $stats = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->where('classes.school_id', $schoolId)
            ->whereBetween('attendances.attendance_date', [$startOfMonth, $today])
            ->select(
                'classes.id as class_id',
                'classes.name as class_name',
                'classes.level as grade_level', // Changed 'grade' to 'level' based on typical schema, adjust if needed
                DB::raw('count(*) as total_records'),
                DB::raw("sum(case when attendances.status in ('present', 'late') then 1 else 0 end) as present_count")
            )
            ->groupBy('classes.id', 'classes.name', 'classes.level')
            ->get();

        // 2. Process and Group by Grade
        $grouped = $stats->map(function ($stat) {
            $rate = $stat->total_records > 0 
                ? round(($stat->present_count / $stat->total_records) * 100, 1) 
                : 0;
            
            return [
                'class_id' => $stat->class_id,
                'class_name' => $stat->class_name,
                'grade_level' => $stat->grade_level,
                'attendance_rate' => $rate,
            ];
        })->groupBy('grade_level');

        // 3. Sort within groups to determine Rank
        $result = [];
        foreach ($grouped as $grade => $classes) {
            $sorted = $classes->sortByDesc('attendance_rate')->values();
            
            // Assign rank
            $ranked = $sorted->map(function ($item, $index) {
                $item['rank'] = $index + 1;
                return $item;
            });

            $result[] = [
                'grade_level' => $grade,
                'classes' => $ranked
            ];
        }

        // Sort grades numerically if needed
        usort($result, fn($a, $b) => $a['grade_level'] <=> $b['grade_level']);

        return response()->json([
            'status' => 'success',
            'data' => $result
        ]);
    }
    /**
     * Get Official School Leaderboard (Snapshots)
     */
    public function officialLeaderboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);
        $periodKey = sprintf('%04d-%02d', $year, $month);

        // Fetch from History Table
        $leaderboards = \App\Models\LeaderboardHistory::where('school_id', $schoolId)
            ->where('period_key', $periodKey)
            ->orderBy('category')
            ->orderBy('rank')
            ->get()
            ->groupBy('category');

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => Carbon::createFromDate($year, $month, 1)->format('F Y'),
                'categories' => [
                    'student_rate' => $leaderboards->get('student_rate', []),
                    'student_streak' => $leaderboards->get('student_streak', []),
                    'class_rate' => $leaderboards->get('class_rate', []),
                ]
            ]
        ]);
    }
    /**
     * Get Hall of Fame (All-Time Records)
     */
    public function hallOfFame(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // 1. Longest Streak Ever (All Time)
        $longestStreaks = User::where('school_id', $schoolId)
            ->where('role', 'student')
            ->orderByDesc('longest_streak')
            ->limit(10)
            ->select('id', 'name', 'longest_streak', 'profile_photo_url')
            ->get();

        // 2. Perfect Attendance (Current Semester)
        // Logic: Students with 100% attendance rate in the active academic semester
        // This can be heavy, so we might rely on the 'reward_eligible' flag or similar, 
        // OR filtering by those who have NO 'absent' records in the period.
        
        $academicYear = \App\Models\AcademicYear::where('school_id', $schoolId)
            ->where('is_active', true)
            ->first();

        $perfectAttendance = [];
        
        if ($academicYear) {
             // Find students with NO absences in this period
             // Strategy: Get all students, exclude those with ANY absence record.
             // Optimize: Check users who have attendance records but NO 'absent' records.
             
             $startDate = $academicYear->start_date;
             $endDate = now()->min($academicYear->end_date)->toDateString();

             $studentsWithAbsences = DB::table('attendances')
                ->whereBetween('attendance_date', [$startDate, $endDate])
                ->where('status', 'absent')
                ->where('school_id', $schoolId)
                ->distinct('student_id')
                ->pluck('student_id');
                
             // Get top students who are NOT in the absence list, sorted by total present days
             $perfectAttendance = User::where('school_id', $schoolId)
                ->where('role', 'student')
                ->whereNotIn('id', $studentsWithAbsences)
                ->whereHas('attendances', function($q) use ($startDate, $endDate) {
                    $q->whereBetween('attendance_date', [$startDate, $endDate]);
                })
                ->withCount(['attendances as total_attended' => function($q) use ($startDate, $endDate) {
                     $q->whereBetween('attendance_date', [$startDate, $endDate])
                       ->whereIn('status', ['present', 'late']);
                }])
                ->orderByDesc('total_attended')
                ->limit(20)
                ->get()
                ->map(function($u) {
                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'photo' => $u->profile_photo_url,
                        'days_perfect' => $u->total_attended
                    ];
                });
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'longest_streaks_ever' => $longestStreaks,
                'perfect_semester' => $perfectAttendance
            ]
        ]);
    }
}
