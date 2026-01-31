<?php

namespace App\Traits;

use App\Models\School;
use App\Scopes\SchoolScope;
use Illuminate\Support\Facades\Auth;

trait BelongsToSchool
{
    /**
     * Boot the trait.
     */
    protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function ($model) {
            // Auto-fill school_id if not set and user is logged in
            if (Auth::check() && ! $model->school_id) {
                $user = Auth::user();
                if ($user->school_id) {
                    $model->school_id = $user->school_id;
                }
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
}
