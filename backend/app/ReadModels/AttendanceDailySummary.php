<?php

namespace App\ReadModels;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Attendance Daily Summary - READ MODEL
 * 
 * This is a denormalized, pre-aggregated table for fast dashboard queries.
 * Updated via event listeners when attendance records are created/modified.
 * 
 * CQRS Pattern: This is the QUERY side (Read Model)
 * - Optimized for reads
 * - Eventually consistent (1-2 second delay acceptable)
 * - No business logic
 * - Updated via domain events
 * 
 * READ REPLICA SUPPORT:
 * This model automatically uses read replicas when DB_READ_HOST is configured.
 * Laravel will route SELECT queries to replica, INSERT/UPDATE to primary.
 * No code changes needed - it's handled by database.php configuration.
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
 * @property float $attendance_rate
 * @property \Carbon\Carbon $last_updated_at
 */
class AttendanceDailySummary extends Model
{
    use HasFactory;

    protected $table = 'attendance_daily_summaries';

    protected static function newFactory()
    {
        return \Database\Factories\AttendanceDailySummaryFactory::new();
    }
    
    protected $fillable = [
        'school_id',
        'attendance_date',
        'class_id',
        'total_students',
        'total_present',
        'total_late',
        'total_absent',
        'total_excused',
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
        'attendance_rate' => 'decimal:2',
        'last_updated_at' => 'datetime',
    ];
    
    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────
    
    public function school(): BelongsTo
    {
        return $this->belongsTo(\App\Models\School::class);
    }
    
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ClassModel::class, 'class_id');
    }
    
    // ─────────────────────────────────────────────────────────────────────
    // QUERY SCOPES
    // ─────────────────────────────────────────────────────────────────────
    
    /**
     * Scope for a specific school
     */
    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }
    
    /**
     * Scope for a specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('attendance_date', $date);
    }
    
    /**
     * Scope for a specific class
     */
    public function scopeForClass($query, int $classId)
    {
        return $query->where('class_id', $classId);
    }
    
    /**
     * Scope for school-wide summary (no class breakdown)
     */
    public function scopeSchoolWide($query)
    {
        return $query->whereNull('class_id');
    }
    
    /**
     * Scope for date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('attendance_date', [$startDate, $endDate]);
    }
    
    // ─────────────────────────────────────────────────────────────────────
    // STATIC QUERY HELPERS
    // ─────────────────────────────────────────────────────────────────────
    
    /**
     * Get today's summary for a school
     * 
     * @param int $schoolId
     * @return self|null
     */
    public static function getTodaySummary(int $schoolId): ?self
    {
        return static::forSchool($schoolId)
            ->forDate(today())
            ->schoolWide()
            ->first();
    }
    
    /**
     * Get summary for a specific class and date
     * 
     * @param int $schoolId
     * @param int $classId
     * @param string|\Carbon\Carbon $date
     * @return self|null
     */
    public static function getClassSummary(int $schoolId, int $classId, $date): ?self
    {
        return static::forSchool($schoolId)
            ->forClass($classId)
            ->forDate($date)
            ->first();
    }
    
    /**
     * Get weekly trend for a school
     * 
     * @param int $schoolId
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getWeeklyTrend(int $schoolId, int $days = 7)
    {
        $startDate = today()->subDays($days - 1);
        $endDate = today();
        
        return static::forSchool($schoolId)
            ->schoolWide()
            ->dateRange($startDate, $endDate)
            ->orderBy('attendance_date')
            ->get();
    }
    
    /**
     * Get all class summaries for a specific date
     * 
     * @param int $schoolId
     * @param string|\Carbon\Carbon $date
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getClassSummaries(int $schoolId, $date)
    {
        return static::forSchool($schoolId)
            ->forDate($date)
            ->whereNotNull('class_id')
            ->with('classroom')
            ->get();
    }
    
    // ─────────────────────────────────────────────────────────────────────
    // ACCESSORS
    // ─────────────────────────────────────────────────────────────────────
    
    /**
     * Get total checked in (present + late)
     */
    public function getTotalCheckedInAttribute(): int
    {
        return $this->total_present + $this->total_late;
    }
    
    /**
     * Get percentage of students not checked in
     */
    public function getNotCheckedInRateAttribute(): float
    {
        if ($this->total_students === 0) {
            return 0;
        }
        
        return round(($this->total_absent / $this->total_students) * 100, 2);
    }
}
