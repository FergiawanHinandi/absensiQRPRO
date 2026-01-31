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
        $summary = $service->bulkGenerateCards($students, $admin, $this->forceRegenerate, $this->filters, true);
        // TODO: Notify admin (email/notification) when done
        Log::channel('audit')->info('bulk_student_card_generation_completed', [
            'admin_id' => $this->adminId,
            'filters_used' => $this->filters,
            'summary' => $summary,
            'timestamp' => now(),
        ]);
    }
}
