<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * TenantAwareJob Base Class
 * 
 * Ensures all queue jobs maintain proper tenant (school) context to prevent
 * cross-tenant data leaks and ensure data integrity in multi-tenant system.
 * 
 * FEATURES:
 * - Enforces school_id requirement for all tenant-aware jobs
 * - Validates school existence on job creation
 * - Provides helper methods for tenant context validation
 * - Ensures queries are properly scoped to tenant
 * 
 * USAGE:
 * ```php
 * class MyJob extends TenantAwareJob
 * {
 *     public function __construct(int $schoolId, $otherParams)
 *     {
 *         parent::__construct($schoolId);
 *         // ... initialize other params
 *     }
 * 
 *     public function handle(): void
 *     {
 *         $schoolId = $this->getSchoolId();
 *         // ... use schoolId in queries
 *     }
 * }
 * ```
 * 
 * SECURITY:
 * - All jobs extending this class MUST pass school_id
 * - Invalid school_id throws exception immediately
 * - Provides ensureTenantContext() for model validation
 * 
 * @version 1.0.0
 * @see backend/docs/QUEUE_JOBS_TENANT_AUDIT.md
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The school ID this job operates on
     */
    protected int $schoolId;

    /**
     * Create a new tenant-aware job instance.
     * 
     * @param int $schoolId The school ID this job operates on
     * @throws InvalidArgumentException If school_id is invalid or doesn't exist
     */
    public function __construct(int $schoolId)
    {
        // Validate school_id is positive
        if ($schoolId <= 0) {
            throw new InvalidArgumentException(
                "Invalid school_id: {$schoolId}. School ID must be a positive integer."
            );
        }

        // Validate school exists
        $schoolExists = DB::table('schools')
            ->where('id', $schoolId)
            ->exists();

        if (!$schoolExists) {
            throw new InvalidArgumentException(
                "School with ID {$schoolId} does not exist. Cannot create tenant-aware job."
            );
        }

        $this->schoolId = $schoolId;
    }

    /**
     * Get the school ID for this job
     * 
     * @return int The school ID
     */
    protected function getSchoolId(): int
    {
        return $this->schoolId;
    }

    /**
     * Ensure a model belongs to this job's school (tenant context validation)
     * 
     * This method validates that a given model belongs to the same school
     * as this job, preventing cross-tenant data access.
     * 
     * @param mixed $model The model to validate
     * @throws RuntimeException If model doesn't have school_id or belongs to different school
     * 
     * @example
     * ```php
     * $student = User::find($studentId);
     * $this->ensureTenantContext($student);
     * // Now safe to use $student
     * ```
     */
    protected function ensureTenantContext($model): void
    {
        // Check if model has school_id property
        if (!isset($model->school_id)) {
            throw new RuntimeException(
                get_class($model) . ' does not have school_id property. ' .
                'Cannot validate tenant context.'
            );
        }

        // Validate model belongs to this job's school
        if ($model->school_id !== $this->schoolId) {
            throw new RuntimeException(
                'Tenant context violation: ' . get_class($model) . 
                ' belongs to school ' . $model->school_id . 
                ' but job is for school ' . $this->schoolId . '. ' .
                'Cross-tenant access is not allowed.'
            );
        }
    }

    /**
     * Get tags for job monitoring and debugging
     * 
     * Override this method in child classes to add more specific tags
     * 
     * @return array
     */
    public function tags(): array
    {
        return [
            'tenant-aware',
            "school:{$this->schoolId}",
        ];
    }
}
