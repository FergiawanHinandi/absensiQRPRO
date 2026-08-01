<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Service for teacher schedule operations
 * 
 * Handles schedule queries with optimized eager loading and policy enforcement.
 */
class TeacherScheduleService
{
    /**
     * Get teacher's weekly schedules
     * 
     * Query optimized with:
     * - Eager loading (class, subject)
     * - School scoping (via BelongsToSchool trait)
     * - Week filtering
     * - Teacher ownership enforcement
     * 
     * @param User $teacher The authenticated teacher
     * @param string|null $week ISO week string (e.g., "2026-W06") or null for current week
     * @return Collection Schedules grouped by day_of_week
     */
    public function getWeeklySchedules(User $teacher, ?string $week = null): Collection
    {
        // Validate teacher role
        if (!in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
            return collect([]);
        }

        $schoolTimezone = $teacher->school->timezone ?? config('app.timezone');

        // Parse week parameter or use current week
        $weekStart = $this->parseWeekStart($week, $schoolTimezone);
        $weekEnd = $weekStart->copy()->endOfWeek();

        // Build optimized query
        // Note: BelongsToSchool trait automatically scopes by school_id
        $schedules = Schedule::query()
            ->with([
                'class:id,name,grade_level,academic_year_id',
                'subject:id,name,code',
            ])
            ->select([
                'id',
                'school_id',
                'class_id',
                'subject_id',
                'teacher_id',
                'day_of_week',
                'start_time',
                'end_time',
                'room',
            ])
            ->where('teacher_id', $teacher->id)
            ->where('school_id', $teacher->school_id) // Explicit school filter for security
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        // Transform and group by day
        return $schedules
            ->map(function ($schedule) use ($weekStart) {
                return [
                    'id' => $schedule->id,
                    'day_of_week' => $schedule->day_of_week,
                    'day_name' => $this->getDayName($schedule->day_of_week),
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'room' => $schedule->room,
                    'class' => $schedule->class ? [
                        'id' => $schedule->class->id,
                        'name' => $schedule->class->name,
                        'grade_level' => $schedule->class->grade_level,
                    ] : null,
                    'subject' => $schedule->subject ? [
                        'id' => $schedule->subject->id,
                        'name' => $schedule->subject->name,
                        'code' => $schedule->subject->code,
                    ] : null,
                    'date' => $this->getDateForDayInWeek($weekStart, $schedule->day_of_week),
                ];
            })
            ->groupBy('day_of_week');
    }

    /**
     * Get a single schedule with authorization check
     * 
     * @param User $teacher
     * @param int $scheduleId
     * @return Schedule|null
     */
    public function getScheduleById(User $teacher, int $scheduleId): ?Schedule
    {
        return Schedule::query()
            ->with(['class:id,name,grade_level', 'subject:id,name,code'])
            ->where('id', $scheduleId)
            ->where('teacher_id', $teacher->id)
            ->where('school_id', $teacher->school_id)
            ->first();
    }

    /**
     * Get today's schedules for a teacher (OPTIMIZED)
     * 
     * Optimizations:
     * - Uses school timezone instead of server timezone
     * - Filters by is_active = true and schedule_type = 'regular'
     * - Uses composite index (teacher_id, school_id, day_of_week)
     * - Eager loads only necessary columns
     * - Avoids SELECT *
     * 
     * @param User $teacher
     * @return Collection
     */
    public function getTodaySchedules(User $teacher): Collection
    {
        // Get school timezone (default to global config if not set)
        $schoolTimezone = $teacher->school->timezone ?? config('app.timezone');
        
        // Get today's day_of_week based on school timezone
        // Carbon dayOfWeek: 0=Sunday, 1=Monday, ..., 6=Saturday
        $todayDayOfWeek = Carbon::now($schoolTimezone)->dayOfWeek;

        /**
         * OPTIMIZED QUERY EXPLANATION:
         * 
         * 1. SELECT specific columns only (no SELECT *)
         * 2. WHERE clause uses composite index in optimal order:
         *    - teacher_id (most selective)
         *    - school_id (tenant isolation)
         *    - day_of_week (time-based filter)
         * 3. Additional filters: is_active, schedule_type
         * 4. Eager loading with specific columns to reduce memory
         * 5. ORDER BY start_time for chronological display
         * 
         * Index Used: idx_teacher_school_day (teacher_id, school_id, day_of_week)
         * 
         * Query Plan:
         * - Index seek on (teacher_id, school_id, day_of_week)
         * - Filter on is_active and schedule_type (covered by idx_active_regular)
         * - Sort by start_time (filesort, but small result set)
         */
        return Schedule::select([
                'id',
                'school_id',
                'class_id',
                'subject_id',
                'teacher_id',
                'day_of_week',
                'start_time',
                'end_time',
                'room',
                'is_active',
                'schedule_type'
            ])
            ->with([
                'class:id,name,grade_level',
                'subject:id,name,code'
            ])
            ->where('teacher_id', $teacher->id)           // Index column 1
            ->where('school_id', $teacher->school_id)     // Index column 2
            ->where('day_of_week', $todayDayOfWeek)       // Index column 3
            ->where('is_active', true)                    // Additional filter
            ->where('schedule_type', 'regular')           // Additional filter
            ->orderBy('start_time')
            ->get()
            ->map(function ($schedule) use ($schoolTimezone) {
                return [
                    'id' => $schedule->id,
                    'class_name' => $schedule->class->name ?? null,
                    'subject_name' => $schedule->subject->name ?? null,
                    'subject_code' => $schedule->subject->code ?? null,
                    'day_of_week' => $schedule->day_of_week,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'room' => $schedule->room,
                    'is_active' => $schedule->is_active,
                    'schedule_type' => $schedule->schedule_type,
                ];
            });
    }

    /**
     * Parse week string to Carbon date
     * 
     * @param string|null $week ISO week format (e.g., "2026-W06")
     * @return Carbon
     */
    private function parseWeekStart(?string $week, string $timezone = 'UTC'): Carbon
    {
        if (!$week) {
            return Carbon::now($timezone)->startOfWeek();
        }

        // Parse ISO week format: 2026-W06
        if (preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches)) {
            $year = (int) $matches[1];
            $weekNum = (int) $matches[2];
            
            return Carbon::now($timezone)
                ->setISODate($year, $weekNum)
                ->startOfWeek();
        }

        return Carbon::now($timezone)->startOfWeek();
    }

    /**
     * Get day name from day_of_week integer
     * 
     * @param int $dayOfWeek 0=Sunday, 1=Monday, etc.
     * @return string
     */
    private function getDayName(int $dayOfWeek): string
    {
        $days = [
            0 => 'Minggu',
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
        ];

        return $days[$dayOfWeek] ?? 'Unknown';
    }

    /**
     * Get the actual date for a day_of_week within a given week
     * 
     * @param Carbon $weekStart
     * @param int $dayOfWeek
     * @return string
     */
    private function getDateForDayInWeek(Carbon $weekStart, int $dayOfWeek): string
    {
        // Carbon's startOfWeek starts on Monday (1)
        // Adjust for day_of_week where 0=Sunday
        $daysFromMonday = $dayOfWeek === 0 ? 6 : $dayOfWeek - 1;
        
        return $weekStart->copy()->addDays($daysFromMonday)->toDateString();
    }
}
