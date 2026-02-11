<?php

namespace App\Traits;

use App\Scopes\SchoolScope;

/**
 * Enforce Tenant Scope Trait
 * 
 * Strict Multi-Tenant Enforcement Layer.
 * Wraps BelongsToSchool logic but with a stricter naming convention.
 * 
 * Usage:
 * class Attendance extends Model {
 *     use EnforceTenantScope;
 * }
 */
trait EnforceTenantScope
{
    /**
     * Use the BelongsToSchool trait logic heavily
     * to manage school_id, validation, and auto-fill.
     */
    use BelongsToSchool;

    /**
     * Boot the trait.
     * Ensures SchoolScope is applied globally.
     */
    protected static function bootEnforceTenantScope(): void
    {
        // Check if SchoolScope is already applied by BelongsToSchool
        if (!static::hasGlobalScope(SchoolScope::class)) {
            static::addGlobalScope(new SchoolScope);
        }
    }

    /**
     * Check if a global scope is registered.
     * 
     * @param string $scope
     * @return bool
     */
    public static function hasGlobalScope($scope)
    {
        return isset(static::$globalScopes[static::class][$scope]);
    }
}
