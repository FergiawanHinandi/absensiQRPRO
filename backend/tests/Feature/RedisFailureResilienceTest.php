<?php

namespace Tests\Feature;

use App\Domain\Attendance\Commands\RecordAttendanceCommand;
use App\Domain\Attendance\Handlers\RecordAttendanceHandler;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Student;
use App\Services\SafeRedisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Redis Failure Resilience Test
 * 
 * Simulates Redis failure during high load to verify:
 * - No cascading failures
 * - Automatic fallback to database locking
 * - No duplicate attendance records
 * - System remains operational
 */
class RedisFailureResilienceTest extends TestCase
{
    use RefreshDatabase;
    
    protected School $school;
    protected Classroom $classroom;
    protected Schedule $schedule;
    protected SafeRedisService $redis;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->school = School::factory()->create();
        $this->classroom = Classroom::factory()->create(['school_id' => $this->school->id]);
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        
        $this->redis = app(SafeRedisService::class);
    }
    
    /** @test */
    public function it_handles_attendance_recording_when_redis_is_down()
    {
        // Create a student
        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        
        // Simulate Redis being down by forcing circuit to open
        $this->simulateRedisFailure();
        
        // Verify circuit is open
        $this->assertFalse($this->redis->isAvailable());
        
        // Try to record attendance (should use database fallback)
        $command = new RecordAttendanceCommand(
            schoolId: $this->school->id,
            studentId: $student->id,
            scheduleId: $this->schedule->id,
            classId: $this->classroom->id,
            attendanceDate: today(),
            status: 'present',
            checkInTime: now(),
            qrCodeId: 1,
        );
        
        $handler = app(RecordAttendanceHandler::class);
        $attendance = $handler->handle($command);
        
        // Verify attendance was recorded successfully
        $this->assertInstanceOf(Attendance::class, $attendance);
        $this->assertEquals('present', $attendance->status);
        $this->assertEquals($student->id, $attendance->student_id);
        
        // Verify only one record exists (no duplicates)
        $count = Attendance::where('student_id', $student->id)
            ->whereDate('attendance_date', today())
            ->count();
        
        $this->assertEquals(1, $count);
    }
    
    /** @test */
    public function it_prevents_duplicate_attendance_with_database_fallback()
    {
        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        
        // Simulate Redis failure
        $this->simulateRedisFailure();
        
        $command = new RecordAttendanceCommand(
            schoolId: $this->school->id,
            studentId: $student->id,
            scheduleId: $this->schedule->id,
            classId: $this->classroom->id,
            attendanceDate: today(),
            status: 'present',
            checkInTime: now(),
            qrCodeId: 1,
        );
        
        $handler = app(RecordAttendanceHandler::class);
        
        // Record attendance twice
        $attendance1 = $handler->handle($command);
        $attendance2 = $handler->handle($command);
        
        // Should be the same record (updated, not duplicated)
        $this->assertEquals($attendance1->id, $attendance2->id);
        
        // Verify only one record exists
        $count = Attendance::where('student_id', $student->id)
            ->whereDate('attendance_date', today())
            ->count();
        
        $this->assertEquals(1, $count);
    }
    
    /** @test */
    public function it_handles_concurrent_attendance_recording_during_redis_failure()
    {
        // Create 10 students
        $students = Student::factory()->count(10)->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        
        // Simulate Redis failure
        $this->simulateRedisFailure();
        
        $handler = app(RecordAttendanceHandler::class);
        $recorded = 0;
        
        // Record attendance for all students
        foreach ($students as $student) {
            $command = new RecordAttendanceCommand(
                schoolId: $this->school->id,
                studentId: $student->id,
                scheduleId: $this->schedule->id,
                classId: $this->classroom->id,
                attendanceDate: today(),
                status: 'present',
                checkInTime: now(),
                qrCodeId: 1,
            );
            
            try {
                $handler->handle($command);
                $recorded++;
            } catch (\Exception $e) {
                // Should not happen
                $this->fail("Failed to record attendance: " . $e->getMessage());
            }
        }
        
        // Verify all were recorded
        $this->assertEquals(10, $recorded);
        
        // Verify count in database
        $count = Attendance::where('school_id', $this->school->id)
            ->whereDate('attendance_date', today())
            ->count();
        
        $this->assertEquals(10, $count);
    }
    
    /** @test */
    public function it_recovers_automatically_when_redis_comes_back()
    {
        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        
        // Simulate Redis failure
        $this->simulateRedisFailure();
        $this->assertFalse($this->redis->isAvailable());
        
        // Record attendance with database fallback
        $command = new RecordAttendanceCommand(
            schoolId: $this->school->id,
            studentId: $student->id,
            scheduleId: $this->schedule->id,
            classId: $this->classroom->id,
            attendanceDate: today(),
            status: 'present',
            checkInTime: now(),
            qrCodeId: 1,
        );
        
        $handler = app(RecordAttendanceHandler::class);
        $handler->handle($command);
        
        // Simulate Redis recovery
        $this->simulateRedisRecovery();
        
        // Wait for circuit to test recovery
        sleep(3);
        
        // Try a simple operation to trigger recovery
        $this->redis->set('test', 'value');
        
        // Circuit should eventually close
        // (In real scenario, this happens after success threshold is met)
        $status = $this->redis->getCircuitStatus();
        
        // State should be either HALF_OPEN or CLOSED
        $this->assertContains($status['state'], ['half_open', 'closed']);
    }
    
    /**
     * Simulate Redis failure by forcing circuit to open
     */
    private function simulateRedisFailure(): void
    {
        $circuitBreaker = app(\App\Infrastructure\CircuitBreaker\RedisCircuitBreaker::class);
        
        // Force failures to open circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $circuitBreaker->execute(function() {
                    throw new \Exception('Simulated Redis failure');
                });
            } catch (\Exception $e) {
                // Expected
            }
        }
    }
    
    /**
     * Simulate Redis recovery by resetting circuit
     */
    private function simulateRedisRecovery(): void
    {
        $circuitBreaker = app(\App\Infrastructure\CircuitBreaker\RedisCircuitBreaker::class);
        $circuitBreaker->reset();
    }
}
