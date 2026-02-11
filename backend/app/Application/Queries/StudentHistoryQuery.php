<?php

declare(strict_types=1);

namespace App\Application\Queries;

use App\Models\Attendance;
use Carbon\Carbon;

/**
 * CQRS-light query service for student attendance history.
 *
 * Reads directly from the Attendance model for per-student detail queries.
 */
class StudentHistoryQuery
{
    /**
     * Get attendance history for a student within a date range.
     */
    public function getHistory(
        int $studentId,
        int $schoolId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $limit = 30,
    ): array {
        $from = $from ?? Carbon::now()->subDays(30);
        $to = $to ?? Carbon::now();

        return Attendance::where('student_id', $studentId)
            ->where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->with(['schedule:id,subject_id,start_time,end_time', 'schedule.subject:id,name'])
            ->orderByDesc('attendance_date')
            ->limit($limit)
            ->get()
            ->map(fn (Attendance $a) => [
                'id' => $a->id,
                'date' => $a->attendance_date?->format('Y-m-d'),
                'state' => $a->state?->value ?? $a->getCurrentState()->value,
                'state_label' => $a->state_label,
                'status' => $a->status,
                'check_in_time' => $a->check_in_time?->format('H:i:s'),
                'check_out_time' => $a->check_out_time?->format('H:i:s'),
                'subject' => $a->schedule?->subject?->name ?? '-',
                'is_manual' => $a->is_manual,
                'source' => $a->source,
            ])
            ->toArray();
    }

    /**
     * Get attendance statistics for a student.
     */
    public function getStatistics(
        int $studentId,
        int $schoolId,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $from = $from ?? Carbon::now()->startOfMonth();
        $to = $to ?? Carbon::now();

        $query = Attendance::where('student_id', $studentId)
            ->where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);

        $total = (clone $query)->count();
        $present = (clone $query)->where('status', 'present')->count();
        $late = (clone $query)->where('status', 'late')->count();
        $absent = (clone $query)->where('status', 'absent')->count();

        return [
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'total' => $total,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'attendance_rate' => $total > 0 ? round(($present + $late) / $total * 100, 2) : 0,
        ];
    }
}
