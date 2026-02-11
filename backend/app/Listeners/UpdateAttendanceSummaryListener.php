<?php

namespace App\Listeners;

use App\Domain\Attendance\Events\AttendanceRecorded;
use App\Domain\Attendance\Events\AttendanceStatusChanged;
use App\ReadModels\AttendanceDailySummary;
use App\Services\SafeRedisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Update Attendance Summary Listener
 * 
 * Listens to attendance domain events and updates the read model.
 * Uses atomic increment/decrement operations for performance.
 * 
 * CQRS Pattern: This synchronizes Write Model → Read Model
 * Eventual Consistency: Updates happen asynchronously via queue
 * 
 * Performance:
 * - Queued processing (non-blocking)
 * - Redis lock for idempotency
 * - Prevents duplicate updates
 */
class UpdateAttendanceSummaryListener implements ShouldQueue
{
    use InteractsWithQueue;
    
    /**
     * The name of the queue the job should be sent to.
     *
     * @var string
     */
    public $queue = 'summary';
    
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;
    
    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 5;
    
    /**
     * Safe Redis Service for circuit breaker protection
     */
    public function __construct(
        private SafeRedisService $redis
    ) {}
    
    /**
     * Handle AttendanceRecorded event
     */
    public function handleAttendanceRecorded(AttendanceRecorded $event): void
    {
        // Acquire Redis lock for idempotency
        $lockKey = $this->getLockKey($event->schoolId, $event->attendanceDate, $event->attendanceId);
        
        if (!$this->acquireLock($lockKey)) {
            Log::info('summary_duplicate_blocked', [
                'attendance_id' => $event->attendanceId,
                'school_id' => $event->schoolId,
                'date' => $event->attendanceDate,
            ]);
            return; // Skip duplicate update
        }
        
        try {
            $this->updateSummary(
                schoolId: $event->schoolId,
                classId: $event->classId,
                date: $event->attendanceDate,
                newStatus: $event->status,
                oldStatus: $event->isUpdate ? $event->previousStatus : null,
            );
            
            Log::info('summary_updated', [
                'attendance_id' => $event->attendanceId,
                'status' => $event->status,
                'school_id' => $event->schoolId,
                'date' => $event->attendanceDate,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update attendance summary', [
                'event' => 'AttendanceRecorded',
                'attendance_id' => $event->attendanceId,
                'error' => $e->getMessage(),
            ]);
            
            // Release lock on failure so it can be retried
            $this->releaseLock($lockKey);
            
            // Don't throw - we don't want to fail the main operation
            // The summary will be eventually consistent via backfill if needed
        }
    }
    
    /**
     * Handle AttendanceStatusChanged event
     */
    public function handleAttendanceStatusChanged(AttendanceStatusChanged $event): void
    {
        // Acquire Redis lock for idempotency
        $lockKey = $this->getLockKey($event->schoolId, $event->attendanceDate, $event->attendanceId);
        
        if (!$this->acquireLock($lockKey)) {
            Log::info('summary_duplicate_blocked', [
                'attendance_id' => $event->attendanceId,
                'school_id' => $event->schoolId,
                'date' => $event->attendanceDate,
            ]);
            return; // Skip duplicate update
        }
        
        try {
            $this->updateSummary(
                schoolId: $event->schoolId,
                classId: $event->classId,
                date: $event->attendanceDate,
                newStatus: $event->newStatus,
                oldStatus: $event->oldStatus,
            );
            
            Log::info('summary_updated', [
                'attendance_id' => $event->attendanceId,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'school_id' => $event->schoolId,
                'date' => $event->attendanceDate,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update attendance summary', [
                'event' => 'AttendanceStatusChanged',
                'attendance_id' => $event->attendanceId,
                'error' => $e->getMessage(),
            ]);
            
            // Release lock on failure so it can be retried
            $this->releaseLock($lockKey);
        }
    }
    
    /**
     * Get Redis lock key for idempotency
     */
    private function getLockKey(int $schoolId, string $date, int $attendanceId): string
    {
        return "summary_lock:{$schoolId}:{$date}:{$attendanceId}";
    }
    
