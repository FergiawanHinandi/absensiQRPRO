<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * IDORProtection Trait
 * 
 * Provides helper methods for controllers to prevent Insecure Direct Object Reference (IDOR) attacks.
 * 
 * SECURITY PRINCIPLE:
 * NEVER trust client-provided IDs. Always validate that the requested resource
 * belongs to the authenticated user's school (tenant).
 * 
 * USAGE:
 * 1. Add trait to controller: use IDORProtection;
 * 2. Use findOrFailWithSchool() to fetch models with school validation
 * 3. Use validateSchoolOwnership() to validate existing models
 * 4. Use findOrFailWithOwnership() for user-owned resources
 * 
 * @version 1.0.0
 */
trait IDORProtection
{
    /**
     * Find a model by ID with school (tenant) validation
     * 
     * SECURITY:
     * - Adds WHERE school_id = user's school_id to the query
     * - Logs IDOR attempts to security channel
     * - Throws NotFoundHttpException if not found or wrong school
     * 
     * @param string $modelClass Fully qualified model class name
     * @param int $id Resource ID
     * @param string $errorMessage Error message if not found
     * @param array $with Relations to eager load
     * @return Model
     * @throws NotFoundHttpException
     */
    protected function findOrFailWithSchool(
        string $modelClass,
        int $id,
        string $errorMessage = 'Resource tidak ditemukan.',
        array $with = []
    ): Model {
        $user = auth()->user();
        $schoolId = $user->school_id;
        
        $query = $modelClass::where('id', $id)
            ->where('school_id', $schoolId);
        
        if (!empty($with)) {
            $query->with($with);
        }
        
        $model = $query->first();
        
        if (!$model) {
            // Check if resource exists in another school (potential IDOR attempt)
            $existsInOtherSchool = $modelClass::where('id', $id)
                ->where('school_id', '!=', $schoolId)
                ->exists();
            
            if ($existsInOtherSchool) {
                $this->logIDORAttempt($user, $modelClass, $id, 'findOrFailWithSchool');
            }
            
            throw new NotFoundHttpException($errorMessage);
        }
        
        return $model;
    }

    /**
     * Validate that an existing model belongs to user's school
     * 
     * SECURITY:
     * - Compares model's school_id with user's school_id
     * - Logs IDOR attempts
     * - Throws NotFoundHttpException if mismatch
     * 
     * @param Model $model Model instance to validate
     * @param string $errorMessage Error message if validation fails
     * @return Model The validated model
     * @throws NotFoundHttpException
     */
    protected function validateSchoolOwnership(
        Model $model,
        string $errorMessage = 'Resource tidak ditemukan atau bukan milik sekolah Anda.'
    ): Model {
        $user = auth()->user();
        
        if ($model->school_id !== $user->school_id) {
            $this->logIDORAttempt($user, get_class($model), $model->id, 'validateSchoolOwnership');
            throw new NotFoundHttpException($errorMessage);
        }
        
        return $model;
    }

    /**
     * Find a model by ID with school validation using model class as string
     * 
     * @param string $modelClass Model class name
     * @param int $id Resource ID
     * @param string $errorMessage Error message
     * @return Model
     * @throws NotFoundHttpException
     */
    protected function validateSchoolOwnershipById(
        string $modelClass,
        int $id,
        string $errorMessage = 'Resource tidak ditemukan.'
    ): Model {
        return $this->findOrFailWithSchool($modelClass, $id, $errorMessage);
    }

