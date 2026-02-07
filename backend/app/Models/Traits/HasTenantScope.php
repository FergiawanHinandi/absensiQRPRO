<?php

namespace App\Models\Traits;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;

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
     */
    public static function allTenants(): \Illuminate\Database\Eloquent\Collection
    {
        if (!auth()->check() || auth()->user()->role_type !== 'super_admin') {
            return collect();
        }

        return static::withoutGlobalScope(SchoolScope::class)->get();
    }

    /**
     * Query without tenant scope (super admin only)
     */
    public static function queryAllTenants(): \Illuminate\Database\Eloquent\Builder
    {
        if (!auth()->check() || auth()->user()->role_type !== 'super_admin') {
            return static::where('id', 0); // Return empty query
        }

        return static::withoutGlobalScope(SchoolScope::class);
    }

    /**
     * Find by ID without tenant scope (super admin only)
     */
    public static function findAnyTenant($id): ?Model
    {
        if (!auth()->check() || auth()->user()->role_type !== 'super_admin') {
            return null;
        }

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