    /**
     * Acquire Redis lock for idempotent processing
     * 
     * @return bool True if lock acquired, false if already locked
     */
    private function acquireLock(string $lockKey): bool
    {
        try {
            // Try to acquire lock with 5 second expiry using SET NX
            $acquired = $this->redis->execute(
                operation: fn() => Redis::set($lockKey, 1, 'EX', 5, 'NX'),
                fallback: fn() => true // If Redis down, allow processing
            );
            
            return (bool) $acquired;
            
        } catch (\Exception $e) {
            Log::warning('Failed to acquire summary lock, proceeding anyway', [
                'lock_key' => $lockKey,
                'error' => $e->getMessage(),
            ]);
            
            // If Redis fails, allow processing (fail open)
            return true;
        }
    }
    
    /**
     * Release Redis lock
     */
    private function releaseLock(string $lockKey): void
    {
        try {
            $this->redis->delete($lockKey);
        } catch (\Exception $e) {
            // Ignore errors on release - lock will expire anyway
            Log::debug('Failed to release summary lock', [
                'lock_key' => $lockKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
    

    
    /**
     * Update summary using atomic operations
     * 
     * @param int $schoolId
     * @param int $classId
     * @param string $date
     * @param string $newStatus
     * @param string|null $oldStatus
     */
    private function updateSummary(
        int $schoolId,
        int $classId,
        string $date,
        string $newStatus,
        ?string $oldStatus = null
    ): void {
        // Update school-wide summary
        $this->updateSingleSummary($schoolId, null, $date, $newStatus, $oldStatus);
        
        // Update class-specific summary
        $this->updateSingleSummary($schoolId, $classId, $date, $newStatus, $oldStatus);
    }
    
    /**
     * Update a single summary record using atomic increment/decrement
     * 
     * @param int $schoolId
     * @param int|null $classId
     * @param string $date
     * @param string $newStatus
     * @param string|null $oldStatus
     */
    private function updateSingleSummary(
        int $schoolId,
        ?int $classId,
        string $date,
        string $newStatus,
        ?string $oldStatus
    ): void {
        // Find or create summary record
        $summary = AttendanceDailySummary::firstOrCreate(
            [
                'school_id' => $schoolId,
                'class_id' => $classId,
                'attendance_date' => $date,
            ],
            [
                'total_students' => 0,
                'total_present' => 0,
                'total_late' => 0,
                'total_absent' => 0,
                'total_excused' => 0,
                'attendance_rate' => 0,
            ]
        );
        
        // Decrement old status count (if updating)
        if ($oldStatus) {
            $this->decrementStatus($summary, $oldStatus);
        }
        
        // Increment new status count
        $this->incrementStatus($summary, $newStatus);
        
        // Recalculate attendance rate
        $this->recalculateRate($summary);
        
        // Update timestamp
        $summary->last_updated_at = now();
        $summary->save();
    }
    
    /**
     * Increment status counter
     */
    private function incrementStatus(AttendanceDailySummary $summary, string $status): void
    {
        match ($status) {
            'present' => $summary->increment('total_present'),
            'late' => $summary->increment('total_late'),
            'absent' => $summary->increment('total_absent'),
            'excused' => $summary->increment('total_excused'),
            default => null,
        };
    }
    
    /**
     * Decrement status counter
     */
    private function decrementStatus(AttendanceDailySummary $summary, string $status): void
    {
        match ($status) {
            'present' => $summary->decrement('total_present'),
            'late' => $summary->decrement('total_late'),
            'absent' => $summary->decrement('total_absent'),
            'excused' => $summary->decrement('total_excused'),
            default => null,
        };
    }
    
    /**
     * Recalculate attendance rate
     */
    private function recalculateRate(AttendanceDailySummary $summary): void
    {
        $total = $summary->total_present + $summary->total_late + $summary->total_absent + $summary->total_excused;
        
        if ($total > 0) {
            $attended = $summary->total_present + $summary->total_late;
            $summary->attendance_rate = round(($attended / $total) * 100, 2);
        } else {
            $summary->attendance_rate = 0;
        }
    }
}
