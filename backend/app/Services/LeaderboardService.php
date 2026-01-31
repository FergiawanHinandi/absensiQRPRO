<?php

namespace App\Services;

use App\Models\LeaderboardHistory;
use App\Models\School;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeaderboardService
{
    /**
     * Generate Monthly Leaderboard for a School
     * 
     * @param int $schoolId
     * @param int|null $month
     * @param int|null $year
     */
    public function generateMonthlyLeaderboard(int $schoolId, $month = null, $year = null)
    {
        $month = $month ?? Carbon::now()->month;
        $year = $year ?? Carbon::now()->year;
        $periodKey = sprintf('%04d-%02d', $year, $month);
        
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        DB::beginTransaction();
        try {
            // Clear existing for this period to allow re-generation
            LeaderboardHistory::where('school_id', $schoolId)
                ->where('period_key', $periodKey)
                ->delete();

            // 1. Top 10 Students (Attendance Rate)
            $this->generateTopStudentAttendance($schoolId, $periodKey, $startDate, $endDate);

            // 2. Top 5 Longest Streaks (Current Snapshot)
            $this->generateTopStudentStreaks($schoolId, $periodKey);

            // 3. Class with Best Attendance Rate
            $this->generateTopClassAttendance($schoolId, $periodKey, $startDate, $endDate);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Leaderboard Generation Failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function generateTopStudentAttendance($schoolId, $periodKey, $startDate, $endDate)
    {
        // Calculate attendance rate per student for the month
        // Formula: (Present + Late) / Total Recorded * 100
        // We only consider students with at least 1 record to avoid 0/0
        
        $results = DB::table('attendances')
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->where('users.school_id', $schoolId)
            ->where('users.role', 'student') // Ensure only students
            ->whereBetween('attendances.attendance_date', [$startDate, $endDate])
            ->select(
                'users.id as student_id',
                'users.name as student_name',
                'users.profile_photo_url',
                DB::raw("count(*) as total_records"),
                DB::raw("sum(case when attendances.status in ('present', 'late') then 1 else 0 end) as present_count")
            )
            ->groupBy('users.id', 'users.name', 'users.profile_photo_url')
            ->having('total_records', '>', 0)
            ->get();
            
        // Calculate rates in memory (easier than complex SQL division safely)
        $ranked = $results->map(function($row) {
            $row->rate = ($row->present_count / $row->total_records) * 100;
            return $row;
        })->sortByDesc('rate')->take(10); // Take top 10

        $rank = 1;
        foreach ($ranked as $student) {
            LeaderboardHistory::create([
                'school_id' => $schoolId,
                'period_key' => $periodKey,
                'category' => 'student_rate',
                'rank' => $rank++,
                'entity_id' => $student->student_id,
                'entity_type' => 'student',
                'entity_name' => $student->student_name,
                'score' => $student->rate,
                'metadata' => [
                    'total_days' => $student->total_records,
                    'present_days' => $student->present_count,
                    'photo' => $student->profile_photo_url
                ]
            ]);
        }
    }

    private function generateTopStudentStreaks($schoolId, $periodKey)
    {
        // Snapshot of current streaks
        $students = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('role', 'student')
            ->where('current_streak', '>', 0)
            ->orderBy('current_streak', 'desc')
            ->limit(5)
            ->select('id', 'name', 'current_streak', 'profile_photo_url')
            ->get();

        $rank = 1;
        foreach ($students as $student) {
            LeaderboardHistory::create([
                'school_id' => $schoolId,
                'period_key' => $periodKey,
                'category' => 'student_streak',
                'rank' => $rank++,
                'entity_id' => $student->id,
                'entity_type' => 'student',
                'entity_name' => $student->name,
                'score' => $student->current_streak, // Score is days
                'metadata' => [
                    'streak_days' => $student->current_streak,
                    'photo' => $student->profile_photo_url
                ]
            ]);
        }
    }

    private function generateTopClassAttendance($schoolId, $periodKey, $startDate, $endDate)
    {
        // Aggregate by class
        // Join attendances -> class_students (to get class at that time? Or current class?)
        // Simplification: Use current class based on 'attendances.class_id' if we added it, 
        // OR join via users if we didn't backfill. 
        // We added class_id to attendances in previous step, assuming it's populating.
        // If class_id is nullable/empty, fallback to class_students.
        
        // Let's rely on class_id in attendances for accuracy of *that* day.
        // But if migration just ran, old records might be null.
        // SAFE APPROACH: Use `class_students` active relationship for current month context, 
        // or assumes structure: attendances.class_id is best if reliable.
        // Let's try to join via class_students active for now as a fallback or primary.
        
        $results = DB::table('attendances')
            ->join('class_students', 'attendances.student_id', '=', 'class_students.student_id')
            ->join('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('classes.school_id', $schoolId)
            ->where('class_students.status', 'active') // Assuming active during this month
            ->whereBetween('attendances.attendance_date', [$startDate, $endDate])
            ->select(
                'classes.id as class_id',
                'classes.name as class_name',
                DB::raw("count(*) as total_records"),
                DB::raw("sum(case when attendances.status in ('present', 'late') then 1 else 0 end) as present_count")
            )
            ->groupBy('classes.id', 'classes.name')
            ->having('total_records', '>', 0)
            ->get();

        $ranked = $results->map(function($row) {
            $row->rate = ($row->present_count / $row->total_records) * 100;
            return $row;
        })->sortByDesc('rate')->take(5); // Top 5 Classes

        $rank = 1;
        foreach ($ranked as $class) {
            LeaderboardHistory::create([
                'school_id' => $schoolId,
                'period_key' => $periodKey,
                'category' => 'class_rate',
                'rank' => $rank++,
                'entity_id' => $class->class_id,
                'entity_type' => 'class',
                'entity_name' => $class->class_name,
                'score' => $class->rate,
                'metadata' => [
                    'total_records' => $class->total_records,
                    'present_count' => $class->present_count
                ]
            ]);
        }
    }
}
