<?php

declare(strict_types=1);

namespace App\Application\Queries;

use App\Infrastructure\Persistence\AttendanceSummaryReadModel;
use Carbon\Carbon;

/**
 * CQRS-light query service for attendance summaries.
 *
 * Reads from denormalized read-model tables for fast dashboard queries.
 */
class AttendanceSummaryQuery
{
    public function __construct(
        private readonly AttendanceSummaryReadModel $readModel,
    ) {}

    /**
     * Get daily summary for a school (all classes/schedules).
     */
    public function getDailySummary(int $schoolId, Carbon $date): array
    {
        return $this->readModel->getDailySummary($schoolId, $date->format('Y-m-d'));
    }

    /**
     * Get aggregated school-level summary for a date.
     */
    public function getSchoolSummary(int $schoolId, Carbon $date): ?object
    {
        return $this->readModel->getSchoolDailySummary($schoolId, $date->format('Y-m-d'));
    }

    /**
     * Get weekly summary (aggregated per day).
     */
    public function getWeeklySummary(int $schoolId, Carbon $weekStart): array
    {
        $results = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $summary = $this->readModel->getSchoolDailySummary($schoolId, $date->format('Y-m-d'));

            if ($summary) {
                $results[] = [
                    'date' => $date->format('Y-m-d'),
                    'day_name' => $date->dayName,
                    'total_students' => (int) ($summary->total_students ?? 0),
                    'present_count' => (int) ($summary->present_count ?? 0),
                    'late_count' => (int) ($summary->late_count ?? 0),
                    'absent_count' => (int) ($summary->absent_count ?? 0),
                    'excused_count' => (int) ($summary->excused_count ?? 0),
                    'attendance_rate' => (float) ($summary->attendance_rate ?? 0),
                ];
            }
        }

        return $results;
    }
}
