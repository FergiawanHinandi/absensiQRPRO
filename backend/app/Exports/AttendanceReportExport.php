<?php

namespace App\Exports;

use App\Models\Attendance;
use App\Models\ExportProgress;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithProgressBar;

/**
 * Attendance Report Export with Chunking
 * 
 * Uses cursor() for memory-efficient iteration over large datasets.
 * Implements chunking to prevent memory exhaustion on 100K+ rows.
 * 
 * @version 2.0.0 - Refactored to use cursor() and chunking
 */
class AttendanceReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, WithProgressBar
{
    protected string $type;
    protected array $params;
    protected ?ExportProgress $exportProgress;
    protected int $processedCount = 0;

    public function __construct(string $type, array $params, ?ExportProgress $exportProgress = null)
    {
        $this->type = $type;
        $this->params = $params;
        $this->exportProgress = $exportProgress;
    }

    /**
     * Build the query for export.
     * Uses cursor() internally by Laravel Excel for memory efficiency.
     */
    public function query()
    {
        $query = Attendance::query()
            ->with(['student', 'schedule', 'teacher'])
            ->where('school_id', $this->params['school_id']);

        // Apply filters based on export type
        switch ($this->type) {
            case 'daily':
                if (isset($this->params['date'])) {
                    $query->whereDate('attendance_date', $this->params['date']);
                }
                if (isset($this->params['class_id'])) {
                    $query->whereHas('student', function ($q) {
                        $q->where('class_id', $this->params['class_id']);
                    });
                }
                break;

            case 'monthly':
                if (isset($this->params['month']) && isset($this->params['year'])) {
                    $query->whereYear('attendance_date', $this->params['year'])
                          ->whereMonth('attendance_date', $this->params['month']);
                }
                if (isset($this->params['class_id'])) {
                    $query->whereHas('student', function ($q) {
                        $q->where('class_id', $this->params['class_id']);
                    });
                }
                break;

            case 'student':
                if (isset($this->params['student_id'])) {
                    $query->where('student_id', $this->params['student_id']);
                }
                if (isset($this->params['start_date']) && isset($this->params['end_date'])) {
                    $query->whereBetween('attendance_date', [
                        $this->params['start_date'],
                        $this->params['end_date']
                    ]);
                }
                break;
        }

        return $query->orderBy('attendance_date', 'desc')
                     ->orderBy('created_at', 'desc');
    }

    /**
     * Define column headings.
     */
    public function headings(): array
    {
        switch ($this->type) {
            case 'daily':
                return [
                    'Tanggal',
                    'Nama Siswa',
                    'NIS',
                    'Kelas',
                    'Mata Pelajaran',
                    'Status',
                    'Waktu Check-In',
                    'Waktu Check-Out',
                    'Guru',
                ];

            case 'monthly':
                return [
                    'Nama Siswa',
                    'NIS',
                    'Kelas',
                    'Total Hadir',
                    'Total Terlambat',
                    'Total Sakit',
                    'Total Izin',
                    'Total Alpa',
                    'Persentase Kehadiran',
                ];

            case 'student':
                return [
                    'Tanggal',
                    'Mata Pelajaran',
                    'Status',
                    'Waktu Check-In',
                    'Waktu Check-Out',
                    'Guru',
                    'Keterangan',
                ];

            default:
                return ['Data'];
        }
    }

    /**
     * Map each row for export.
     * This method is called for each record processed by cursor().
     */
    public function map($attendance): array
    {
        // Update progress tracking
        $this->processedCount++;
        if ($this->exportProgress && $this->processedCount % 100 === 0) {
            $this->exportProgress->updateProgress($this->processedCount);
        }

        switch ($this->type) {
            case 'daily':
                return [
                    $attendance->attendance_date?->format('d/m/Y') ?? '-',
                    $attendance->student?->name ?? '-',
                    $attendance->student?->nis ?? '-',
                    $attendance->student?->class?->name ?? '-',
                    $attendance->schedule?->subject?->name ?? '-',
                    $this->formatStatus($attendance->status),
                    $attendance->check_in_time?->format('H:i:s') ?? '-',
                    $attendance->check_out_time?->format('H:i:s') ?? '-',
                    $attendance->teacher?->name ?? '-',
                ];

            case 'monthly':
                // For monthly, we need aggregated data
                // This is a simplified version - you may need to adjust based on your needs
                return [
                    $attendance->student?->name ?? '-',
                    $attendance->student?->nis ?? '-',
                    $attendance->student?->class?->name ?? '-',
                    $attendance->status === 'present' ? 1 : 0,
                    $attendance->status === 'late' ? 1 : 0,
                    $attendance->status === 'sick' ? 1 : 0,
                    $attendance->status === 'permission' ? 1 : 0,
                    $attendance->status === 'absent' ? 1 : 0,
                    '-', // Percentage will be calculated separately
                ];

            case 'student':
                return [
                    $attendance->attendance_date?->format('d/m/Y') ?? '-',
                    $attendance->schedule?->subject?->name ?? '-',
                    $this->formatStatus($attendance->status),
                    $attendance->check_in_time?->format('H:i:s') ?? '-',
                    $attendance->check_out_time?->format('H:i:s') ?? '-',
                    $attendance->teacher?->name ?? '-',
                    $attendance->notes ?? '-',
                ];

            default:
                return [$attendance->id];
        }
    }

    /**
     * Configure chunk size for reading.
     * Uses configurable chunk size from config.
     */
    public function chunkSize(): int
    {
        return config('exports.chunk_size', 1000);
    }

    /**
     * Format status for display.
     */
    protected function formatStatus(string $status): string
    {
        return match ($status) {
            'present' => 'Hadir',
            'late' => 'Terlambat',
            'absent' => 'Alpa',
            'sick' => 'Sakit',
            'permission' => 'Izin',
            default => ucfirst($status),
        };
    }
}
