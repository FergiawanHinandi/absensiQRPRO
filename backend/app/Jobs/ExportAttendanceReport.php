<?php

namespace App\Jobs;

use App\Exports\AttendanceReportExport;
use App\Helpers\TimezoneHelper;
use App\Models\ExportProgress;
use App\Models\User;
use App\Notifications\ReportExportCompleted;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Export Attendance Report Job (Async)
 * 
 * BENEFITS:
 * - Non-blocking: User doesn't wait for export
 * - Scalable: Can handle large datasets
 * - Reliable: Automatic retry on failure
 * - Notifies user when complete
 * - Tenant-aware: Ensures school_id context is maintained
 * 
 * USAGE:
 * ExportAttendanceReport::dispatch($userId, $schoolId, 'monthly', $params);
 * 
 * @version 2.0.0 - Updated to extend TenantAwareJob for tenant safety
 */
class ExportAttendanceReport extends TenantAwareJob
{

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     * Uses configurable timeout from exports config.
     */
    public $timeout = 600; // Default 10 minutes

    /**
     * Job parameters
     */
    protected $userId;
    protected $reportType;
    protected $params;
    protected $exportProgressId;

    /**
     * Create a new job instance.
     *
     * @param int $userId User who requested the export
     * @param int $schoolId School ID for tenant context (REQUIRED for tenant safety)
     * @param string $reportType Type of report (daily, monthly, student, school)
     * @param array $params Report parameters (date, class_id, etc.)
     * @param int|null $exportProgressId Optional export progress tracking ID
     */
    public function __construct(int $userId, int $schoolId, string $reportType, array $params, ?int $exportProgressId = null)
    {
        parent::__construct($schoolId);
        
        $this->userId = $userId;
        $this->reportType = $reportType;
        $this->params = $params;
        $this->exportProgressId = $exportProgressId;
        
        // Set timeout from config
        $this->timeout = config('exports.timeout', 600);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $memoryStart = memory_get_usage(true);
        
        Log::info('Export job started', [
            'user_id' => $this->userId,
            'school_id' => $this->schoolId,
            'type' => $this->reportType,
            'params' => $this->params,
            'memory_start' => round($memoryStart / 1024 / 1024, 2) . 'MB',
        ]);

        $exportProgress = null;

        try {
            // Get user
            $user = User::findOrFail($this->userId);
            
            // ✅ TENANT SAFETY: Validate user belongs to this school
            $this->ensureTenantContext($user);

            // ✅ TENANT SAFETY: Force school_id filter in params
            $this->params['school_id'] = $this->schoolId;

            // Get or create export progress tracker
            if ($this->exportProgressId) {
                $exportProgress = ExportProgress::find($this->exportProgressId);
            }

            if (!$exportProgress && config('exports.enable_progress_tracking', true)) {
                $exportProgress = ExportProgress::create([
                    'user_id' => $this->userId,
                    'school_id' => $this->schoolId,
                    'export_type' => $this->reportType,
                    'status' => ExportProgress::STATUS_PENDING,
                ]);
            }

            // Count total records for progress tracking
            $totalRecords = $this->countRecords();
            
            if ($exportProgress) {
                $exportProgress->markAsProcessing($totalRecords);
            }

            Log::info('Export processing started', [
                'user_id' => $this->userId,
                'school_id' => $this->schoolId,
                'total_records' => $totalRecords,
                'chunk_size' => config('exports.chunk_size', 1000),
            ]);

            // Generate filename
            $filename = $this->generateFilename();
            $filePath = "exports/attendance/{$filename}";

            // Create export instance with progress tracking
            $export = new AttendanceReportExport($this->reportType, $this->params, $exportProgress);

            // Store to disk using chunked processing
            Excel::store($export, $filePath, 'public');

            // Get file URL
            $fileUrl = Storage::disk('public')->url($filePath);

            $executionTime = round(microtime(true) - $startTime, 2);
            $memoryPeak = memory_get_peak_usage(true);
            $memoryUsed = round($memoryPeak / 1024 / 1024, 2);

            Log::info('Export job completed', [
                'user_id' => $this->userId,
                'school_id' => $this->schoolId,
                'type' => $this->reportType,
                'filename' => $filename,
                'total_records' => $totalRecords,
                'execution_time' => $executionTime . 's',
                'memory_peak' => $memoryUsed . 'MB',
            ]);

            // Mark as completed
            if ($exportProgress) {
                $exportProgress->markAsCompleted($filename, $filePath);
            }

            // Notify user
            $user->notify(new ReportExportCompleted([
                'type' => $this->reportType,
                'filename' => $filename,
                'download_url' => $fileUrl,
                'execution_time' => $executionTime,
                'total_records' => $totalRecords,
                'memory_used' => $memoryUsed,
            ]));

            // Audit log
            \App\Models\AuditLog::create([
                'user_id' => $this->userId,
                'school_id' => $this->schoolId,
                'module' => 'report',
                'action' => 'export_completed',
                'severity' => \App\Models\AuditLog::SEVERITY_INFO,
                'description' => "Export laporan absensi selesai: {$this->reportType}",
                'ip_address' => request()->ip() ?? '0.0.0.0',
                'user_agent' => request()->userAgent() ?? 'Queue Worker',
                'metadata' => json_encode([
                    'filename' => $filename,
                    'execution_time' => $executionTime,
                    'total_records' => $totalRecords,
                    'memory_used' => $memoryUsed,
                ]),
            ]);

        } catch (\Exception $e) {
            Log::error('Export job failed', [
                'user_id' => $this->userId,
                'school_id' => $this->schoolId,
                'type' => $this->reportType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark as failed
            if ($exportProgress) {
                $exportProgress->markAsFailed($e->getMessage());
            }

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Export job failed permanently', [
            'user_id' => $this->userId,
            'school_id' => $this->schoolId,
            'type' => $this->reportType,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        // Notify user of failure
        try {
            $user = User::find($this->userId);
            if ($user) {
                // You can create a ReportExportFailed notification
                // $user->notify(new ReportExportFailed(...));
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify user of export failure', [
                'user_id' => $this->userId,
                'school_id' => $this->schoolId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate unique filename for export
     */
    protected function generateFilename(): string
    {
        $timestamp = TimezoneHelper::now()->format('Y-m-d_His');
        $type = $this->reportType;
        $userId = $this->userId;

        // Add specific identifier based on type
        $identifier = '';
        if (isset($this->params['class_id'])) {
            $identifier = "_class{$this->params['class_id']}";
        } elseif (isset($this->params['student_id'])) {
            $identifier = "_student{$this->params['student_id']}";
        }

        return "attendance_{$type}{$identifier}_{$timestamp}_user{$userId}.xlsx";
    }

    /**
     * Count total records for progress tracking.
     * Uses the same query logic as the export.
     */
    protected function countRecords(): int
    {
        $query = \App\Models\Attendance::query()
            ->where('school_id', $this->schoolId);

        // Apply same filters as export
        switch ($this->reportType) {
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

        return $query->count();
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return array_merge(parent::tags(), [
            'export',
            'attendance',
            "user:{$this->userId}",
            "type:{$this->reportType}",
        ]);
    }
}
