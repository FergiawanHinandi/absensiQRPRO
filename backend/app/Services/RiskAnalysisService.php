<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\StudentAttendanceRisk;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RiskAnalysisService
{
    /**
     * Calculate comprehensive risk score for a student.
     */
    public function calculateStudentRisk(User $student): array
    {
        $score = 0;
        $factors = [];
        $today = Carbon::today();
        $thirtyDaysAgo = $today->copy()->subDays(30);
        $sixtyDaysAgo = $today->copy()->subDays(60);

        $breakdown = [
            'attendance_rate_score' => 0,
            'absence_streak_score' => 0,
            'late_frequency_score' => 0,
            'attendance_drop_score' => 0,
        ];

        // 1. Attendance Rate (Last 30 Days)
        // We calculate based on recorded attendance days (assuming 'absent' is actively recorded)
        // Logic: (Present + Late) / Total Records
        $recentStats = Attendance::where('student_id', $student->id)
            ->whereBetween('attendance_date', [$thirtyDaysAgo, $today])
            ->selectRaw("
                count(*) as total,
                count(case when status in ('present', 'late') then 1 end) as present,
                count(case when status = 'late' then 1 end) as late_count
            ")
            ->first();

        $totalRecent = $recentStats->total ?? 0;
        $presentRecent = $recentStats->present ?? 0;
        $rateRecent = $totalRecent > 0 ? ($presentRecent / $totalRecent) * 100 : 100;

        if ($rateRecent < 70) {
            $score += 40;
            $breakdown['attendance_rate_score'] = 40;
            $factors[] = 'Attendance rate < 70% ('.number_format($rateRecent, 1).'%)';
        } elseif ($rateRecent < 85) {
            $score += 20;
            $breakdown['attendance_rate_score'] = 20;
            $factors[] = 'Attendance rate 70-85% ('.number_format($rateRecent, 1).'%)';
        }

        // 2. Consecutive Absences
        // Get last few records to check sequence
        $lastRecords = Attendance::where('student_id', $student->id)
            ->where('attendance_date', '<=', $today)
            ->orderBy('attendance_date', 'desc')
            ->limit(5)
            ->get();

        $consecutiveAbsent = 0;
        foreach ($lastRecords as $record) {
            if ($record->status === 'absent') {
                $consecutiveAbsent++;
            } else {
                break; // Break on first non-absent
            }
        }

        if ($consecutiveAbsent >= 3) {
            $score += 30;
            $breakdown['absence_streak_score'] = 30;
            $factors[] = '3+ consecutive absences';
        } elseif ($consecutiveAbsent == 2) {
            $score += 15;
            $breakdown['absence_streak_score'] = 15;
            $factors[] = '2 consecutive absences';
        }

        // 3. Late Frequency (Last 30 Days)
        $lateCount = $recentStats->late_count ?? 0;
        if ($lateCount >= 10) {
            $score += 25;
            $breakdown['late_frequency_score'] = 25;
            $factors[] = 'High late frequency (10+ days)';
        } elseif ($lateCount >= 5) {
            $score += 15;
            $breakdown['late_frequency_score'] = 15;
            $factors[] = 'Moderate late frequency (5+ days)';
        }

        // 4. Sudden Drop (>15% vs previous month)
        // Calculate previous 30 days (days 31-60 ago)
        $prevStats = Attendance::where('student_id', $student->id)
            ->whereBetween('attendance_date', [$sixtyDaysAgo, $thirtyDaysAgo])
            ->selectRaw("
                count(*) as total,
                count(case when status in ('present', 'late') then 1 end) as present
            ")
            ->first();

        $totalPrev = $prevStats->total ?? 0;
        $ratePrev = $totalPrev > 0
            ? ($prevStats->present / $totalPrev) * 100
            : 100; // Assume perfect if no history

        // Only calculate drop if we have significant history (e.g., > 5 records in previous month)
        if ($totalPrev > 5) {
            $drop = $ratePrev - $rateRecent;
            if ($drop > 15) {
                $score += 20;
                $breakdown['attendance_drop_score'] = 20;
                $factors[] = 'Significant attendance drop (>15%) compared to previous month';
            }
        }

        // Determine Risk Level
        $level = 'Low';
        $color = 'green';
        if ($score >= 60) {
            $level = 'High';
            $color = 'red';
        } elseif ($score >= 30) {
            $level = 'Medium';
            $color = 'yellow';
        }

        // 5. Trend Analysis (vs 14 days ago)
        $trend = 'unchanged';
        $trendMessage = 'Stable';

        // Find risk record closest to 14 days ago (range 10-20 days)
        $historicalRisk = StudentAttendanceRisk::where('student_id', $student->id)
            ->whereBetween('calculated_at', [$today->copy()->subDays(20), $today->copy()->subDays(10)])
            ->orderBy('calculated_at', 'desc')
            ->first();

        if ($historicalRisk) {
            $prevScore = $historicalRisk->risk_score;
            if ($score < $prevScore) {
                $trend = 'improved';
                $diff = $prevScore - $score;
                $trendMessage = "Improved (Score dropped by {$diff})";
            } elseif ($score > $prevScore) {
                $trend = 'worsened';
                $diff = $score - $prevScore;
                $trendMessage = "Worsened (Score increased by {$diff})";
            }
        } else {
            $trend = 'new';
            $trendMessage = 'New Assessment';
        }

        return [
            'student_id' => $student->id,
            'student_name' => $student->name,
            'risk_score' => $score,
            'breakdown' => $breakdown,
            'risk_level' => $level,
            'risk_color' => $color, // For UI
            'factors' => $factors,
            'risk_trend' => $trend, // improved, unchanged, worsened, new
            'trend_detail' => $trendMessage,
            'details' => [
                'current_rate' => round($rateRecent, 1),
                'previous_rate' => round($ratePrev, 1),
                'consecutive_absent' => $consecutiveAbsent,
                'late_count' => $lateCount,
            ],
            'assessed_at' => now()->toIso8601String(),
        ];
    }
}
