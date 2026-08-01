<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TrendService
 *
 * Shared service for attendance trend queries.
 * Eliminates duplicated logic between:
 * - AttendanceTrendController (API charts)
 * - ExportTrendController (Excel/PDF export)
 * - TrendsExport (Excel sheet generation)
 *
 * All trend queries now go through this single service,
 * ensuring consistent date ranges, aggregations, and scoring.
 */
class TrendService
{
    /**
     * Get daily trend data with summary and optional weekly aggregation.
     *
     * @param  int     $schoolId   School to scope data to
     * @param  string  $period     '7d' | '30d' | '90d'
     * @param  int|null $teacherId  Optional teacher filter
     * @return array{
     *   daily: array,
     *   summary: array,
     *   weekly: array|null,
     *   startDate: Carbon,
     *   endDate: Carbon,
     * }
     */
    public function getDailyTrend(int $schoolId, string $period, ?int $teacherId = null): array
    {
        $days = match ($period) {
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };

        $endDate = Carbon::now();
        $startDate = $endDate->copy()->subDays($days - 1);

        // Base query—always scoped to school
        $query = DB::table('attendances')
            ->where('attendances.school_id', $schoolId)
            ->whereBetween('attendances.attendance_date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ]);

        // Teacher filter (scoped to their own schedules)
        if ($teacherId) {
            $query->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
                ->where('schedules.teacher_id', $teacherId);
        }

        // Daily aggregation
        $dailyStats = (clone $query)
            ->select(
                'attendances.attendance_date',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN attendances.status = 'present' THEN 1 ELSE 0 END) as present"),
                DB::raw("SUM(CASE WHEN attendances.status = 'late' THEN 1 ELSE 0 END) as late"),
                DB::raw("SUM(CASE WHEN attendances.status IN ('absent', 'alpha') THEN 1 ELSE 0 END) as absent"),
                DB::raw("SUM(CASE WHEN attendances.status IN ('sick', 'permit') THEN 1 ELSE 0 END) as excused")
            )
            ->groupBy('attendances.attendance_date')
            ->orderBy('attendances.attendance_date')
            ->get()
            ->keyBy('attendance_date');

        // Build complete date series with zero-fill
        $daily = [];
        $totalPresent = 0;
        $totalLate = 0;
        $totalAbsent = 0;
        $totalExcused = 0;
        $totalAll = 0;
        $daysWithData = 0;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $endDate->copy()->subDays($i);
            $dateStr = $date->toDateString();
            $stats = $dailyStats->get($dateStr);

            $present = $stats ? (int) $stats->present : 0;
            $late = $stats ? (int) $stats->late : 0;
            $absent = $stats ? (int) $stats->absent : 0;
            $excused = $stats ? (int) $stats->excused : 0;
            $total = $stats ? (int) $stats->total : 0;

            $rate = $total > 0 ? round((($present + $late) / $total) * 100, 1) : 0;

            $totalPresent += $present;
            $totalLate += $late;
            $totalAbsent += $absent;
            $totalExcused += $excused;
            $totalAll += $total;
            if ($total > 0) {
                $daysWithData++;
            }

            $daily[] = [
                'date' => $dateStr,
                'label' => $this->formatDateLabel($date, $period),
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'excused' => $excused,
                'total' => $total,
                'rate' => $rate,
            ];
        }

        // Summary
        $summary = [
            'period' => $period,
            'days' => $days,
            'total_records' => $totalAll,
            'total_present' => $totalPresent,
            'total_late' => $totalLate,
            'total_absent' => $totalAbsent,
            'total_excused' => $totalExcused,
            'avg_attendance_rate' => $daysWithData > 0
                ? round(array_sum(array_column($daily, 'rate')) / $daysWithData, 1)
                : 0,
            'days_with_data' => $daysWithData,
        ];

        // Weekly aggregation for 30d & 90d
        $weekly = in_array($period, ['30d', '90d'])
            ? $this->aggregateWeekly($daily)
            : null;

        return [
            'daily' => $daily,
            'summary' => $summary,
            'weekly' => $weekly,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
    }

