<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

/**
 * Export Teacher Report Job
 * 
 * Generates attendance reports for teachers in various formats (XLSX, CSV, PDF).
 * 
 * TENANT SAFETY:
 * - Extends TenantAwareJob to ensure school_id context
 * - All queries are scoped to the school_id
 * - Validates teacher belongs to the correct school
 * 
 * Usage:
 *   ExportTeacherReport::dispatch($schoolId, $teacherId, $month, $format);
 * 
 * @version 2.0.0 - Updated to extend TenantAwareJob for tenant safety
 */
class ExportTeacherReport extends TenantAwareJob
{
    public $timeout = 300; // 5 minutes
    public $tries = 3;

    protected int $teacherId;
    protected string $month;
    protected string $format;

    /**
     * Create a new job instance.
     *
     * @param int $schoolId The school ID for tenant context (REQUIRED)
     * @param int $teacherId The teacher ID
     * @param string $month The month in Y-m format
     * @param string $format The export format (xlsx, csv, pdf)
     */
    public function __construct(int $schoolId, int $teacherId, string $month, string $format = 'xlsx')
    {
        parent::__construct($schoolId);
        $this->teacherId = $teacherId;
        $this->month = $month;
        $this->format = $format;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // ✅ TENANT SAFETY: Validate teacher belongs to this school
            $teacher = User::where('school_id', $this->schoolId)
                ->findOrFail($this->teacherId);
            
            // Parse month to get date range
            $startDate = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            // ✅ TENANT SAFETY: Fetch attendance data with explicit school_id filter
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
                ->with([
                    'student:id,name,username,nis',
                    'schedule:id,class_id,subject_id',
                    'schedule.class:id,name',
                    'schedule.subject:id,name'
                ])
                ->whereHas('schedule', function ($query) {
                    $query->where('teacher_id', $this->teacherId)
                          ->where('school_id', $this->schoolId);
                })
                ->where('school_id', $this->schoolId) // ✅ Explicit school_id filter
                ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->orderBy('attendance_date', 'desc')
                ->get();

            // Calculate statistics
            $stats = [
                'total_hadir' => $attendances->where('status', 'present')->count(),
                'total_terlambat' => $attendances->where('status', 'late')->count(),
                'total_sakit' => $attendances->where('status', 'sick')->count(),
                'total_izin' => $attendances->where('status', 'permit')->count(),
                'total_alpha' => $attendances->where('status', 'alpha')->count(),
                'total_records' => $attendances->count(),
            ];

            $stats['persentase_kehadiran'] = $stats['total_records'] > 0
                ? round((($stats['total_hadir'] + $stats['total_terlambat']) / $stats['total_records']) * 100, 2)
                : 0;

            // Generate file based on format
            $filePath = match($this->format) {
                'csv' => $this->generateCsv($teacher, $attendances, $stats, $startDate, $endDate),
                'pdf' => $this->generatePdf($teacher, $attendances, $stats, $startDate, $endDate),
                default => $this->generateXlsx($teacher, $attendances, $stats, $startDate, $endDate),
            };

            // Log successful export
            Log::channel('audit')->info('teacher_report_exported', [
                'teacher_id' => $this->teacherId,
                'school_id' => $this->schoolId,
                'month' => $this->month,
                'format' => $this->format,
                'file_path' => $filePath,
                'records_count' => $attendances->count(),
                'timestamp' => now(),
            ]);

            $teacher->notify(new \App\Notifications\ReportReadyNotification($filePath, $this->format, $this->month));

        } catch (\Exception $e) {
            Log::error('Export teacher report job failed', [
                'teacher_id' => $this->teacherId,
                'month' => $this->month,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate XLSX file
     */
    protected function generateXlsx(User $teacher, $attendances, array $stats, Carbon $startDate, Carbon $endDate): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $sheet->setCellValue('A1', 'LAPORAN ABSENSI GURU');
        $sheet->setCellValue('A2', 'Nama Guru: ' . $teacher->name);
        $sheet->setCellValue('A3', 'Periode: ' . $startDate->format('d M Y') . ' - ' . $endDate->format('d M Y'));
        $sheet->setCellValue('A4', '');

        // Statistics
        $sheet->setCellValue('A5', 'RINGKASAN');
        $sheet->setCellValue('A6', 'Total Hadir: ' . $stats['total_hadir']);
        $sheet->setCellValue('A7', 'Total Terlambat: ' . $stats['total_terlambat']);
        $sheet->setCellValue('A8', 'Total Sakit: ' . $stats['total_sakit']);
        $sheet->setCellValue('A9', 'Total Izin: ' . $stats['total_izin']);
        $sheet->setCellValue('A10', 'Total Alpha: ' . $stats['total_alpha']);
        $sheet->setCellValue('A11', 'Persentase Kehadiran: ' . $stats['persentase_kehadiran'] . '%');
        $sheet->setCellValue('A12', '');

        // Table headers
        $headerRow = 13;
        $sheet->setCellValue('A' . $headerRow, 'No');
        $sheet->setCellValue('B' . $headerRow, 'Tanggal');
        $sheet->setCellValue('C' . $headerRow, 'NIS');
        $sheet->setCellValue('D' . $headerRow, 'Nama Siswa');
        $sheet->setCellValue('E' . $headerRow, 'Kelas');
        $sheet->setCellValue('F' . $headerRow, 'Mata Pelajaran');
        $sheet->setCellValue('G' . $headerRow, 'Status');
        $sheet->setCellValue('H' . $headerRow, 'Waktu Check-in');
        $sheet->setCellValue('I' . $headerRow, 'Manual');
        $sheet->setCellValue('J' . $headerRow, 'Catatan');

        // Data rows
        $row = $headerRow + 1;
        $no = 1;
        foreach ($attendances as $attendance) {
            $sheet->setCellValue('A' . $row, $no++);
            $sheet->setCellValue('B' . $row, $attendance->attendance_date);
            $sheet->setCellValue('C' . $row, $attendance->student->nis ?? '-');
            $sheet->setCellValue('D' . $row, $attendance->student->name ?? 'Unknown');
            $sheet->setCellValue('E' . $row, $attendance->schedule->class->name ?? '-');
            $sheet->setCellValue('F' . $row, $attendance->schedule->subject->name ?? '-');
            $sheet->setCellValue('G' . $row, ucfirst($attendance->status));
            $sheet->setCellValue('H' . $row, $attendance->check_in_time ?? '-');
            $sheet->setCellValue('I' . $row, $attendance->is_manual ? 'Ya' : 'Tidak');
            $sheet->setCellValue('J' . $row, $attendance->notes ?? '-');
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Save file
        $fileName = 'laporan_guru_' . $teacher->id . '_' . $this->month . '.xlsx';
        $filePath = 'exports/teacher_reports/' . $fileName;
        
        $writer = new Xlsx($spreadsheet);
        $writer->save(storage_path('app/' . $filePath));

        return $filePath;
    }

    /**
     * Generate CSV file
     */
    protected function generateCsv(User $teacher, $attendances, array $stats, Carbon $startDate, Carbon $endDate): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $sheet->fromArray([
            ['No', 'Tanggal', 'NIS', 'Nama Siswa', 'Kelas', 'Mata Pelajaran', 'Status', 'Waktu Check-in', 'Manual', 'Catatan']
        ]);

        // Data
        $row = 2;
        $no = 1;
        foreach ($attendances as $attendance) {
            $sheet->fromArray([[
                $no++,
                $attendance->attendance_date,
                $attendance->student->nis ?? '-',
                $attendance->student->name ?? 'Unknown',
                $attendance->schedule->class->name ?? '-',
                $attendance->schedule->subject->name ?? '-',
                ucfirst($attendance->status),
                $attendance->check_in_time ?? '-',
                $attendance->is_manual ? 'Ya' : 'Tidak',
                $attendance->notes ?? '-',
            ]], null, 'A' . $row);
            $row++;
        }

        // Save file
        $fileName = 'laporan_guru_' . $teacher->id . '_' . $this->month . '.csv';
        $filePath = 'exports/teacher_reports/' . $fileName;
        
        $writer = new Csv($spreadsheet);
        $writer->save(storage_path('app/' . $filePath));

        return $filePath;
    }

    /**
     * Generate PDF file (placeholder - requires PDF library)
     */
    protected function generatePdf(User $teacher, $attendances, array $stats, Carbon $startDate, Carbon $endDate): string
    {
        $fileName = 'laporan_guru_' . $teacher->id . '_' . $this->month . '.pdf';
        $filePath = 'exports/teacher_reports/' . $fileName;

        if (!Storage::exists('exports/teacher_reports')) {
            Storage::makeDirectory('exports/teacher_reports');
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.teacher', [
            'teacher' => $teacher,
            'attendances' => $attendances,
            'stats' => $stats,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);

        Storage::put($filePath, $pdf->output());

        return $filePath;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ExportTeacherReport job failed permanently', [
            'teacher_id' => $this->teacherId,
            'month' => $this->month,
            'error' => $exception->getMessage(),
        ]);

        $teacher = User::find($this->teacherId);
        if ($teacher) {
            $teacher->notify(new \App\Notifications\ReportFailedNotification($this->month));
        }
    }
}
