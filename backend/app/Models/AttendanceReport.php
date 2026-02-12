<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AttendanceReport — Pre-generated attendance reports (daily/weekly/monthly/semester/yearly)
 *
 * @property int $id
 * @property int $school_id
 * @property string $report_type  (daily|weekly|monthly|semester|yearly)
 * @property int|null $class_id
 * @property int|null $student_id
 * @property \Carbon\Carbon $report_date
 * @property \Carbon\Carbon $period_start
 * @property \Carbon\Carbon $period_end
 * @property int $total_days
 * @property int $present_count
 * @property int $late_count
 * @property int $absent_count
 * @property int $sick_count
 * @property int $permit_count
 * @property float $attendance_rate
 * @property array $report_data
 * @property string|null $file_url
 * @property int $generated_by
 */
class AttendanceReport extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'report_type',
        'class_id',
        'student_id',
        'report_date',
        'period_start',
        'period_end',
        'total_days',
        'present_count',
        'late_count',
        'absent_count',
        'sick_count',
        'permit_count',
        'attendance_rate',
        'report_data',
        'file_url',
        'generated_by',
    ];

    protected $casts = [
        'report_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'total_days' => 'integer',
        'present_count' => 'integer',
        'late_count' => 'integer',
        'absent_count' => 'integer',
        'sick_count' => 'integer',
        'permit_count' => 'integer',
        'attendance_rate' => 'decimal:2',
        'report_data' => 'array',
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    // ─────────────────────────────────────────────────────────────────────
    // QUERY SCOPES
    // ─────────────────────────────────────────────────────────────────────

    public function scopeOfType($query, string $type)
    {
        return $query->where('report_type', $type);
    }

    public function scopeForClass($query, int $classId)
    {
        return $query->where('class_id', $classId);
    }

    public function scopeForStudent($query, int $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForPeriod($query, $start, $end)
    {
        return $query->where('period_start', '>=', $start)
            ->where('period_end', '<=', $end);
    }
}
