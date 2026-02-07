<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\StudentCardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkGenerateStudentCards implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $studentIds;

    protected $adminId;

    protected $filters;

    protected $forceRegenerate;

    public function __construct(array $studentIds, int $adminId, array $filters, bool $forceRegenerate)
    {
        $this->studentIds = $studentIds;
        $this->adminId = $adminId;
        $this->filters = $filters;
        $this->forceRegenerate = $forceRegenerate;
    }

    public function handle(StudentCardService $service)
    {
        $admin = User::findOrFail($this->adminId);
        $students = User::whereIn('id', $this->studentIds)->get();
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
            'student_count' => count($this->studentIds),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
