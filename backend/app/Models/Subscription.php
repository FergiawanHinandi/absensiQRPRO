<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use App\Traits\BelongsToSchool;

/**
 * Subscription Model
 * 
 * Manages school subscription status for SaaS billing
 * 
 * @property int $id
 * @property int $school_id
 * @property string $plan_type (free, basic, premium, enterprise)
 * @property bool $is_active
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property int $max_students
 * @property int $max_teachers
 * @property array $features
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Subscription extends Model
{
    use HasFactory;
    use BelongsToSchool; // Multi-tenancy: otomatis filter berdasarkan school_id

    protected $fillable = [
        'school_id',
        'plan_type',
        'is_active',
        'starts_at',
        'expires_at',
        'max_students',
        'max_teachers',
        'features',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'features' => 'array',
            'max_students' => 'integer',
            'max_teachers' => 'integer',
        ];
    }

    /**
     * Relationship: Subscription belongs to School
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Check if subscription is currently active
     */
    public function isActive(): bool
    {
        return $this->is_active 
            && $this->expires_at >= now()
            && $this->starts_at <= now();
    }

    /**
     * Check if subscription is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at < now();
    }

    /**
     * Check if subscription is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Get days remaining until expiry
     */
    public function daysRemaining(): int
    {
        if ($this->isExpired()) {
            return 0;
        }

        return now()->diffInDays($this->expires_at, false);
    }

    /**
     * Check if subscription is in grace period (7 days after expiry)
     */
    public function isInGracePeriod(): bool
    {
        if (!$this->isExpired()) {
            return false;
        }

        $gracePeriodEnd = $this->expires_at->copy()->addDays(7);
        return now() <= $gracePeriodEnd;
    }

    /**
     * Scope: Active subscriptions only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('expires_at', '>=', now())
            ->where('starts_at', '<=', now());
    }

    /**
     * Scope: Expired subscriptions
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    /**
     * Scope: Expiring soon (within X days)
     */
    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->where('is_active', true)
            ->where('expires_at', '>=', now())
            ->where('expires_at', '<=', now()->addDays($days));
    }
}
