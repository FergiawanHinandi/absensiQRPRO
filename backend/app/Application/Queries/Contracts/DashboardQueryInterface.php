<?php

declare(strict_types=1);

namespace App\Application\Queries\Contracts;

/**
 * Dashboard query contract.
 *
 * Implementations:
 *  - ReadModelDashboardQuery  → reads from attendance_summary_views (fast, eventual)
 *  - CountBasedDashboardQuery → reads from attendances table via COUNT() (slow, consistent)
 *
 * Bound via feature flag: config('features.use_read_model')
 * Rollback: set FEATURE_USE_READ_MODEL=false → instant switch to COUNT()
 */
interface DashboardQueryInterface
{
    /**
     * Get complete dashboard overview for a school.
     *
     * @return array{today: array, this_week: array}
     */
    public function getDashboard(int $schoolId): array;

    /**
     * Get daily summary for a school on a specific date.
     *
     * @return array{total_students: int, present: int, late: int, absent: int, excused: int, attendance_rate: float}|null
     */
    public function getDailySummary(int $schoolId, string $date): ?array;
}
