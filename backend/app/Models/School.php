<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'npsn',
        'school_level',
        'code',
        'address',
        'phone',
        'email',
        'timezone',
        'latitude',
        'longitude',
        'radius_meters',
        'logo_url',
        'settings',
        'is_active',
        'package_type',
        'max_students',
        'max_teachers',
        'max_classes',
        'package_updated_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    /**
     * Get the users for the school.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the classes for the school.
     */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /**
     * Get the subscription for the school.
     */
    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Get the active subscription for the school.
     */
    public function activeSubscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('is_active', true)
            ->where('expires_at', '>=', now())
            ->where('starts_at', '<=', now());
    }

    /**
     * Check if school has an active subscription
     */
    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    /**
     * Scope a query to only include active schools.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
