<?php

namespace App\Jobs;

use App\Exports\AttendanceExport;
use App\Models\Attendance;
use App\Models\ReportExport;
use App\Models\School;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Job to generate report exports asynchronously
 *
 * This job handles heavy report generation (Excel/PDF) in the background
 * to prevent API timeout and improve user experience.
 */
class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ReportExport $reportExport
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $export = $this->reportExport;

        try {
            Log::info('Starting report export', [
                'export_id' => $export->id,
                'type' => $export->type,
                'format' => $export->format,
            ]);

            // Mark as processing
            $export->markAsProcessing();

            // Generate the report based on type and format
            $result = match ($export->type) {
                ReportExport::TYPE_ATTENDANCE => $this->generateAttendanceReport($export),
                ReportExport::TYPE_SUMMARY => $this->generateSummaryReport($export),
                default => throw new \Exception("Unknown report type: {$export->type}"),
            };

            // Mark as completed
            $export->markAsCompleted(
                $result['path'],
                $result['filename'],
                $result['size']
            );

            Log::info('Report export completed', [
                'export_id' => $export->id,
                'file_path' => $result['path'],
                'file_size' => $result['size'],
            ]);

        } catch (\Exception $e) {
            Log::error('Report export failed', [
                'export_id' => $export->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $export->markAsFailed($e->getMessage());

            // Re-throw to trigger job retry
            throw $e;
        }
    }

    /**
     * Generate attendance report
     */
    private function generateAttendanceReport(ReportExport $export): array
    {
        $params = $export->parameters;
        $schoolId = $export->school_id;

        $export->updateProgress(10);

        if ($export->format === ReportExport::FORMAT_EXCEL) {
            return $this->generateExcelReport($export, $params, $schoolId);
        } else {
            return $this->generatePdfReport($export, $params, $schoolId);
        }
    }

    /**
     * Generate Excel report
     */
    private function generateExcelReport(ReportExport $export, array $params, int $schoolId): array
    {
        $export->updateProgress(20);

        $excelExport = new AttendanceExport(
            $schoolId,
            $params['start_date'],
            $params['end_date'],
            $params['class_id'] ?? null
        );

        $export->updateProgress(50);

        // Generate unique filename
        $filename = sprintf(
            'attendance_report_%s_%s.xlsx',
            $schoolId,
            now()->format('Y-m-d_His')
        );

        $path = "exports/{$schoolId}/{$filename}";

        // Store to configured disk (local or S3)
        Excel::store($excelExport, $path, config('filesystems.default'));

        $export->updateProgress(90);

        // Get file size
        $size = Storage::size($path);

        return [
            'path' => $path,
            'filename' => $filename,
            'size' => $size,
        ];
    }

    /**
     * Generate PDF report
     */
    private function generatePdfReport(ReportExport $export, array $params, int $schoolId): array
    {
        $export->updateProgress(20);

        // Query attendance data
        $query = Attendance::with(['student', 'schedule.class', 'schedule.subject'])
            ->where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$params['start_date'], $params['end_date']]);

        if (! empty($params['class_id'])) {
            $query->whereHas('schedule', function ($q) use ($params) {
                $q->where('class_id', $params['class_id']);
            });
        }

        $attendances = $query->orderBy('attendance_date', 'asc')->get();

        $export->updateProgress(50);

        $school = School::find($schoolId);

        $data = [
            'attendances' => $attendances,
            'start_date' => $params['start_date'],
            'end_date' => $params['end_date'],
            'school' => $school,
            'generated_at' => now()->format('d-m-Y H:i:s'),
        ];

        $export->updateProgress(70);

        // Generate PDF
        $pdf = Pdf::loadView('reports.attendance', $data);

        // Generate unique filename
        $filename = sprintf(
            'attendance_report_%s_%s.pdf',
            $schoolId,
            now()->format('Y-m-d_His')
        );

        $path = "exports/{$schoolId}/{$filename}";

        $export->updateProgress(85);

        // Store to configured disk
        Storage::put($path, $pdf->output());

        $export->updateProgress(90);

        // Get file size
        $size = Storage::size($path);

        return [
            'path' => $path,
            'filename' => $filename,
            'size' => $size,
        ];
    }

    /**
     * Generate summary report
     */
    private function generateSummaryReport(ReportExport $export): array
    {
        // Placeholder for summary reports
        // Can be extended based on requirements
        throw new \Exception('Summary report type not yet implemented');
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Report export job failed permanently', [
            'export_id' => $this->reportExport->id,
            'error' => $exception->getMessage(),
        ]);

        $this->reportExport->markAsFailed(
            "Export gagal setelah {$this->tries} percobaan: ".$exception->getMessage()
        );
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            (new \Illuminate\Queue\Middleware\RateLimited('heavy_jobs')),
        ];
    }
}
