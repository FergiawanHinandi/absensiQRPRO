<?php

namespace App\Traits;

use App\Models\School;
use App\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * BelongsToSchool Trait
 * 
 * Multi-Tenant Security: Automatically scopes all queries by school_id
 * 
 * FEATURES:
 * - Global scope filters all queries by authenticated user's school_id
 * - Auto-fills school_id on model creation
 * - Prevents school_id modification (security)
 * - Super admin bypass support
 * - Helper scopes for cross-school operations
 * 
 * USAGE:
 * class Schedule extends Model {
 *     use BelongsToSchool;
 * }
 * 
 * BYPASS (for super admin):
 * Model::withoutGlobalScope('school')->get();
 * Model::allSchools()->get();
 * Model::forSchool($schoolId)->get();
 * 
 * @author Multi-Tenant Security Architect
 * @version 2.0.0 (Enhanced)
 */
trait BelongsToSchool
{
    /**
     * Boot the trait.
     */
    protected static function bootBelongsToSchool(): void
    {
        // ============================================================
        // GLOBAL SCOPE: Auto-filter by school_id
        // ============================================================
        static::addGlobalScope(new SchoolScope);

        // ============================================================
        // AUTO-FILL: Set school_id on model creation
        // ============================================================
        static::creating(function ($model) {
            // Skip in CLI/Queue context (unless testing)
            if (app()->runningInConsole() && !app()->runningUnitTests()) {
                return;
            }

            // Skip if school_id already set
            if ($model->school_id) {
                return;
            }

            // Auto-fill school_id if not set and user is logged in
            if (Auth::check()) {
                $user = Auth::user();
                
                if ($user->school_id) {
                    $model->school_id = $user->school_id;
                    
                    Log::channel('audit')->debug('school_autofill_applied', [
                        'user_id' => $user->id,
                        'school_id' => $user->school_id,
                        'model' => get_class($model),
                    ]);
                } else {
                    Log::channel('security')->error('school_autofill_user_no_school', [
                        'user_id' => $user->id,
                        'user_role' => $user->role_type,
                        'model' => get_class($model),
                    ]);

                    throw new \Exception('Cannot create model: User is not associated with any school.');
                }
            }
        });

        // ============================================================
        // VALIDATION: Prevent school_id modification
        // ============================================================
        static::updating(function ($model) {
            // Check if school_id is being changed
            if ($model->isDirty('school_id')) {
                $originalSchoolId = $model->getOriginal('school_id');
                $newSchoolId = $model->school_id;

                // Allow if user is super admin
                if (Auth::check() && Auth::user()->role_type === 'super_admin') {
                    Log::channel('audit')->warning('school_id_changed_by_superadmin', [
                        'user_id' => Auth::id(),
                        'model' => get_class($model),
                        'model_id' => $model->id,
                        'original_school_id' => $originalSchoolId,
                        'new_school_id' => $newSchoolId,
                    ]);
                    return;
                }

                // Block school_id modification for regular users
                Log::channel('security')->error('school_id_modification_blocked', [
                    'user_id' => Auth::id(),
                    'model' => get_class($model),
                    'model_id' => $model->id,
                    'original_school_id' => $originalSchoolId,
                    'attempted_school_id' => $newSchoolId,
                ]);

                throw new \Exception('Cannot modify school_id: Security violation detected.');
            }
        });
    }

    /**
     * Relationship to School
     */
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Scope to bypass school filter (for super admin operations)
     * 
     * Usage: Model::allSchools()->get();
     */
    public function scopeAllSchools(Builder $query): Builder
    {
        return $query->withoutGlobalScope('school');
    }

    /**
     * Scope to filter by specific school (for super admin operations)
     * 
     * Usage: Model::forSchool($schoolId)->get();
     */
    public function scopeForSchool(Builder $query, int $schoolId): Builder
    {
        return $query->withoutGlobalScope('school')
            ->where('school_id', $schoolId);
    }
}
