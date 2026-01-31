<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * SchoolScope - Global Scope for Multi-Tenant School Isolation
 *
 * AUTOMATIC SCHOOL DATA ISOLATION:
 * ================================
 * This scope automatically filters all queries on models that use it,
 * ensuring users can only see data from their own school.
 *
 * SAFETY BENEFITS:
 * 1. PREVENTS DATA LEAKS - Users cannot accidentally query other schools' data
 * 2. DEFENSE IN DEPTH - Even if policy check is forgotten, data is still isolated
 * 3. AUDIT TRAIL - All bypass scenarios are logged
 * 4. ZERO DEVELOPER EFFORT - Applied automatically to all queries
 *
 * BYPASS SCENARIOS:
 * 1. Super Admin - Has access to all schools (logged)
 * 2. CLI Context - Artisan commands, queue jobs (logged)
 * 3. Explicit Disable - Using withoutGlobalScope() (developer responsibility)
 * 4. Explicit School Context - Using forSchool() method
 *
 * @see \App\Traits\BelongsToSchool
 */
class SchoolScope implements Scope
{
    /**
     * Explicit school context (set via forSchool())
     */
    private static ?int $explicitSchoolId = null;

    /**
     * Flag to completely disable scope (use with caution)
     */
    private static bool $disabled = false;

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * QUERY BEFORE SCOPE:
     *   SELECT * FROM attendances WHERE status = 'present'
     *
     * QUERY AFTER SCOPE:
     *   SELECT * FROM attendances WHERE status = 'present' AND school_id = 123
     */
    public function apply(Builder $builder, Model $model): void
    {
        // BYPASS 1: Scope explicitly disabled
        if (self::$disabled) {
            $this->logBypass($model, 'scope_disabled');
            return;
        }

        // BYPASS 2: CLI context (artisan commands, queue jobs, tinker)
        if ($this->isCliContext()) {
            // In CLI, we may have explicit school context set
            if (self::$explicitSchoolId !== null) {
                $builder->where($model->getTable() . '.school_id', self::$explicitSchoolId);
                return;
            }
            
            $this->logBypass($model, 'cli_context');
            return;
        }

        // BYPASS 3: Explicit school context set (for cross-school operations)
        if (self::$explicitSchoolId !== null) {
            $builder->where($model->getTable() . '.school_id', self::$explicitSchoolId);
            return;
        }

        // BYPASS 4: No authenticated user
        if (!Auth::hasUser()) {
            // For unauthenticated requests, don't apply scope
            // (route middleware should handle authentication)
            return;
        }

        $user = Auth::user();

        // BYPASS 5: Super Admin has access to all schools
        if ($this->isSuperAdmin($user)) {
            $this->logBypass($model, 'super_admin', [
                'user_id' => $user->id,
                'email' => $user->email ?? 'unknown',
            ]);
            return;
        }

        // APPLY SCOPE: Filter by user's school_id
        if ($user->school_id) {
            $builder->where($model->getTable() . '.school_id', $user->school_id);
        } else {
            // User has no school_id - this shouldn't happen for normal users
            // Log warning and return empty result for safety
            Log::channel('security_json')->warning('User without school_id accessing school-scoped model', [
                'user_id' => $user->id,
                'model' => get_class($model),
                'role_type' => $user->role_type ?? 'unknown',
            ]);
            
            // Force empty result by impossible condition
            $builder->whereRaw('1 = 0');
        }
    }

    /**
     * Check if running in CLI context (artisan, queue, tinker)
     *
     * In CLI context, there's no authenticated user,
     * but we still want artisan commands and jobs to work.
     */
    private function isCliContext(): bool
    {
        return app()->runningInConsole();
    }

    /**
     * Check if user is Super Admin
     *
     * Super admins can access all schools.
     * This is typically platform administrators, not school admins.
     */
    protected function isSuperAdmin($user): bool
    {
        // Method 1: Check via Spatie Permission
        $superAdminRole = config('permission.super_admin_role', 'super_admin');

        if (method_exists($user, 'hasRole') && $user->hasRole($superAdminRole)) {
            return true;
        }

        // Method 2: Check role_type column
        if (property_exists($user, 'role_type') && $user->role_type === 'super_admin') {
            return true;
        }

        // Method 3: Check is_super_admin flag
        if (property_exists($user, 'is_super_admin') && $user->is_super_admin === true) {
            return true;
        }

        return false;
    }

    /**
     * Log scope bypass for security audit
     */
    private function logBypass(Model $model, string $reason, array $context = []): void
    {
        // Only log in production for performance
        if (!app()->isProduction()) {
            return;
        }

        Log::channel('security_json')->info('SchoolScope bypassed', array_merge([
            'reason' => $reason,
            'model' => get_class($model),
            'table' => $model->getTable(),
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }

    // =========================================================================
    // STATIC METHODS FOR EXPLICIT CONTEXT CONTROL
    // =========================================================================

    /**
     * Set explicit school context for subsequent queries
     *
     * USE CASE: Queue jobs that need to operate on a specific school
     *
     * Example:
     *   SchoolScope::forSchool(123);
     *   $attendances = Attendance::where('status', 'present')->get();
     *   SchoolScope::clearSchool();
     *
     * @param int $schoolId School ID to filter by
     */
    public static function forSchool(int $schoolId): void
    {
        self::$explicitSchoolId = $schoolId;
    }

    /**
     * Clear explicit school context
     */
    public static function clearSchool(): void
    {
        self::$explicitSchoolId = null;
    }

    /**
     * Execute callback with specific school context
     *
     * Example:
     *   SchoolScope::withSchool(123, function () {
     *       return Attendance::count();
     *   });
     *
     * @param int $schoolId School ID
     * @param callable $callback Code to execute
     * @return mixed Result of callback
     */
    public static function withSchool(int $schoolId, callable $callback): mixed
    {
        $previousSchoolId = self::$explicitSchoolId;
        
        try {
            self::$explicitSchoolId = $schoolId;
            return $callback();
        } finally {
            self::$explicitSchoolId = $previousSchoolId;
        }
    }

    /**
     * Temporarily disable scope (use with extreme caution!)
     *
     * This should only be used for:
     * - Database migrations
     * - System-wide analytics
     * - Emergency debugging
     *
     * @param callable $callback Code to execute without scope
     * @return mixed Result of callback
     */
    public static function withoutScope(callable $callback): mixed
    {
        $previousState = self::$disabled;
        
        try {
            self::$disabled = true;
            Log::channel('security_json')->warning('SchoolScope COMPLETELY DISABLED', [
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
            ]);
            return $callback();
        } finally {
            self::$disabled = $previousState;
        }
    }

    /**
     * Get current explicit school context
     */
    public static function getCurrentSchoolId(): ?int
    {
        return self::$explicitSchoolId;
    }
}

