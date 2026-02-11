<?php

declare(strict_types=1);

namespace App\Application\Queries;

use App\Application\Queries\Contracts\DashboardQueryInterface;
use App\Infrastructure\Persistence\AttendanceSummaryReadModel;
use Carbon\Carbon;

/**
 * CQRS-light query service for school dashboard data.
 *
 * Reads from the denormalized attendance_summary_views table
 * that is populated by event-driven projectors.
 *
 * Bound to DashboardQueryInterface when FEATURE_USE_READ_MODEL=true.
 */
class SchoolDashboardQuery implements DashboardQueryInterface
{
    public function __construct(
        private readonly AttendanceSummaryReadModel $readModel,
    ) {}

    /**
     * Get complete dashboard data for a school.
     */
    public function getDashboard(int $schoolId): array
    {
        return cache()->remember(
            "school_dashboard_{$schoolId}",
            now()->addMinutes(5),
            fn () => $this->buildDashboard($schoolId),
        );
    }

    /**
     * Get daily summary for a school on a specific date.
     */
    public function getDailySummary(int $schoolId, string $date): ?array
    {
        $summary = $this->readModel->getSchoolDailySummary($schoolId, $date);

        if (! $summary || (int) ($summary->total_students ?? 0) === 0) {
            return null;
        }

        return [
            'total_students' => (int) ($summary->total_students ?? 0),
            'present' => (int) ($summary->present_count ?? 0),
            'late' => (int) ($summary->late_count ?? 0),
            'absent' => (int) ($summary->absent_count ?? 0),
            'excused' => (int) ($summary->excused_count ?? 0),
            'attendance_rate' => (float) ($summary->attendance_rate ?? 0),
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
        $trend = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $summary = $this->readModel->getSchoolDailySummary($schoolId, $date->format('Y-m-d'));

            $trend[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->shortDayName,
                'rate' => (float) ($summary->attendance_rate ?? 0),
            ];
        }

        return $trend;
    }
}
