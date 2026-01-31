<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\AttendanceExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ExportReportRequest;
use App\Http\Requests\Report\MonthlySummaryRequest;
use App\Models\Attendance;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

class ReportExportController extends Controller
{
    /**
     * Export Attendance Report to Excel
     */
    public function exportExcel(ExportReportRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $export = new AttendanceExport(
            $user->school_id,
            $validated['start_date'],
            $validated['end_date'],
            $validated['class_id'] ?? null
        );

        $filename = 'attendance_report_'.date('Y-m-d_His').'.xlsx';

        return Excel::download($export, $filename);
    }

    /**
     * Export Attendance Report to PDF
     */
    public function exportPdf(ExportReportRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $schoolId = $user->school_id;

        $query = Attendance::with(['student', 'schedule.class', 'schedule.subject'])
            ->where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$validated['start_date'], $validated['end_date']]);

        if (! empty($validated['class_id'])) {
            $query->whereHas('schedule', function ($q) use ($validated) {
                $q->where('class_id', $validated['class_id']);
            });
        }

        $attendances = $query->orderBy('attendance_date', 'asc')->get();

        $school = \App\Models\School::find($schoolId);

        $data = [
            'attendances' => $attendances,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'school' => $school,
            'generated_at' => now()->format('d-m-Y H:i:s'),
        ];

        $pdf = Pdf::loadView('reports.attendance', $data);
        $filename = 'attendance_report_'.date('Y-m-d_His').'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Get monthly summary for a class
     */
    public function monthlySummary(MonthlySummaryRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $schoolId = $user->school_id;

        // Get all students in the class with eager loading
        $students = \App\Models\User::where('role_type', 'student')
            ->where('school_id', $schoolId)
            ->whereHas('activeClass', function ($q) use ($validated) {
                $q->where('class_id', $validated['class_id']);
            })
            ->with(['school']) // Eager load school
            ->get();

        $startDate = "{$validated['year']}-{$validated['month']}-01";
        $endDate = date('Y-m-t', strtotime($startDate));

        // ✅ FIX N+1: Fetch ALL attendances in ONE query
        $studentIds = $students->pluck('id')->toArray();
        $allAttendances = Attendance::with(['student:id,name,nis', 'schedule.class:id,name', 'schedule.subject:id,name', 'schedule.teacher:id,name'])
            ->where('school_id', $schoolId)
            ->whereIn('student_id', $studentIds)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->get()
            ->groupBy('student_id'); // Group by student for easy access

        $summary = $students->map(function ($student) use ($allAttendances) {
            // ✅ Get attendances from already-loaded collection (NO additional query)
            $attendances = $allAttendances->get($student->id, collect());

            return [
                'student_id' => $student->id,
                'student_name' => $student->name,
                'nis' => $student->nis,
                'total_present' => $attendances->where('status', 'present')->count(),
                'total_late' => $attendances->where('status', 'late')->count(),
                'total_sick' => $attendances->where('status', 'sick')->count(),
                'total_permit' => $attendances->where('status', 'permit')->count(),
                'total_alpha' => $attendances->where('status', 'alpha')->count(),
                'total_days' => $attendances->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }
}
