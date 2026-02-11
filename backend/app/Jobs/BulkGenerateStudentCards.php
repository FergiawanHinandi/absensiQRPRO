<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\StudentCardService;
use Illuminate\Support\Facades\Log;

/**
 * Bulk Generate Student Cards Job
 *
 * Generates student ID cards in bulk for a school.
 * Extends TenantAwareJob to ensure tenant context is maintained.
 *
 * USAGE:
 * BulkGenerateStudentCards::dispatch($studentIds, $adminId, $schoolId, $filters, $forceRegenerate);
 *
 * @version 2.0.0 - Updated to extend TenantAwareJob for tenant safety
 */
class BulkGenerateStudentCards extends TenantAwareJob
{
    protected $studentIds;

    protected $adminId;

    protected $filters;

    protected $forceRegenerate;

    public function __construct(array $studentIds, int $adminId, int $schoolId, array $filters, bool $forceRegenerate)
    {
        parent::__construct($schoolId);
        $this->studentIds = $studentIds;
        $this->adminId = $adminId;
        $this->filters = $filters;
        $this->forceRegenerate = $forceRegenerate;
    }

    public function handle(StudentCardService $service)
    {
        // ✅ Ensure tenant context
        $admin = User::where('school_id', $this->schoolId)->findOrFail($this->adminId);
        $students = User::where('school_id', $this->schoolId)
            ->whereIn('id', $this->studentIds)
            ->get();
        
        $jobId = $this->job->getJobId();
        $summary = $service->bulkGenerateCards(
            $students, 
            $admin, 
            $this->forceRegenerate, 
            $this->filters, 
            true,
            function ($current, $total) use ($jobId) {
                if ($jobId) {
                    \Illuminate\Support\Facades\Cache::put('job_progress_' . $jobId, round(($current / $total) * 100), 3600);
                }
            }
        );
        // Notify admin
        $message = "Student Card generation completed. Processed: {$summary['total_students_processed']}, Generated: {$summary['cards_generated']}, Skipped: {$summary['cards_skipped']}.";
        $data = ['summary' => $summary];
        if (isset($summary['zip_path'])) {
             $data['download_url'] = route('student-cards.download', ['path' => $summary['zip_path']]);
        }
        
        $admin->notify(new \App\Notifications\JobCompleted('Student Card Generation', $message, $data));

        Log::channel('audit')->info('bulk_student_card_generation_completed', [
            'admin_id' => $this->adminId,
            'school_id' => $this->schoolId,
            'filters_used' => $this->filters,
            'summary' => $summary,
            'timestamp' => now(),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('BulkGenerateStudentCards job failed', [
            'job' => self::class,
            'admin_id' => $this->adminId,
            'school_id' => $this->schoolId,
            'student_count' => count($this->studentIds),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
