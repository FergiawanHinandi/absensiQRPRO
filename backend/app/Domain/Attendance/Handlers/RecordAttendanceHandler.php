<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\RecordAttendanceCommand;
use App\Domain\Attendance\Events\AttendanceRecorded;
use App\Domain\Shared\Command;
use App\Domain\Shared\CommandHandler;
use App\Infrastructure\CircuitBreaker\CircuitBreakerOpenException;
use App\Models\Attendance;
use App\Services\SafeRedisService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Record Attendance Handler
 * 
 * Handles the RecordAttendanceCommand with:
 * - Circuit breaker protected Redis locking
 * - Fallback to database locking when Redis is down
 * - Database transaction for atomicity
 * - Domain event dispatch for read model updates
 * 
 * CQRS Pattern: This is the WRITE side handler
 * Resilience: Uses circuit breaker to prevent Redis failures from cascading
 */
class RecordAttendanceHandler implements CommandHandler
{
    public function __construct(
        private SafeRedisService $redis
    ) {}
    
    /**
     * Handle the command
     * 
     * @param RecordAttendanceCommand $command
     * @return Attendance
     * @throws \Exception
     */
    public function handle(Command $command): Attendance
    {
        if (!$command instanceof RecordAttendanceCommand) {
            throw new \InvalidArgumentException('Invalid command type');
        }
        
        // Generate lock key for this student + date
        $lockKey = sprintf(
            'attendance:lock:%d:%s',
            $command->studentId,
            $command->attendanceDate->toDateString()
        );
        
        // Try Redis lock first (with circuit breaker)
        $lock = $this->redis->lock($lockKey, 5);
        
        if ($lock) {
            // Redis is available, use Redis lock
            return $this->executeWithRedisLock($command, $lock);
        }
        
        // Redis is down (circuit open), fallback to database lock
        Log::warning('Redis unavailable, using database lock for attendance', [
            'student_id' => $command->studentId,
            'circuit_status' => $this->redis->getCircuitStatus(),
        ]);
        
        return $this->executeWithDatabaseLock($command, $lockKey);
    }
    
    /**
     * Execute with Redis lock
     */
    private function executeWithRedisLock(Command $command, $lock): Attendance
    {
        try {
            // Wait up to 3 seconds to acquire lock
            $lock->block(3);
            
            // Execute in database transaction
            $attendance = DB::transaction(function () use ($command) {
                return $this->executeCommand($command);
            });
            
            // Dispatch domain event (outside transaction for async processing)
            $this->dispatchEvent($attendance);
            
            Log::info('Attendance recorded successfully (Redis lock)', [
                'attendance_id' => $attendance->id,
                'student_id' => $command->studentId,
                'status' => $command->status,
            ]);
            
            return $attendance;
            
        } catch (\Illuminate\Contracts\Redis\LockTimeoutException $e) {
            Log::warning('Failed to acquire Redis lock', [
                'student_id' => $command->studentId,
                'date' => $command->attendanceDate->toDateString(),
            ]);
            
            throw new \Exception('Another attendance operation is in progress. Please try again.');
            
        } finally {
            // Always release the lock
            $lock->release();
        }
    }
    
    /**
     * Execute with database lock (fallback when Redis is down)
     */
    private function executeWithDatabaseLock(Command $command, string $lockKey): Attendance
    {
        // Use Cache::lock with database driver as fallback
        $lock = Cache::lock($lockKey, 5);
        
        try {
            // Wait up to 3 seconds to acquire lock
            if (!$lock->block(3)) {
                throw new \Exception('Failed to acquire database lock. Please try again.');
            }
            
            // Execute in database transaction
            $attendance = DB::transaction(function () use ($command) {
                return $this->executeCommand($command);
            });
            
            // Dispatch domain event
            $this->dispatchEvent($attendance);
            
            Log::info('Attendance recorded successfully (DB lock fallback)', [
                'attendance_id' => $attendance->id,
                'student_id' => $command->studentId,
                'status' => $command->status,
            ]);
            
            return $attendance;
            
        } finally {
            // Always release the lock
            $lock->release();
        }
    }
    
    /**
     * Dispatch attendance recorded event
     * 
     * Uses afterCommit() to ensure event is only dispatched after
     * database transaction commits successfully.
     */
    private function dispatchEvent(Attendance $attendance): void
    {
        // Dispatch after transaction commits for queue safety
        event(new AttendanceRecorded(
            attendanceId: $attendance->id,
            schoolId: $attendance->school_id,
            studentId: $attendance->student_id,
            classId: $attendance->class_id,
            attendanceDate: $attendance->attendance_date->toDateString(),
            status: $attendance->status,
        ))->afterCommit();
    }
    
    /**
     * Execute the command within a transaction
     * 
     * @param RecordAttendanceCommand $command
     * @return Attendance
     */
    private function executeCommand(RecordAttendanceCommand $command): Attendance
    {
        // Check for existing attendance record
        $existing = Attendance::where('school_id', $command->schoolId)
            ->where('student_id', $command->studentId)
            ->where('schedule_id', $command->scheduleId)
            ->where('attendance_date', $command->attendanceDate)
            ->first();
        
        if ($existing) {
            // Update existing record
            $existing->update([
                'status' => $command->status,
                'check_in_time' => $command->checkInTime,
                'lat_in' => $command->latitude,
                'lng_in' => $command->longitude,
                'device_id_in' => $command->deviceId,
                'qr_code_id' => $command->qrCodeId,
                'is_manual' => $command->isManual,
                'recorded_by' => $command->recordedBy,
                'notes' => $command->notes,
            ]);
            
            return $existing->fresh();
        }
        
        // Create new attendance record (using firstOrCreate)
        $attendance = Attendance::firstOrCreate(
            [
                'student_id' => $command->studentId,
                'schedule_id' => $command->scheduleId,
                'attendance_date' => $command->attendanceDate,
                'school_id' => $command->schoolId,
            ],
            [
                'class_id' => $command->classId,
                'status' => $command->status,
                'check_in_time' => $command->checkInTime,
                'lat_in' => $command->latitude,
                'lng_in' => $command->longitude,
                'device_id_in' => $command->deviceId,
                'qr_code_id' => $command->qrCodeId,
                'is_manual' => $command->isManual,
                'recorded_by' => $command->recordedBy,
                'notes' => $command->notes,
                'source' => $command->isManual ? 'manual' : 'qr_scan',
            ]
        );
        
        // Check if attendance already existed (constraint violation handling)
        if (!$attendance->wasRecentlyCreated) {
            throw new \App\Exceptions\AttendanceException(
                'Attendance record already exists for this student, schedule, and date.'
            );
        }
        
        return $attendance;
    }
}
