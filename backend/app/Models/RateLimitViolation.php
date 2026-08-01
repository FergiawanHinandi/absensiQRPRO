<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rate Limit Violation Model
 *
 * Stores rate limit violations for audit, analysis, and monitoring dashboard.
 * Spec: critical-rate-limiting / tasks.md Task 11.3
 */
class RateLimitViolation extends Model
{
    use SoftDeletes;

    protected $table = 'rate_limit_violations';

    public $timestamps = false;

    protected $fillable = [
        'endpoint_key',
        'rate_limit_key',
        'ip_address',
        'user_id',
        'school_id',
        'attempt_count',
        'max_attempts',
        'user_agent',
        'url',
        'severity',
        'created_at',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'max_attempts'  => 'integer',
        'user_id'       => 'integer',
        'school_id'     => 'integer',
        'created_at'    => 'datetime',
    ];

    // Scopes
    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeForEndpoint($query, string $endpoint)
    {
        return $query->where('endpoint_key', $endpoint);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    public function scopeTopIps($query, int $limit = 10)
    {
        return $query->select('ip_address', \DB::raw('COUNT(*) as total'))
                     ->groupBy('ip_address')
                     ->orderByDesc('total')
                     ->limit($limit);
    }
}
