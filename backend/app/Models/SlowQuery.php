<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlowQuery extends Model
{
    protected $fillable = [
        'sql',
        'bindings',
        'execution_time',
        'connection',
        'execution_plan',
        'request_id',
        'user_id',
        'school_id',
        'route',
        'method',
        'query_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'bindings' => 'array',
        'execution_time' => 'decimal:2',
        'query_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Get the normalized SQL (without bindings) for grouping
     */
    public function getNormalizedSqlAttribute(): string
    {
        return preg_replace('/\?/', '?', $this->sql);
    }

    /**
     * Scope to get queries slower than threshold
     */
    public function scopeSlowerThan($query, float $milliseconds)
    {
        return $query->where('execution_time', '>', $milliseconds);
    }

    /**
     * Scope to get recent queries
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to get queries by school
     */
    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    /**
     * Get top slow queries grouped by normalized SQL
     */
    public static function getTopSlowQueries(int $limit = 10, int $days = 7)
    {
        return static::query()
            ->recent($days)
            ->selectRaw('
                sql,
                AVG(execution_time) as avg_time,
                MAX(execution_time) as max_time,
                MIN(execution_time) as min_time,
                SUM(query_count) as total_count,
                MAX(last_seen_at) as last_seen
            ')
            ->groupBy('sql')
            ->orderByDesc('avg_time')
            ->limit($limit)
            ->get();
    }

    /**
     * Get query frequency statistics
     */
    public static function getQueryFrequency(int $days = 7)
    {
        return static::query()
            ->recent($days)
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as query_count,
                AVG(execution_time) as avg_time
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Clean up old queries based on retention policy
     */
    public static function cleanup(int $retentionDays = 30): int
    {
        return static::where('created_at', '<', now()->subDays($retentionDays))->delete();
    }
}
