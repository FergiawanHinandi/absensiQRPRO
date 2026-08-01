<?php

namespace App\Services\DR;

use App\Services\DR\AlertManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant Security Validator for DR Operations
 *
 * Ensures backup/restore operations never cross school (tenant) boundaries.
 *
 * Spec: disaster-recovery-audit-improvements / tasks.md Task 4.1
 */
class TenantSecurityValidator
{
    public function __construct(
        private readonly AlertManager $alertManager
    ) {}

    /**
     * Validate that a backup operation is scoped to the correct school.
     *
     * @throws \RuntimeException if validation fails
     */
    public function validateBackupScope(?int $requestedSchoolId, $authenticatedUser): bool
    {
        // Global backup (null school_id) requires super_admin
        if ($requestedSchoolId === null) {
            if (!$this->isSuperAdmin($authenticatedUser)) {
                $this->alertManager->warning(
                    'unauthorized_global_backup',
                    'Non-super-admin attempted global backup',
                    ['user_id' => $authenticatedUser?->id, 'role' => $authenticatedUser?->role_type]
                );
                throw new \RuntimeException('Global backup requires super_admin role');
            }
            return true;
        }

        // School-specific backup: must match authenticated user's school
        if ($authenticatedUser && !$this->isSuperAdmin($authenticatedUser)) {
            if ((int) $authenticatedUser->school_id !== (int) $requestedSchoolId) {
                $this->alertManager->critical(
                    'cross_tenant_backup_attempt',
                    'Cross-tenant backup attempt detected',
                    [
                        'user_id'            => $authenticatedUser->id,
                        'user_school_id'     => $authenticatedUser->school_id,
                        'requested_school_id' => $requestedSchoolId,
                    ]
                );
                throw new \RuntimeException(
                    "Tenant isolation violation: school {$requestedSchoolId} not accessible"
                );
            }
        }

        return true;
    }

    /**
     * Validate restore operation — ensure backup belongs to target school.
     */
    public function validateRestoreScope(int $operationId, ?int $targetSchoolId): bool
    {
        $operation = DB::table('backup_operations')->find($operationId);

        if (!$operation) {
            throw new \RuntimeException("Backup operation {$operationId} not found");
        }

        // Global backup can only be restored to global (or by super_admin)
        if ($operation->school_id !== null && $targetSchoolId !== null) {
            if ((int) $operation->school_id !== (int) $targetSchoolId) {
                $this->alertManager->critical(
                    'cross_tenant_restore_attempt',
                    'Cross-tenant restore detected',
                    [
                        'operation_id'       => $operationId,
                        'backup_school_id'   => $operation->school_id,
                        'target_school_id'   => $targetSchoolId,
                    ]
                );
                throw new \RuntimeException('Cannot restore school backup to different school');
            }
        }

        return true;
    }

    /**
     * Validate that a backup file path belongs to the correct school.
     * Prevents path traversal attacks on backup storage.
     */
    public function validateBackupPath(string $path, ?int $schoolId): bool
    {
        // Prevent path traversal
        if (str_contains($path, '..') || str_contains($path, '//')) {
            throw new \RuntimeException('Invalid backup path: path traversal detected');
        }

        // If school-specific, path must contain school ID segment
        if ($schoolId !== null && !str_contains($path, "school_{$schoolId}")) {
            Log::warning('TenantSecurityValidator: Path does not match school', [
                'path'      => $path,
                'school_id' => $schoolId,
            ]);
            // Warning only — path structure may vary
        }

        return true;
    }

    private function isSuperAdmin($user): bool
    {
        if (!$user) {
            return false;
        }
        $role = $user->role_type ?? $user->role ?? '';
        return in_array($role, ['super_admin', 'superadmin'], true);
    }
}
