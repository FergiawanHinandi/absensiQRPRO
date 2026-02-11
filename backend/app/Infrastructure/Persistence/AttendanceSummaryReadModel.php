<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * Read model for attendance summary views.
 *
 * Provides fast, denormalized reads for dashboards and reports.
 * Updated asynchronously by projectors listening to domain events.
 */
class AttendanceSummaryReadModel
{
    protected string $table = 'attendance_summary_views';

    /**
     * Upsert a summary row (increment/decrement counters).
     */
    public function recordAttendance(
        int $schoolId,
        ?int $classId,
        ?int $scheduleId,
        string $attendanceDate,
        string $status,
    ): void {
        $column = $this->statusToColumn($status);

        DB::table($this->table)->upsert(
            [
                'school_id' => $schoolId,
                'class_id' => $classId,
                'schedule_id' => $scheduleId,
                'attendance_date' => $attendanceDate,
                $column => 1,
                'total_students' => 1,
                'updated_at' => now(),
            ],
            ['school_id', 'class_id', 'schedule_id', 'attendance_date'],
            [
                $column => DB::raw("{$column} + 1"),
                'total_students' => DB::raw('total_students + 1'),
                'updated_at' => now(),
            ]
        );

        $this->recalculateRate($schoolId, $classId, $scheduleId, $attendanceDate);
    }

    /**
     * Update a status change (decrement old, increment new).
     */
    public function updateStatus(
        int $schoolId,
        ?int $classId,
        ?int $scheduleId,
        string $attendanceDate,
        string $oldStatus,
        string $newStatus,
    ): void {
        $oldColumn = $this->statusToColumn($oldStatus);
        $newColumn = $this->statusToColumn($newStatus);

        if ($oldColumn === $newColumn) {
            return;
        }

        DB::table($this->table)
            ->where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->where('schedule_id', $scheduleId)
            ->where('attendance_date', $attendanceDate)
            ->update([
                $oldColumn => DB::raw("GREATEST({$oldColumn} - 1, 0)"),
                $newColumn => DB::raw("{$newColumn} + 1"),
                'updated_at' => now(),
            ]);

        $this->recalculateRate($schoolId, $classId, $scheduleId, $attendanceDate);
    }

    /**
     * Get daily summary for a school.
     */
    public function getDailySummary(int $schoolId, string $date): array
    {
        return DB::table($this->table)
            ->where('school_id', $schoolId)
            ->where('attendance_date', $date)
            ->get()
            ->toArray();
    }

    /**
     * Get aggregated summary for a school on a date.
     */
    public function getSchoolDailySummary(int $schoolId, string $date): ?object
    {
        return DB::table($this->table)
            ->where('school_id', $schoolId)
            ->where('attendance_date', $date)
            ->selectRaw('
                SUM(total_students) as total_students,
                SUM(present_count) as present_count,
                SUM(late_count) as late_count,
                SUM(absent_count) as absent_count,
                SUM(excused_count) as excused_count,
                CASE WHEN SUM(total_students) > 0
                    THEN ROUND((SUM(present_count) + SUM(late_count))::numeric / SUM(total_students) * 100, 2)
                    ELSE 0
                END as attendance_rate
            ')
            ->first();
    }

    private function statusToColumn(string $status): string
    {
        return match ($status) {
            'present' => 'present_count',
            'late' => 'late_count',
            'absent' => 'absent_count',
            'sick', 'excused', 'permit' => 'excused_count',
            default => 'absent_count',
        };
    }

    private function recalculateRate(
        int $schoolId,
        ?int $classId,
        ?int $scheduleId,
        string $attendanceDate,
    ): void {
        DB::table($this->table)
            ->where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->where('schedule_id', $scheduleId)
            ->where('attendance_date', $attendanceDate)
            ->update([
                'attendance_rate' => DB::raw('
                    CASE WHEN total_students > 0
                        THEN ROUND((present_count + late_count)::numeric / total_students * 100, 2)
                        ELSE 0
                    END
                '),
            ]);
    }
}
