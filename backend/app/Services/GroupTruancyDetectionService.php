<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GroupTruancyDetectionService
{
    /**
     * Detect classes with possible group truancy in a given month.
     *
     * @param  string|null  $month  (format: 'YYYY-MM', default: current month)
     */
    public function detect(int $schoolId, ?string $month = null): array
    {
        $month = $month ?: Carbon::now()->format('Y-m');
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();

        // Query: For each class, for each day, count students absent
        $absenceQuery = Attendance::query()
            ->select([
                'attendances.class_id',
                DB::raw('DATE(attendances.date) as date'),
                DB::raw('COUNT(DISTINCT attendances.student_id) as absent_count'),
            ])
            ->where('attendances.school_id', $schoolId)
            ->whereBetween('attendances.date', [$start, $end])
            ->where('attendances.status', 'absent')
            ->groupBy('attendances.class_id', DB::raw('DATE(attendances.date)'));

        $absenceRows = $absenceQuery->get();

        // Group by class
        $classAbsenceDays = [];
        foreach ($absenceRows as $row) {
            if ($row->absent_count >= 3) {
                $classAbsenceDays[$row->class_id][] = $row->date;
            }
        }

        // Flag classes with ≥3 students absent on same day more than twice in month
        $flags = [];
        foreach ($classAbsenceDays as $classId => $dates) {
            if (count($dates) > 2) {
                $flags[$classId] = [
                    'class_id' => $classId,
                    'flagged' => true,
                    'dates' => $dates,
                    'count' => count($dates),
                ];
            }
        }

        return [
            'grouped_absence_query' => $absenceRows,
            'class_flags' => array_values($flags),
        ];
    }
}
