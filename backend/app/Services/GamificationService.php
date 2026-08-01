<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\StudentPoint;
use Carbon\Carbon;

class GamificationService
{
    /**
     * Award points based on daily attendance.
     */
    public function awardDailyPoints(Attendance $attendance): void
    {
        // Prevent duplicate processing
        if ($this->alreadyAwardedDaily($attendance)) {
            return;
        }

        $points = 0;
        $description = '';

        // Requirement: +10 points On-time, +5 points Late, 0 Absent
        if ($attendance->status === 'present') {
            $points = 10;
            $description = 'On-time attendance';
        } elseif ($attendance->status === 'late') {
            $points = 5;
            $description = 'Late attendance';
        }

        if ($points > 0) {
            StudentPoint::create([
                'student_id' => $attendance->student_id,
                'points' => $points,
                'source' => 'attendance_daily',
                'date' => $attendance->attendance_date,
                'reference_id' => $attendance->id,
                'description' => $description,
            ]);

            // Update streak
            $this->updateStreak($attendance->student, $attendance->attendance_date);

            // Update Total Points
            $attendance->student->increment('total_points', $points);
        } elseif ($attendance->status === 'absent') {
            // Explicitly reset streak if absent record is processed
            $this->resetStreak($attendance->student);
        }

        // Check for Bonuses
        $this->checkWeeklyBonus($attendance->student_id, $attendance->attendance_date);
        $this->checkMonthlyBonus($attendance->student_id, $attendance->attendance_date);
    }

    /**
     * Update student attendance streak.
     * Rules:
     * - +1 if consecutive (ignoring weekends/holidays if logic permits, but here simple date check)
     * - Reset if gap > 1 day (assumes daily attendance required)
     * - Late counts.
     */
    public function updateStreak(\App\Models\User $student, $dateInput): void
    {
        $date = Carbon::parse($dateInput);
        $lastStreakDate = $student->last_streak_date ? Carbon::parse($student->last_streak_date) : null;

        // If no previous streak date, or if it was reset
        if (! $lastStreakDate) {
            $student->update([
                'current_streak' => 1,
                'longest_streak' => max($student->longest_streak, 1),
                'last_streak_date' => $date,
            ]);

            return;
        }

        // Check difference in days
        // Case 1: Same day (already updated for this day? - though checkDuplicate prevents this, but safe to check)
        if ($date->isSameDay($lastStreakDate)) {
            return;
        }

        // Case 2: Consecutive day
        // We need to handle weekends. If Friday -> Monday, that is consecutive in school terms.
        // Simple logic:
        // DiffInDays = 1 -> consecutive
        // DiffInDays > 1 -> check if gaps are weekends

        $diff = $date->diffInDays($lastStreakDate); // absolute difference

        $isConsecutive = false;

        if ($diff == 1) {
            $isConsecutive = true;
        } elseif ($diff <= 3) {
            // Check if weekend gap
            // Iterate days between lastStreakDate and date. If all are weekends, then it is consecutive.
            $tempDate = $lastStreakDate->copy()->addDay();
            $allWeekends = true;
            while ($tempDate->lt($date)) {
                if (! $tempDate->isWeekend()) {
                    $allWeekends = false;
                    break;
                }
                $tempDate->addDay();
            }
            if ($allWeekends) {
                $isConsecutive = true;
            }
        }

        if ($isConsecutive) {
            $newStreak = $student->current_streak + 1;
            $student->update([
                'current_streak' => $newStreak,
                'longest_streak' => max($student->longest_streak, $newStreak),
                'last_streak_date' => $date,
            ]);
        } else {
            // Gap detected: Reset
            $student->update([
                'current_streak' => 1,
                'last_streak_date' => $date,
            ]);
        }

        $this->checkBadges($student);
    }

    /**
     * Explicitly reset streak (e.g. when marked absent).
     */
    public function resetStreak(\App\Models\User $student): void
    {
        // Check if eligibility was likely active (Streak >= 30)
        // Note: This is a simplification. True check involves checking reward_eligible attribute logic
        if ($student->current_streak >= 30) {
            \App\Events\RewardEligibilityLost::dispatch($student, 'Streak reset due to absence.');
        }

        $student->update([
            'current_streak' => 0,
            // longest_streak is preserved
        ]);
    }

    private function alreadyAwardedDaily(Attendance $attendance): bool
    {
        return StudentPoint::where('source', 'attendance_daily')
            ->where('reference_id', $attendance->id)
            ->exists();
    }

    /**
     * Bonus: +20 points for Perfect Attendance in 1 week.
     * Strategy: Check on Friday/Saturday/Sunday if no absences recorded.
     */
    private function checkBadges(\App\Models\User $student): void
    {
        // 1. 7-Day Warrior
        if ($student->current_streak >= 7) {
            $this->awardBadge($student, '7-day-warrior');
        }

        // 2. On-Time Hero (30 days streak without late)
        // This is expensive to calculate every time. Optimization: Check only if attendance was just marked 'present' (not late)
        // and do a query limit.
        // Or track 'on_time_streak' separately. For now, let's query.
        $this->checkOnTimeHero($student);
    }