    /**
     * Find a model that must be owned by the current user
     * 
     * SECURITY:
     * - Validates both school_id AND owner_id (e.g., teacher_id, student_id)
     * - For resources that belong to a specific user within a school
     * 
     * @param string $modelClass Model class name
     * @param int $id Resource ID
     * @param string $ownerColumn Column name that references the owner (e.g., 'teacher_id')
     * @param string $errorMessage Error message
     * @param array $with Relations to eager load
     * @return Model
     * @throws NotFoundHttpException
     */
    protected function findOrFailWithOwnership(
        string $modelClass,
        int $id,
        string $ownerColumn,
        string $errorMessage = 'Resource tidak ditemukan atau bukan milik Anda.',
        array $with = []
    ): Model {
        $user = auth()->user();
        
        $query = $modelClass::where('id', $id)
            ->where('school_id', $user->school_id)
            ->where($ownerColumn, $user->id);
        
        if (!empty($with)) {
            $query->with($with);
        }
        
        $model = $query->first();
        
        if (!$model) {
            // Check if it exists but belongs to someone else
            $existsButNotOwner = $modelClass::where('id', $id)
                ->where('school_id', $user->school_id)
                ->where($ownerColumn, '!=', $user->id)
                ->exists();
            
            if ($existsButNotOwner) {
                $this->logIDORAttempt($user, $modelClass, $id, 'findOrFailWithOwnership', [
                    'owner_column' => $ownerColumn,
                    'expected_owner_id' => $user->id,
                ]);
            }
            
            throw new NotFoundHttpException($errorMessage);
        }
        
        return $model;
    }

    /**
     * Validate multiple IDs belong to user's school
     * 
     * SECURITY:
     * - Bulk validation for batch operations
     * - Returns only IDs that exist within user's school
     * - Logs if any invalid IDs were attempted
     * 
     * @param string $modelClass Model class name
     * @param array $ids Array of resource IDs
     * @return array Array of valid IDs
     */
    protected function validateMultipleSchoolOwnership(string $modelClass, array $ids): array
    {
        $user = auth()->user();
        
        $validIds = $modelClass::whereIn('id', $ids)
            ->where('school_id', $user->school_id)
            ->pluck('id')
            ->toArray();
        
        // Check for invalid IDs
        $invalidIds = array_diff($ids, $validIds);
        
        if (!empty($invalidIds)) {
            $this->logIDORAttempt($user, $modelClass, 0, 'validateMultipleSchoolOwnership', [
                'requested_ids' => $ids,
                'invalid_ids' => $invalidIds,
                'valid_ids' => $validIds,
            ]);
        }
        
        return $validIds;
    }

    /**
     * Assert that a relationship is within the same school
     * 
     * SECURITY:
     * - Validates foreign key references within tenant boundary
     * - Use before creating records with foreign keys
     * 
     * @param string $relatedModelClass Related model class
     * @param int|null $relatedId Foreign key value
     * @param string $errorMessage Error message
     * @return bool True if valid or null (nullable FK)
     * @throws NotFoundHttpException
     */
    protected function assertRelationInSchool(
        string $relatedModelClass,
        ?int $relatedId,
        string $errorMessage = 'Relasi tidak valid atau bukan milik sekolah Anda.'
    ): bool {
        if ($relatedId === null) {
            return true; // Nullable FK is OK
        }
        
        $user = auth()->user();
        
        $exists = $relatedModelClass::where('id', $relatedId)
            ->where('school_id', $user->school_id)
            ->exists();
        
        if (!$exists) {
            $this->logIDORAttempt($user, $relatedModelClass, $relatedId, 'assertRelationInSchool');
            throw new NotFoundHttpException($errorMessage);
        }
        
        return true;
    }

    /**
     * Log an IDOR attempt to security channel
     * 
     * @param mixed $user Authenticated user
     * @param string $modelClass Target model class
     * @param int $attemptedId Attempted resource ID
     * @param string $method Protection method that caught the attempt
     * @param array $extra Extra context data
     */
    private function logIDORAttempt(
        $user,
        string $modelClass,
        int $attemptedId,
        string $method,
        array $extra = []
    ): void {
        Log::channel('security')->warning('idor_attempt_blocked', array_merge([
            'user_id' => $user->id,
            'user_school_id' => $user->school_id,
            'user_role' => $user->role_type,
            'target_model' => $modelClass,
            'attempted_id' => $attemptedId,
            'protection_method' => $method,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
            'timestamp' => now()->toIso8601String(),
        ], $extra));
    }
}
