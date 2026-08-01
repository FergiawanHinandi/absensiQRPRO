<?php

namespace App\Jobs;

use App\Models\Attendance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Background Job for Zero-Downtime Data Migration
 * 
 * This job backfills new columns in chunks to avoid locking the table.
 * It's designed to run during Phase 2 of the Expand-Migrate-Contract pattern.
 * 
 * TENANT SAFETY:
 * - Extends TenantAwareJob to ensure school_id context
 * - All queries are scoped to the school_id
 * - Should be dispatched separately per school for safety
 * 
 * Usage:
 *   BackfillAttendanceDataJob::dispatch($schoolId, 'state', 'mapStatusToState');
 * 
 * Monitoring:
 *   SELECT COUNT(*) as total, COUNT(state) as migrated 
 *   FROM attendances WHERE school_id = ?;
 * 
 * @version 2.0.0 - Updated to extend TenantAwareJob for tenant safety
 */
class BackfillAttendanceDataJob extends TenantAwareJob
{
    public $timeout = 3600; // 1 hour max
    public $tries = 3;
    public $backoff = 300; // 5 minutes between retries

    protected string $targetColumn;
    protected string $mappingMethod;
    protected int $chunkSize;

    /**
     * Create a new job instance.
     *
     * @param int $schoolId The school ID for tenant context (REQUIRED)
     * @param string $targetColumn The column to backfill (e.g., 'state')
     * @param string $mappingMethod The method to map old value to new (e.g., 'mapStatusToState')
     * @param int $chunkSize Number of records to process per chunk
     */
    public function __construct(
        int $schoolId,
        string $targetColumn = 'state',
        string $mappingMethod = 'mapStatusToState',
        int $chunkSize = 1000
    ) {
        parent::__construct($schoolId);
        $this->targetColumn = $targetColumn;
        $this->mappingMethod = $mappingMethod;
        $this->chunkSize = $chunkSize;
        $this->onQueue('migrations'); // Dedicated queue for migrations
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = now();
        $processedCount = 0;
        $errorCount = 0;

        Log::info("Starting backfill for column: {$this->targetColumn}", [
            'school_id' => $this->schoolId,
            'chunk_size' => $this->chunkSize,
            'mapping_method' => $this->mappingMethod,
        ]);

        // ✅ TENANT SAFETY: Get total count with explicit school_id filter
        $totalCount = Attendance::where('school_id', $this->schoolId)
            ->whereNull($this->targetColumn)
            ->count();

        if ($totalCount === 0) {
            Log::info("No records to backfill for {$this->targetColumn}", [
                'school_id' => $this->schoolId,
            ]);
            return;
        }

        Log::info("Total records to migrate: {$totalCount}", [
            'school_id' => $this->schoolId,
        ]);

        // ✅ TENANT SAFETY: Process in chunks with explicit school_id filter
        Attendance::where('school_id', $this->schoolId)
            ->whereNull($this->targetColumn)
            ->chunkById($this->chunkSize, function ($attendances) use (&$processedCount, &$errorCount, $totalCount) {
                DB::transaction(function () use ($attendances, &$processedCount, &$errorCount) {
                    foreach ($attendances as $attendance) {
                        try {
                            // ✅ TENANT SAFETY: Validate attendance belongs to this school
                            if ($attendance->school_id !== $this->schoolId) {
                                Log::error("Tenant context violation in BackfillAttendanceDataJob", [
                                    'job_school_id' => $this->schoolId,
                                    'attendance_school_id' => $attendance->school_id,
                                    'attendance_id' => $attendance->id,
                                ]);
                                $errorCount++;
                                continue;
                            }

                            // Call the mapping method dynamically
                            $newValue = $this->{$this->mappingMethod}($attendance);
                            
                            // Update without triggering observers (saveQuietly)
                            $attendance->{$this->targetColumn} = $newValue;
                            $attendance->saveQuietly();
                            
                            $processedCount++;
                        } catch (\Exception $e) {
                            $errorCount++;
                            Log::error("Failed to backfill attendance #{$attendance->id}", [
                                'school_id' => $this->schoolId,
                                'error' => $e->getMessage(),
                                'attendance_id' => $attendance->id,
                            ]);
                        }
                    }
                });

                // Log progress every chunk
                $progress = round(($processedCount / $totalCount) * 100, 2);
                Log::info("Backfill progress: {$processedCount}/{$totalCount} ({$progress}%)", [
                    'school_id' => $this->schoolId,
                    'errors' => $errorCount,
                ]);

                // Throttle to avoid overloading the database
                // Sleep for 100ms between chunks (adjustable based on load)
                usleep(100000);
            });

        $duration = now()->diffInSeconds($startTime);

        Log::info("Backfill completed for {$this->targetColumn}", [
            'school_id' => $this->schoolId,
            'processed' => $processedCount,
            'errors' => $errorCount,
            'duration_seconds' => $duration,
            'records_per_second' => $duration > 0 ? round($processedCount / $duration, 2) : 0,
        ]);
    }

    /**
     * Map legacy status to new state
     */
    protected function mapStatusToState(Attendance $attendance): string
    {
        // Check if already has check_out_time
        if ($attendance->check_out_time) {
            return 'checked_out';
        }

        // Check if has check_in_time
        if ($attendance->check_in_time) {
            return 'checked_in';
        }

        // Map based on status
        return match($attendance->status) {
            'present', 'late' => 'checked_in',
            'sick', 'permit', 'excused' => 'approved',
            'absent' => 'init',
            default => 'init',
        };
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical("Backfill job failed critically", [
            'school_id' => $this->schoolId,
            'column' => $this->targetColumn,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Optionally send alert to Slack/Email
        // app(SecurityAlertService::class)->createAlert(...)
    }
}