    private function awardBadge(\App\Models\User $student, string $slug): void
    {
        $badge = \App\Models\Badge::where('slug', $slug)->first();
        if (! $badge) {
            return;
        }

        // Check if student already has this badge
        $hasBadge = \App\Models\StudentBadge::where('student_id', $student->id)
            ->where('badge_id', $badge->id)
            ->exists();

        if (! $hasBadge) {
            \App\Models\StudentBadge::create([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'badge_id' => $badge->id,
                'awarded_at' => now(),
            ]);

            // Trigger Event
            \App\Events\BadgeAwarded::dispatch($student, $badge->name, $badge->slug);
        }
    }

    private function checkOnTimeHero(\App\Models\User $student): void
    {
        // Logic: Last 30 attendance records must be 'present' (not late, not absent, etc - wait, 'absent' breaks chain anyway?)
        // The badge says "30 consecutive days without being late".
        // This effectively means 30 attended days where status != 'late'.

        // Let's count last 30 'attended' records (present or late) and see if 0 are late.
        // Or better: Check if the last 30 consecutive attended days were 'present'.

        $records = Attendance::where('student_id', $student->id)
            ->whereIn('status', ['present', 'late'])
            ->orderBy('attendance_date', 'desc')
            ->limit(30)
            ->get();

        if ($records->count() < 30) {
            return;
        }

        foreach ($records as $record) {
            if ($record->status === 'late') {
                return; // Streak broken
            }
        }

        $this->awardBadge($student, 'on-time-hero');
    }

