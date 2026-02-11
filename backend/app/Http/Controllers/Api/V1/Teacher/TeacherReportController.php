<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Jobs\ExportTeacherReport;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TeacherReportController extends Controller
{
    /**
     * Get teacher attendance reports for a specific month
     * 
     * GET /api/v1/teacher/reports?month=YYYY-MM
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        $teacher = $request->user();
        $month = $request->input('month', now()->format('Y-m'));

        try {
            // Parse month to get date range
            $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            // Optimized query with specific columns and eager loading
            $attendances = Attendance::select([
                    'id',
                    'student_id',
                    'schedule_id',
                    'attendance_date',
                    'status',
                    'check_in_time',
                    'is_manual',
                    'notes'
                ])
                ->with(['student:id,name,username,nis'])
                ->whereHas('schedule', function ($query) use ($teacher) {
                    $query->where('teacher_id', $teacher->id)
                          ->where('school_id', $teacher->school_id);
                })
                ->where('school_id', $teacher->school_id)
                ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->orderBy('attendance_date', 'desc')
                ->paginate(20);

            // Calculate statistics using aggregation
            $stats = Attendance::select([
                    DB::raw('COUNT(CASE WHEN status = "present" THEN 1 END) as total_hadir'),
                    DB::raw('COUNT(CASE WHEN status = "late" THEN 1 END) as total_terlambat'),
                    DB::raw('COUNT(CASE WHEN status = "sick" THEN 1 END) as total_sakit'),
                    DB::raw('COUNT(CASE WHEN status = "permit" THEN 1 END) as total_izin'),
                    DB::raw('COUNT(CASE WHEN status = "alpha" THEN 1 END) as total_alpha'),
                    DB::raw('COUNT(*) as total_records')
                ])
                ->whereHas('schedule', function ($query) use ($teacher) {
                    $query->where('teacher_id', $teacher->id)
                          ->where('school_id', $teacher->school_id);
                })
                ->where('school_id', $teacher->school_id)
                ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->first();

            // Calculate percentage
            $totalRecords = $stats->total_records ?? 0;
            $persentaseKehadiran = $totalRecords > 0 
                ? round((($stats->total_hadir + $stats->total_terlambat) / $totalRecords) * 100, 2)
                : 0;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'month' => $month,
                    'period' => [
                        'start' => $startDate->format('Y-m-d'),
                        'end' => $endDate->format('Y-m-d'),
                    ],
                    'statistics' => [
                        'total_hadir' => $stats->total_hadir ?? 0,
                        'total_terlambat' => $stats->total_terlambat ?? 0,
                        'total_sakit' => $stats->total_sakit ?? 0,
                        'total_izin' => $stats->total_izin ?? 0,
                        'total_alpha' => $stats->total_alpha ?? 0,
                        'total_records' => $totalRecords,
                        'persentase_kehadiran' => $persentaseKehadiran,
                    ],
                    'attendances' => $attendances->map(function ($attendance) {
                        return [
                            'id' => $attendance->id,
                            'student' => [
                                'id' => $attendance->student->id ?? null,
                                'name' => $attendance->student->name ?? 'Unknown',
                                'username' => $attendance->student->username ?? null,
                                'nis' => $attendance->student->nis ?? null,
                            ],
                            'attendance_date' => $attendance->attendance_date,
                            'status' => $attendance->status,
                            'check_in_time' => $attendance->check_in_time,
                            'is_manual' => $attendance->is_manual,
                            'notes' => $attendance->notes,
                        ];
                    }),
                    'pagination' => [
                        'current_page' => $attendances->currentPage(),
                        'last_page' => $attendances->lastPage(),
                        'per_page' => $attendances->perPage(),
                        'total' => $attendances->total(),
                        'from' => $attendances->firstItem(),
                        'to' => $attendances->lastItem(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Teacher report error', [
                'teacher_id' => $teacher->id,
                'month' => $month,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memuat laporan',
            ], 500);
        }
    }

    /**
     * Export teacher attendance report
     * 
     * POST /api/v1/teacher/reports/export
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'format' => 'nullable|in:xlsx,csv,pdf',
        ]);

        $teacher = $request->user();
        $month = $validated['month'];
        $format = $validated['format'] ?? 'xlsx';

        try {
            // Dispatch async job
            $job = new ExportTeacherReport(
                $teacher->id,
                $teacher->school_id,
                $month,
                $format
            );

            dispatch($job);

            // Log export request
            Log::channel('audit')->info('teacher_report_export_requested', [
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'month' => $month,
                'format' => $format,
                'timestamp' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Laporan sedang diproses. Anda akan menerima notifikasi ketika sudah siap.',
                'data' => [
                    'month' => $month,
                    'format' => $format,
                    'estimated_time' => '1-2 menit',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Teacher report export error', [
                'teacher_id' => $teacher->id,
                'month' => $month,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses permintaan export',
            ], 500);
        }
    }
}
