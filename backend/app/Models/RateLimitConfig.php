<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rate Limit Config Model
 *
 * Allows per-school dynamic rate limit configuration.
 * Spec: critical-rate-limiting / tasks.md Task 11.3
 */
class RateLimitConfig extends Model
{
    protected $table = 'rate_limit_configs';

    protected $fillable = [
        'school_id',
        'endpoint_key',
        'max_attempts',
        'decay_seconds',
        'is_active',
        'reason',
        'set_by',
    ];

    protected $casts = [
        'school_id'     => 'integer',
        'max_attempts'  => 'integer',
        'decay_seconds' => 'integer',
        'is_active'     => 'boolean',
    ];

    /**
     * Get effective rate limit config for a school+endpoint.
     * Falls back to global config (school_id = null) if no school-specific config.
     */
    public static function getEffective(int $schoolId, string $endpointKey): ?array
    {
        // School-specific config first
        $config = static::where('school_id', $schoolId)
            ->where('endpoint_key', $endpointKey)
            ->where('is_active', true)
            ->first();

        if ($config) {
            return $config->only(['max_attempts', 'decay_seconds']);
        }

        // Global override
        $global = static::whereNull('school_id')
            ->where('endpoint_key', $endpointKey)
            ->where('is_active', true)
            ->first();

        return $global?->only(['max_attempts', 'decay_seconds']);
    }
}
