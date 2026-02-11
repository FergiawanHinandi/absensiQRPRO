<?php

namespace App\Models\Traits;

use App\Models\Scopes\SchoolScope;
use App\Services\TenantScopeBypassAuditService;
use Illuminate\Database\Eloquent\Model;

/**
 * HasTenantScope Trait
 *
 * Provides tenant isolation for multi-tenant models.
 *
 * SECURITY:
 * - Automatically applies SchoolScope to all queries
 * - Bypass methods require super_admin role
 * - All bypass operations are audited
 *
 * USAGE:
 * - Add trait to model: use HasTenantScope;
 * - Use allTenants(), queryAllTenants(), findAnyTenant() for cross-tenant access
 * - All bypass operations are logged for security audit
 *
 * @version 2.0.0 - Added audit logging
 */
trait HasTenantScope
{
    /**
     * Boot the trait
     */
    protected static function bootHasTenantScope(): void
    {
        // Apply global scope for tenant isolation
        static::addGlobalScope(new SchoolScope);
    }

    /**
     * Get all records without tenant scope (super admin only)
     *
     * SECURITY:
     * - Requires super_admin role
     * - Logs all bypass attempts
     * - Returns empty collection for unauthorized users
     *
     * @param string|null $reason Reason for bypass (for audit)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function allTenants(?string $reason = null): \Illuminate\Database\Eloquent\Collection
    {
        $auditService = app(TenantScopeBypassAuditService::class);
        
        // Authorization check
        if (!$auditService->isAuthorized()) {
            // Log unauthorized attempt
            $auditService->logUnauthorizedAttempt('allTenants', static::class, [
                'method' => 'allTenants',
            ]);
            
            return collect();
        }

        // Log authorized bypass
        $auditService->logBypass('allTenants', static::class, [
            'method' => 'allTenants',
            'returns' => 'collection',
        ], $reason);

        return static::withoutGlobalScope(SchoolScope::class)->get();
    }

    /**
     * Query without tenant scope (super admin only)
     *
     * SECURITY:
     * - Requires super_admin role
     * - Logs all bypass attempts
     * - Returns empty query for unauthorized users
     *
     * @param string|null $reason Reason for bypass (for audit)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function queryAllTenants(?string $reason = null): \Illuminate\Database\Eloquent\Builder
    {
        $auditService = app(TenantScopeBypassAuditService::class);
        
        // Authorization check
        if (!$auditService->isAuthorized()) {
            // Log unauthorized attempt
            $auditService->logUnauthorizedAttempt('queryAllTenants', static::class, [
                'method' => 'queryAllTenants',
            ]);
            
            return static::where('id', 0); // Return empty query
        }

        // Log authorized bypass
        $auditService->logBypass('queryAllTenants', static::class, [
            'method' => 'queryAllTenants',
            'returns' => 'builder',
        ], $reason);

        return static::withoutGlobalScope(SchoolScope::class);
    }

    /**
     * Find by ID without tenant scope (super admin only)
     *
     * SECURITY:
     * - Requires super_admin role
     * - Logs all bypass attempts
     * - Returns null for unauthorized users
     *
     * @param mixed $id
     * @param string|null $reason Reason for bypass (for audit)
     * @return ?Model
     */
    public static function findAnyTenant($id, ?string $reason = null): ?Model
    {
        $auditService = app(TenantScopeBypassAuditService::class);
        
        // Authorization check
        if (!$auditService->isAuthorized()) {
            // Log unauthorized attempt
            $auditService->logUnauthorizedAttempt('findAnyTenant', static::class, [
                'method' => 'findAnyTenant',
                'id' => $id,
            ]);
            
            return null;
        }

        // Log authorized bypass
        $auditService->logBypass('findAnyTenant', static::class, [
            'method' => 'findAnyTenant',
            'id' => $id,
            'returns' => 'model',
        ], $reason);

        return static::withoutGlobalScope(SchoolScope::class)->find($id);
    }

    /**
     * Force school_id on creation
     */
    protected static function bootHasTenantScopeCreation(): void
    {
        static::creating(function (Model $model) {
            if (auth()->check() && auth()->user()->role_type !== 'super_admin') {
                $model->school_id = auth()->user()->school_id;
            }
        });
    }
}
