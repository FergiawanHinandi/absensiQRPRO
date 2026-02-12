<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SubscriptionPackage — SaaS billing plan definitions
 *
 * @property int $id
 * @property string $name
 * @property float $price
 * @property string $billing_cycle (monthly|yearly)
 * @property array|null $features
 * @property bool $is_popular
 * @property bool $is_active
 */
class SubscriptionPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'billing_cycle',
        'features',
        'is_popular',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'is_popular' => 'boolean',
        'price' => 'decimal:2',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // QUERY SCOPES
    // ─────────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }

    public function scopeByBillingCycle($query, string $cycle)
    {
        return $query->where('billing_cycle', $cycle);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ACCESSORS
    // ─────────────────────────────────────────────────────────────────────

    public function getMaxStudentsAttribute(): int
    {
        return $this->features['max_students'] ?? 0;
    }

    public function getMaxTeachersAttribute(): int
    {
        return $this->features['max_teachers'] ?? 0;
    }

    public function getMaxClassesAttribute(): int
    {
        return $this->features['max_classes'] ?? 0;
    }

    public function getStorageGbAttribute(): int
    {
        return $this->features['storage_gb'] ?? 0;
    }
}