    /**
     * Get week-over-week comparison data.
     *
     * @param  int     $schoolId
     * @param  int|null $teacherId
     * @return array{this_week: array, last_week: array, change_percent: float, trend_direction: string}
     */
    public function getComparison(int $schoolId, ?int $teacherId = null): array
    {
        $now = Carbon::now();

        $thisWeekStart = $now->copy()->subDays(6);
        $lastWeekStart = $now->copy()->subDays(13);
        $lastWeekEnd = $now->copy()->subDays(7);

        $baseQuery = DB::table('attendances')
            ->where('attendances.school_id', $schoolId);

        if ($teacherId) {
            $baseQuery->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
                ->where('schedules.teacher_id', $teacherId);
        }

        // This week (last 7 days)
        $thisWeek = (clone $baseQuery)
            ->whereBetween('attendances.attendance_date', [
                $thisWeekStart->toDateString(),
                $now->toDateString(),
            ])
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN attendances.status IN ('present', 'late') THEN 1 ELSE 0 END) as attended")
            )
            ->first();

        // Last week (7 days before this week)
        $lastWeek = (clone $baseQuery)
            ->whereBetween('attendances.attendance_date', [
                $lastWeekStart->toDateString(),
                $lastWeekEnd->toDateString(),
            ])
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN attendances.status IN ('present', 'late') THEN 1 ELSE 0 END) as attended")
            )
            ->first();

        $twTotal = (int) ($thisWeek->total ?? 0);
        $twAttended = (int) ($thisWeek->attended ?? 0);
        $lwTotal = (int) ($lastWeek->total ?? 0);
        $lwAttended = (int) ($lastWeek->attended ?? 0);

        $thisWeekRate = $twTotal > 0 ? round(($twAttended / $twTotal) * 100, 1) : 0;
        $lastWeekRate = $lwTotal > 0 ? round(($lwAttended / $lwTotal) * 100, 1) : 0;

        $change = $lastWeekRate > 0
            ? round((($thisWeekRate - $lastWeekRate) / $lastWeekRate) * 100, 1)
            : 0;

        return [
            'this_week' => [
                'rate' => $thisWeekRate,
                'total' => $twTotal,
                'attended' => $twAttended,
            ],
            'last_week' => [
                'rate' => $lastWeekRate,
                'total' => $lwTotal,
                'attended' => $lwAttended,
            ],
            'change_percent' => $change,
            'trend_direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable'),
        ];
    }

    /**
     * Format a date label for chart display.
     */
    public function formatDateLabel(Carbon $date, string $period): string
    {
        return match ($period) {
            '7d' => $date->isoFormat('dd'),       // Sen, Sel, ...
            default => $date->isoFormat('DD MMM'), // 01 Jan
        };
    }

    /**
     * Aggregate daily data into weekly chunks (for 30d & 90d views).
     */
    public function aggregateWeekly(array $dailyData): array
    {
        $weeks = [];
        $weekIndex = 0;

        foreach ($dailyData as $day) {
            $carbon = Carbon::parse($day['date']);
            $weekKey = $carbon->isoFormat('YYYY-WW');

            if (! isset($weeks[$weekKey])) {
                $weekIndex++;
                $weeks[$weekKey] = [
                    'week' => $weekIndex,
                    'label' => 'Minggu ' . $weekIndex,
                    'start_date' => $day['date'],
                    'present' => 0,
                    'late' => 0,
                    'absent' => 0,
                    'excused' => 0,
                    'total' => 0,
                ];
            }

            $weeks[$weekKey]['present'] += $day['present'];
            $weeks[$weekKey]['late'] += $day['late'];
            $weeks[$weekKey]['absent'] += $day['absent'];
            $weeks[$weekKey]['excused'] += $day['excused'];
            $weeks[$weekKey]['total'] += $day['total'];
        }

        return array_values(array_map(function ($week) {
            $attended = $week['present'] + $week['late'];
            $week['rate'] = $week['total'] > 0
                ? round(($attended / $week['total']) * 100, 1)
                : 0;

            return $week;
        }, $weeks));
    }
}
