<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class SchoolScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Don't apply scope if no authenticated user
        if (!Auth::check()) {
            return;
        }

        $user = Auth::user();

        // Super admin bypass - no school filtering
        if ($user->role_type === 'super_admin') {
            return;
        }

        // Apply school_id filter for all other users
        $builder->where('school_id', $user->school_id);
    }
}
