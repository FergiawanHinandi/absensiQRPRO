<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DailyAttendanceSummary — Write-side model for daily_attendance_summaries table
 *
 * Works alongside App\ReadModels\AttendanceDailySummary (read-side, CQRS pattern).
 * Used by AttendanceSummaryService and Jobs for upsert operations.
 *
 * @property int $id
 * @property int $school_id
 * @property \Carbon\Carbon $attendance_date
 * @property int|null $class_id
 * @property int $total_students
 * @property int $total_present
 * @property int $total_late
 * @property int $total_absent
 * @property int $total_excused
 * @property int $total_permission
 * @property int $total_sick
 * @property float $attendance_rate
 * @property \Carbon\Carbon $last_updated_at
 */
class DailyAttendanceSummary extends Model
{
    use BelongsToSchool;

    protected $table = 'daily_attendance_summaries';

    protected $fillable = [
        'school_id',
        'attendance_date',
        'class_id',
        'total_students',
        'total_present',
        'total_late',
        'total_absent',
        'total_excused',
        'total_permission',
        'total_sick',
        'attendance_rate',
        'last_updated_at',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'total_students' => 'integer',
        'total_present' => 'integer',
        'total_late' => 'integer',
        'total_absent' => 'integer',
        'total_excused' => 'integer',
        'total_permission' => 'integer',
        'total_sick' => 'integer',
        'attendance_rate' => 'decimal:2',
        'last_updated_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    // ─────────────────────────────────────────────────────────────────────
    // QUERY SCOPES
    // ─────────────────────────────────────────────────────────────────────

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('attendance_date', $date);
    }

    public function scopeForClass($query, int $classId)
    {
        return $query->where('class_id', $classId);
    }

    public function scopeSchoolWide($query)
    {
        return $query->whereNull('class_id');
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('attendance_date', [$startDate, $endDate]);
    }
}
