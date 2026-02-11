<?php

namespace App\Services;

use App\Models\Attendance;
use Illuminate\Support\Facades\DB;

class StudentSubjectAttendanceAnalysisService
{
    /**
     * Get attendance behavior per subject for a student
     */
    public function analyzePerSubject(int $studentId): array
    {
        // Grouped query by subject_id
        $rows = Attendance::select([
                'subject_id',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status = 'present' then 1 else 0 end) as present_count"),
                DB::raw("sum(case when status = 'late' then 1 else 0 end) as late_count"),
                DB::raw("sum(case when status = 'alpha' then 1 else 0 end) as alpha_count"),
            ])
            ->where('student_id', $studentId)
            ->groupBy('subject_id')
            ->get();

        $summary = [];
        $maxLate = null;
        $maxAlpha = null;
        $subjectMostLate = null;
        $subjectMostAlpha = null;

        foreach ($rows as $row) {
            $rate = $row->total > 0 ? round(($row->present_count + $row->late_count) * 100 / $row->total, 2) : 0;
            $summary[$row->subject_id] = [
                'subject_id' => $row->subject_id,
                'attendance_rate' => $rate,
                'late_count' => $row->late_count,
                'alpha_count' => $row->alpha_count,
                'total' => $row->total,
            ];
            if (is_null($maxLate) || $row->late_count > $maxLate) {
                $maxLate = $row->late_count;
                $subjectMostLate = $row->subject_id;
            }
            if (is_null($maxAlpha) || $row->alpha_count > $maxAlpha) {
                $maxAlpha = $row->alpha_count;
                $subjectMostAlpha = $row->subject_id;
            }
        }

        return [
            'per_subject' => $summary,
            'subject_with_most_late' => $subjectMostLate,
            'subject_with_most_alpha' => $subjectMostAlpha,
        ];
    }
}