    /**
     * Bonus: +20 points for Perfect Attendance in 1 week.
     * Strategy: Check on Friday/Saturday/Sunday if no absences recorded.
     */
    public function checkWeeklyBonus(int $studentId, $dateInput): void
    {
        $date = Carbon::parse($dateInput);

        // Only check near the end of the week (Friday onwards)
        // Assuming School Week is Mon-Fri.
        if ($date->dayOfWeekIso < 5) {
            return;
        }

        $startOfWeek = $date->copy()->startOfWeek(); // Monday
        $endOfWeek = $date->copy()->endOfWeek();     // Sunday

        // Check if already awarded this week
        $alreadyAwarded = StudentPoint::where('student_id', $studentId)
            ->where('source', 'weekly_bonus')
            ->whereBetween('date', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')])
            ->exists();

        if ($alreadyAwarded) {
            // Even if points awarded, we might need to check badge if logic is shared?
            // Actually, "Perfect Week" badge should align with the bonus.
            // If points awarded, badge likely awarded too.
            // But safely re-check badge to be sure.
            $student = \App\Models\User::find($studentId);
            if ($student) {
                $this->awardBadge($student, 'perfect-week');
            }

            return;
        }

        // Logic: No 'absent' records AND has attended at least 5 days (or appropriate number)
        // Note: This relies on 'absent' records being generated for missed days.
        $hasAbsence = Attendance::where('student_id', $studentId)
            ->whereBetween('attendance_date', [$startOfWeek, $endOfWeek])
            ->where('status', 'absent')
            ->exists();

        if ($hasAbsence) {
            return;
        }

        // Ensure we actually have attendance records (e.g., didn't just not show up with no record)
        // Assuming 5 school days.
        $daysAttended = Attendance::where('student_id', $studentId)
            ->whereBetween('attendance_date', [$startOfWeek, $endOfWeek])
            ->whereIn('status', ['present', 'late'])
            ->distinct('attendance_date')
            ->count();

        // Threshold: 5 days. (Adjust logic if school days vary)
        if ($daysAttended >= 5) {
            StudentPoint::create([
                'student_id' => $studentId,
                'points' => 20,
                'source' => 'weekly_bonus',
                'date' => $date,
                'description' => 'Perfect attendance bonus (Week '.$date->weekOfYear.')',
            ]);

            $student = \App\Models\User::find($studentId);
            if ($student) {
                $student->increment('total_points', 20);
                $this->awardBadge($student, 'perfect-week');
            }
        }
    }

    /**
     * Bonus: +100 points for Perfect Attendance in 1 month.
     * Strategy: Check on last day of month.
     */
    public function checkMonthlyBonus(int $studentId, $dateInput): void
    {
        $date = Carbon::parse($dateInput);

        // Only check if we are at the end of the month
        if ($date->copy()->addDay()->month === $date->month) {
            // Not the last day yet.
            // But maybe check if today is Friday and month ends on Weekend?
            // Simplification: Check on last calendar day OR if current date is effectively end of month attendance.
            // Let's stick to: Check purely on Last Day of Month logic or close to it.
            // Risk: If student attends on 28th, but month ends 31st, and 29-31 are holidays/weekends.
            // Safer: Check if remaining days are weekends?

            // For robustness: Just return if day < 25 (skip early checks)
            if ($date->day < 25) {
                return;
            }

            // If today is not the last day, we might wait?
            // But if they don't attend on the last day (e.g. sick), they miss logic trigger if triggered by attendance.
            // The system implies automation.
            // Let's check: "Is the month 'completed' for school purposes?"
            // Hard to know.
            // Let's act on Last Day of Month only.
            if ($date->format('Y-m-d') !== $date->copy()->endOfMonth()->format('Y-m-d')) {
                // Optimization: Don't block, just verifying logic.
                // Actually, if today is Friday 28th, and 31st is Monday, we have to wait.
                // Strict Check: only award if today == endOfMonth or we can prove no more school days.

                // Let's try to match "Business Days" count logic again.
            }
        }

        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        $alreadyAwarded = StudentPoint::where('student_id', $studentId)
            ->where('source', 'monthly_bonus')
            ->whereBetween('date', [$startOfMonth->format('Y-m-d'), $endOfMonth->format('Y-m-d')])
            ->exists();

        if ($alreadyAwarded) {
            // Ensure badge
            $student = \App\Models\User::find($studentId);
            if ($student) {
                $this->awardBadge($student, 'perfect-month');
            }

            return;
        }

        // 1. No Absences
        $hasAbsence = Attendance::where('student_id', $studentId)
            ->whereBetween('attendance_date', [$startOfMonth, $endOfMonth])
            ->where('status', 'absent')
            ->exists();

        if ($hasAbsence) {
            return;
        }

        // 2. Count Business Days (Mon-Fri)
        // This is a naive calculation, not accounting for holidays.
        $businessDays = 0;
        $tempDate = $startOfMonth->copy();
        while ($tempDate->lte($endOfMonth)) {
            if ($tempDate->isWeekday()) {
                $businessDays++;
            }
            $tempDate->addDay();
        }

        // 3. Count Attended
        $daysAttended = Attendance::where('student_id', $studentId)
            ->whereBetween('attendance_date', [$startOfMonth, $endOfMonth])
            ->whereIn('status', ['present', 'late'])
            ->distinct('attendance_date')
            ->count();

        // Tolerance: If holidays exist, Attended < BusinessDays.
        // We can't know holidays easily without a table.
        // MVP Rule: If Attended >= BusinessDays - 5 (generous holiday allowance?) matches roughly.
        // Or STRICT: Attended >= 20. (Most months have 20-22 school days).

        if ($daysAttended >= ($businessDays - 2)) { // Allow 2 days margin (e.g. holidays)
            StudentPoint::create([
                'student_id' => $studentId,
                'points' => 100,
                'source' => 'monthly_bonus',
                'date' => $date,
                'description' => 'Perfect attendance bonus (Month '.$date->englishMonth.')',
            ]);

            $student = \App\Models\User::find($studentId);
            if ($student) {
                $student->increment('total_points', 100);
                $this->awardBadge($student, 'perfect-month');
            }
        }
    }

    /**
     * Determine "Attendance Champions" for a class in a given month.
     * Criteria:
     * - No Absences (status='absent' count == 0)
     * - Max 1 Late (status='late' count <= 1)
     * - Highest "Present" count (or total attended count, here we treat (present+late) as attended, but rank by 'present')
     *
     * @return array List of User objects (or ID/name) who are champions.
     */
    public function determineMonthlyClassChampions(int $classId, int $month, int $year): array
    {
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Query logic:
        // Group by student_id
        // Filter by class_id
        // Filter date range
        // Aggregates:
        //  - absent_count
        //  - late_count
        //  - present_count
        //  - total_points (tie-breaker)

        $candidates = \App\Models\Attendance::query()
            ->select('student_id',
                \Illuminate\Support\Facades\DB::raw("COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count"),
                \Illuminate\Support\Facades\DB::raw("COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count"),
                \Illuminate\Support\Facades\DB::raw("COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count")
            )
            ->where('class_id', $classId)
            ->whereBetween('attendance_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->groupBy('student_id')
            ->having('absent_count', '=', 0)
            ->having('late_count', '<=', 1)
            ->orderByDesc('present_count')
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        // We might have multiple students with the same 'present_count'.
        // Let's get the max score.
        $maxPresent = $candidates->first()->present_count;

        // Filter those who have the max score
        $topCandidates = $candidates->filter(fn ($c) => $c->present_count === $maxPresent);

        // Tie-breaker: Total Points (if we want single champion, or return multiple).
        // Let's fetch User details for these top candidates and sort by their total_points.
        $studentIds = $topCandidates->pluck('student_id')->toArray();

        $champions = \App\Models\User::whereIn('id', $studentIds)
            ->orderByDesc('total_points')
            ->get();

        // If strict single champion needed:
        // return [$champions->first()];

        // Returning all who tied for top attendance stats, sorted by points.
        return $champions->values()->toArray();
    }
}
