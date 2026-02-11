<?php

declare(strict_types=1);

namespace App\Application\Queries;

use App\Application\Queries\Contracts\DashboardQueryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard query backed by live COUNT() queries on the attendances table.
 *
 * This is the rollback / fallback strategy.
 * When FEATURE_USE_READ_MODEL=false, this class is bound to DashboardQueryInterface.
 * It preserves the exact same response format as ReadModelDashboardQuery.
 */
class CountBasedDashboardQuery implements DashboardQueryInterface
{
    public function getDashboard(int $schoolId): array
    {
        return cache()->remember(
            "school_dashboard_{$schoolId}",
            now()->addMinutes(5),
            fn () => $this->buildDashboard($schoolId),
        );
    }

    public function getDailySummary(int $schoolId, string $date): ?array
    {
        $row = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->whereDate('attendance_date', $date)
            ->selectRaw("
                COUNT(*) as total_students,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status IN ('sick','excused','permit') THEN 1 ELSE 0 END) as excused
            ")
            ->first();

        if (! $row || (int) $row->total_students === 0) {
            return null;
        }

        $total = (int) $row->total_students;
        $present = (int) $row->present;
        $late = (int) $row->late;
        $rate = $total > 0 ? round(($present + $late) / $total * 100, 2) : 0;

        return [
            'total_students' => $total,
            'present' => $present,
            'late' => $late,
            'absent' => (int) $row->absent,
            'excused' => (int) $row->excused,
            'attendance_rate' => $rate,
        ];
    }

    private function buildDashboard(int $schoolId): array
    {
        $today = Carbon::today();
        $todaySummary = $this->getDailySummary($schoolId, $today->format('Y-m-d'));

        return [
            'today' => [
                'date' => $today->format('Y-m-d'),
                'total_students' => $todaySummary['total_students'] ?? 0,
                'present' => $todaySummary['present'] ?? 0,
                'late' => $todaySummary['late'] ?? 0,
                'absent' => $todaySummary['absent'] ?? 0,
                'excused' => $todaySummary['excused'] ?? 0,
                'attendance_rate' => (float) ($todaySummary['attendance_rate'] ?? 0),
            ],
            'this_week' => $this->getWeekTrend($schoolId, $today),
        ];
    }

    private function getWeekTrend(int $schoolId, Carbon $today): array
    {
        $weekStart = $today->copy()->startOfWeek();
        $weekEnd = $weekStart->copy()->addDays(6);

        $rows = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->whereBetween('attendance_date', [
                $weekStart->format('Y-m-d'),
                $weekEnd->format('Y-m-d'),
            ])
            ->selectRaw("
                DATE(attendance_date) as att_date,
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as attended
            ")
            ->groupBy(DB::raw('DATE(attendance_date)'))
            ->get()
            ->keyBy('att_date');

        $trend = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $key = $date->format('Y-m-d');
            $row = $rows->get($key);
            $rate = ($row && (int) $row->total > 0)
                ? round((int) $row->attended / (int) $row->total * 100, 2)
                : 0;

            $trend[] = [
                'date' => $key,
                'day' => $date->shortDayName,
                'rate' => $rate,
            ];
        }

        return $trend;
    }
}
