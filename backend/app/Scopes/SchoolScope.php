<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * School Scope
 * 
 * Multi-Tenant Security: Automatically filters all queries by school_id
 * 
 * FEATURES:
 * - Auto-filters queries by authenticated user's school_id
 * - Super admin bypass support
 * - CLI/Queue context handling
 * - Comprehensive audit logging
 * - Security violation detection
 * 
 * @author Multi-Tenant Security Architect
 * @version 2.0.0 (Enhanced)
 */
class SchoolScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // ============================================================
        // STEP 1: SKIP IN CLI/QUEUE CONTEXT (unless testing)
        // ============================================================
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            Log::channel('audit')->debug('school_scope_skipped_cli', [
                'model' => get_class($model),
                'context' => 'cli',
            ]);
            return;
        }

        // ============================================================
        // STEP 2: CHECK AUTHENTICATION
        // ============================================================
        if (!Auth::check()) {
            Log::channel('audit')->debug('school_scope_skipped_no_auth', [
                'model' => get_class($model),
            ]);
            return;
        }

        $user = Auth::user();

        // ============================================================
        // STEP 3: SUPER ADMIN BYPASS
        // ============================================================
        if ($user->role_type === 'super_admin') {
            Log::channel('audit')->info('school_scope_bypassed_superadmin', [
                'user_id' => $user->id,
                'model' => get_class($model),
            ]);
            return;
        }

        // ============================================================
        // STEP 4: VALIDATE USER HAS SCHOOL_ID
        // ============================================================
        if (!$user->school_id) {
            Log::channel('security')->warning('school_scope_user_no_school', [
                'user_id' => $user->id,
                'user_role' => $user->role_type,
                'model' => get_class($model),
            ]);
            return;
        }

        // ============================================================
        // STEP 5: APPLY SCHOOL_ID FILTER
        // ============================================================
        $tableName = $model->getTable();
        $builder->where("{$tableName}.school_id", $user->school_id);

        Log::channel('audit')->debug('school_scope_applied', [
            'user_id' => $user->id,
            'school_id' => $user->school_id,
            'user_role' => $user->role_type,
            'model' => get_class($model),
            'table' => $tableName,
        ]);
    }
}
