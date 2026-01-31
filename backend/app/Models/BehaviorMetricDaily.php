<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Behavior Metrics Daily
 * 
 * Aggregated daily behavior metrics for each teacher.
 * Used to establish baselines and detect anomalies.
 */
class BehaviorMetricDaily extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'behavior_metrics_daily';

    protected $fillable = [
        'user_id',
        'school_id',
        'date',
        'total_scans',
        'successful_scans',
        'failed_scans',
        'outside_radius_attempts',
        'device_mismatch_attempts',
        'schedule_mismatch_attempts',
        'qr_replay_attempts',
        'avg_scan_interval_seconds',
        'min_scan_interval_seconds',
        'max_scan_interval_seconds',
        'avg_distance_from_school',
        'max_distance_from_school',
        'unique_devices_used',
        'first_scan_time',
        'last_scan_time',
    ];

    protected $casts = [
        'date' => 'date',
        'total_scans' => 'integer',
        'successful_scans' => 'integer',
        'failed_scans' => 'integer',
        'outside_radius_attempts' => 'integer',
        'device_mismatch_attempts' => 'integer',
        'schedule_mismatch_attempts' => 'integer',
        'qr_replay_attempts' => 'integer',
        'avg_scan_interval_seconds' => 'integer',
        'min_scan_interval_seconds' => 'integer',
        'max_scan_interval_seconds' => 'integer',
        'avg_distance_from_school' => 'float',
        'max_distance_from_school' => 'float',
        'unique_devices_used' => 'integer',
        'first_scan_time' => 'datetime:H:i:s',
        'last_scan_time' => 'datetime:H:i:s',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeLastNDays($query, int $days)
    {
        return $query->where('date', '>=', now()->subDays($days)->toDateString())
                     ->where('date', '<', now()->toDateString());
    }

    public function scopeWithFailures($query)
    {
        return $query->where('failed_scans', '>', 0);
    }

    public function scopeWithViolations($query)
    {
        return $query->where(function ($q) {
            $q->where('outside_radius_attempts', '>', 0)
              ->orWhere('device_mismatch_attempts', '>', 0)
              ->orWhere('schedule_mismatch_attempts', '>', 0)
              ->orWhere('qr_replay_attempts', '>', 0);
        });
    }

    // =========================================================================
    // COMPUTED ATTRIBUTES
    // =========================================================================

    public function getFailedRatioAttribute(): float
    {
        if ($this->total_scans === 0) {
            return 0;
        }
        return round($this->failed_scans / $this->total_scans, 4);
    }

    public function getSuccessRatioAttribute(): float
    {
        if ($this->total_scans === 0) {
            return 0;
        }
        return round($this->successful_scans / $this->total_scans, 4);
    }

    public function getTotalViolationsAttribute(): int
    {
        return $this->outside_radius_attempts 
             + $this->device_mismatch_attempts 
             + $this->schedule_mismatch_attempts
             + $this->qr_replay_attempts;
    }

    // =========================================================================
    // STATIC METHODS
    // =========================================================================

    /**
     * Update or create daily metrics for a user
     */
    public static function recordOrUpdate(int $userId, int $schoolId, array $data): self
    {
        return self::updateOrCreate(
            [
                'user_id' => $userId,
                'school_id' => $schoolId,
                'date' => $data['date'] ?? today(),
            ],
            $data
        );
    }

    /**
     * Increment a specific metric counter
     */
    public static function incrementMetric(int $userId, int $schoolId, string $metric, int $amount = 1): void
    {
        $record = self::firstOrCreate(
            [
                'user_id' => $userId,
                'school_id' => $schoolId,
                'date' => today(),
            ],
            [
                'total_scans' => 0,
                'successful_scans' => 0,
                'failed_scans' => 0,
            ]
        );

        $record->increment($metric, $amount);
    }
}
