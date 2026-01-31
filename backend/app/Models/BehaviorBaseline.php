<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Behavior Baseline
 * 
 * Stores rolling 14-day behavioral baselines for each teacher.
 * Used as reference point for anomaly detection.
 */
class BehaviorBaseline extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'behavior_baselines';

    // Risk level constants
    public const RISK_NORMAL = 'normal';
    public const RISK_SUSPICIOUS = 'suspicious';
    public const RISK_HIGH = 'high';
    public const RISK_CRITICAL = 'critical';

    // Risk score thresholds
    public const THRESHOLD_SUSPICIOUS = 3;
    public const THRESHOLD_HIGH = 6;
    public const THRESHOLD_CRITICAL = 9;

    protected $fillable = [
        'user_id',
        'school_id',
        'avg_scans_per_day',
        'avg_successful_scans',
        'avg_failed_ratio',
        'avg_outside_radius_attempts',
        'avg_device_mismatch_attempts',
        'avg_schedule_mismatch_attempts',
        'avg_scan_interval_seconds',
        'stddev_scans',
        'stddev_failed_ratio',
        'stddev_scan_interval',
        'days_in_baseline',
        'baseline_start_date',
        'baseline_end_date',
        'current_risk_level',
        'current_risk_score',
        'current_risk_factors',
        'last_risk_assessment',
        'requires_device_reverification',
        'flagged_for_review',
        'flagged_at',
        'flagged_by',
    ];

    protected $casts = [
        'avg_scans_per_day' => 'float',
        'avg_successful_scans' => 'float',
        'avg_failed_ratio' => 'float',
        'avg_outside_radius_attempts' => 'float',
        'avg_device_mismatch_attempts' => 'float',
        'avg_schedule_mismatch_attempts' => 'float',
        'avg_scan_interval_seconds' => 'float',
        'stddev_scans' => 'float',
        'stddev_failed_ratio' => 'float',
        'stddev_scan_interval' => 'float',
        'days_in_baseline' => 'integer',
        'baseline_start_date' => 'date',
        'baseline_end_date' => 'date',
        'current_risk_score' => 'integer',
        'current_risk_factors' => 'array',
        'last_risk_assessment' => 'datetime',
        'requires_device_reverification' => 'boolean',
        'flagged_for_review' => 'boolean',
        'flagged_at' => 'datetime',
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

    public function flagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
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

    public function scopeAtRisk($query)
    {
        return $query->whereIn('current_risk_level', [
            self::RISK_SUSPICIOUS, 
            self::RISK_HIGH, 
            self::RISK_CRITICAL
        ]);
    }

    public function scopeCritical($query)
    {
        return $query->where('current_risk_level', self::RISK_CRITICAL);
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('current_risk_level', [self::RISK_HIGH, self::RISK_CRITICAL]);
    }

    public function scopeFlagged($query)
    {
        return $query->where('flagged_for_review', true);
    }

    public function scopeRequiresReverification($query)
    {
        return $query->where('requires_device_reverification', true);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Update risk assessment
     */
    public function updateRiskAssessment(int $score, array $factors): void
    {
        $this->update([
            'current_risk_score' => $score,
            'current_risk_level' => self::scoreToRiskLevel($score),
            'current_risk_factors' => $factors,
            'last_risk_assessment' => now(),
        ]);
    }

    /**
     * Flag user for admin review
     */
    public function flagForReview(?int $flaggedBy = null): void
    {
        $this->update([
            'flagged_for_review' => true,
            'flagged_at' => now(),
            'flagged_by' => $flaggedBy,
        ]);
    }

    /**
     * Clear review flag
     */
    public function clearFlag(): void
    {
        $this->update([
            'flagged_for_review' => false,
            'flagged_at' => null,
            'flagged_by' => null,
        ]);
    }

    /**
     * Require device re-verification
     */
    public function requireDeviceReverification(): void
    {
        $this->update(['requires_device_reverification' => true]);
    }

    /**
     * Clear device re-verification requirement
     */
    public function clearDeviceReverification(): void
    {
        $this->update(['requires_device_reverification' => false]);
    }

    /**
     * Check if baseline has sufficient data
     */
    public function hasSufficientData(): bool
    {
        return $this->days_in_baseline >= 7;
    }

    /**
     * Check if user is at risk
     */
    public function isAtRisk(): bool
    {
        return in_array($this->current_risk_level, [
            self::RISK_SUSPICIOUS,
            self::RISK_HIGH,
            self::RISK_CRITICAL,
        ]);
    }

    /**
     * Check if user is critical risk
     */
    public function isCritical(): bool
    {
        return $this->current_risk_level === self::RISK_CRITICAL;
    }

    /**
     * Get top risk factors
     */
    public function getTopRiskFactors(int $limit = 3): array
    {
        $factors = $this->current_risk_factors ?? [];
        
        // Sort by score descending
        uasort($factors, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        
        return array_slice($factors, 0, $limit, true);
    }

    // =========================================================================
    // STATIC METHODS
    // =========================================================================

    /**
     * Convert risk score to risk level
     */
    public static function scoreToRiskLevel(int $score): string
    {
        if ($score >= self::THRESHOLD_CRITICAL) {
            return self::RISK_CRITICAL;
        }
        if ($score >= self::THRESHOLD_HIGH) {
            return self::RISK_HIGH;
        }
        if ($score >= self::THRESHOLD_SUSPICIOUS) {
            return self::RISK_SUSPICIOUS;
        }
        return self::RISK_NORMAL;
    }

    /**
     * Get risk level color for UI
     */
    public static function getRiskLevelColor(string $level): string
    {
        return match ($level) {
            self::RISK_CRITICAL => 'red',
            self::RISK_HIGH => 'orange',
            self::RISK_SUSPICIOUS => 'yellow',
            default => 'green',
        };
    }

    /**
     * Get risk level emoji
     */
    public static function getRiskLevelEmoji(string $level): string
    {
        return match ($level) {
            self::RISK_CRITICAL => '🔴',
            self::RISK_HIGH => '🟠',
            self::RISK_SUSPICIOUS => '🟡',
            default => '🟢',
        };
    }

    /**
     * Get or create baseline for user
     */
    public static function getOrCreateForUser(int $userId, int $schoolId): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId],
            [
                'school_id' => $schoolId,
                'current_risk_level' => self::RISK_NORMAL,
                'current_risk_score' => 0,
            ]
        );
    }
}
