<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Attendance Log Model
 *
 * Records individual scan events for audit and heatmap visualization.
 */
class AttendanceLog extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    protected $fillable = [
        'attendance_id',
        'qr_code_id',
        'user_id',
        'school_id',
        'teacher_id',
        'action',
        'ip_address',
        'user_agent',
        'latitude',
        'longitude',
        'location_accuracy',
        'students_scanned',
        'device_info',
        'previous_status',
        'new_status',
        'notes',
        'created_at',
        // State machine transition fields
        'from_state',
        'to_state',
        'performed_by',
        'reason',
        'changes',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'location_accuracy' => 'float',
        'students_scanned' => 'integer',
        'device_info' => 'array',
        'created_at' => 'datetime',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeWithLocation($query)
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    public function scopeForTeacher($query, int $teacherId)
    {
        return $query->where('teacher_id', $teacherId);
    }

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeInDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeOnDate($query, string $date)
    {
        return $query->whereDate('created_at', $date);
    }

    public function scopeScanActions($query)
    {
        return $query->whereIn('action', ['scan_in', 'scan_out']);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if this log has valid GPS coordinates
     */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Calculate distance from a point in meters
     */
    public function distanceFrom(float $lat, float $lng): float
    {
        if (! $this->hasLocation()) {
            return 0;
        }

        $earthRadius = 6371000; // meters

        $latFrom = deg2rad($this->latitude);
        $lonFrom = deg2rad($this->longitude);
        $latTo = deg2rad($lat);
        $lonTo = deg2rad($lng);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return round($angle * $earthRadius);
    }
}
