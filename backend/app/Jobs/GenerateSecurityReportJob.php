<?php

namespace App\Jobs;

use App\Models\SecurityReport;
use App\Models\User;
use App\Services\TeacherSecurityReportService;
use Illuminate\Support\Facades\Log;

/**
 * Job to auto-generate security investigation reports
 * when critical behavior is detected.
 * 
 * USAGE:
 * GenerateSecurityReportJob::dispatch($teacherId, $schoolId, '7d', 'critical_behavior_detected');
 * 
 * @version 2.0.0 - Updated to extend TenantAwareJob for tenant safety
 */
class GenerateSecurityReportJob extends TenantAwareJob
{
    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 300; // 5 minutes

    protected int $teacherId;

    protected string $range;

    protected string $triggerReason;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $teacherId,
        int $schoolId,
        string $range = '7d',
        string $triggerReason = 'critical_behavior_detected'
    ) {
        parent::__construct($schoolId);
        $this->teacherId = $teacherId;
        $this->range = $range;
        $this->triggerReason = $triggerReason;

        $this->onQueue('security');
    }

    /**
     * Execute the job.
     */
    public function handle(TeacherSecurityReportService $reportService): void
    {
        // ✅ Ensure tenant context
        $teacher = User::where('school_id', $this->schoolId)
            ->with('school')
            ->find($this->teacherId);

        if (! $teacher) {
            Log::channel('security')->warning('Auto-report generation skipped: teacher not found or wrong school', [
                'teacher_id' => $this->teacherId,
                'school_id' => $this->schoolId,
            ]);

            return;
        }

        // Validate teacher belongs to this school
        $this->ensureTenantContext($teacher);

        // Check if a recent report already exists (within last 24 hours)
        $recentReport = SecurityReport::where('teacher_id', $this->teacherId)
            ->where('school_id', $this->schoolId)
            ->where('generation_type', SecurityReport::GENERATION_AUTO)
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if ($recentReport) {
            Log::channel('security')->info('Auto-report generation skipped: recent report exists', [
                'teacher_id' => $this->teacherId,
                'teacher_name' => $teacher->name,
            ]);

            return;
        }

        // Find a super_admin or school_admin to attribute the report to
        $systemAdmin = $this->findSystemAdmin($this->schoolId);

        if (! $systemAdmin) {
            Log::channel('security')->error('Auto-report generation failed: no admin found', [
                'teacher_id' => $this->teacherId,
                'school_id' => $this->schoolId,
            ]);

            return;
        }

        try {
            $report = $reportService->generateReport(
                $this->teacherId,
                $this->range,
                $systemAdmin->id,
                SecurityReport::GENERATION_AUTO
            );

            Log::channel('security')->warning('Auto security report generated', [
                'report_id' => $report->id,
                'teacher_id' => $this->teacherId,
                'teacher_name' => $teacher->name,
                'school_id' => $this->schoolId,
                'risk_level' => $report->risk_level,
                'trigger_reason' => $this->triggerReason,
                'generated_by' => $systemAdmin->id,
            ]);

            // Dispatch notification job
            $this->notifyAdmins($report, $teacher);

        } catch (\Exception $e) {
            Log::channel('security')->error('Auto-report generation failed', [
                'teacher_id' => $this->teacherId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Find a suitable admin to attribute the auto-generated report to.
     */
    protected function findSystemAdmin(int $schoolId): ?User
    {
        // First, try to find a super_admin
        $superAdmin = User::where('role_type', 'super_admin')
            ->where('is_active', true)
            ->first();

        if ($superAdmin) {
            return $superAdmin;
        }

        // Fall back to school admin
        return User::where('school_id', $schoolId)
            ->whereIn('role_type', ['admin', 'school_admin'])
            ->where('is_active', true)
            ->first();
    }

    /**
     * Notify relevant admins about the auto-generated report.
     */
    protected function notifyAdmins(SecurityReport $report, User $teacher): void
    {
        // Find admins to notify - only for this school
        $admins = User::where(function ($query) {
            $query->where('role_type', 'super_admin')
                ->orWhere(function ($q) {
                    $q->whereIn('role_type', ['admin', 'school_admin'])
                        ->where('school_id', $this->schoolId);
                });
        })
            ->where('is_active', true)
            ->get();

        foreach ($admins as $admin) {
            // Create security alert for auto-generated report
            $alert = \App\Models\SecurityAlert::createAlert([
                'school_id' => $this->schoolId,
                'type' => 'auto_security_report',
                'severity' => $report->risk_level === 'high' ? 'high' : 'medium',
                'description' => "Auto-generated security report for {$teacher->name} - Risk Level: ".strtoupper($report->risk_level),
                'ip_address' => request()->ip(),
            ]);

            // Send notification using the alert object
            if ($alert->shouldNotify()) {
                SendSecurityAlertNotification::dispatch($alert)->onQueue('notifications');
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('security')->error('GenerateSecurityReportJob failed permanently', [
            'teacher_id' => $this->teacherId,
            'trigger_reason' => $this->triggerReason,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags for the job.
     */
    public function tags(): array
    {
        return [
            'security-report',
            'teacher:'.$this->teacherId,
            'trigger:'.$this->triggerReason,
        ];
    }
}
