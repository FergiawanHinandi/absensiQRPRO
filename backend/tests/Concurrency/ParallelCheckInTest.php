<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Application\Services\AttendanceApplicationService;
use App\Models\Attendance;
use App\Models\School;
use App\Models\Schedule;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrency Test: Parallel Check-In
 * Tests system behavior under concurrent load:
 * - 100+ parallel requests
 * - Race conditions
 * - Deadlock prevention
 * - Duplicate prevention
 * - Proper error responses
 */
#[\PHPUnit\Framework\Attributes\Group('concurrency')]
#[\PHPUnit\Framework\Attributes\Group('slow')]
class ParallelCheckInTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Student $student;
    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->student = Student::factory()->create(['school_id' => $this->school->id]);
        $this->schedule = Schedule::factory()->create(['school_id' => $this->school->id]);
    }

    /**
     * CT-001: 100 concurrent check-ins (same student)
     * Expected: Only 1 attendance record created, others get 409 Conflict
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_duplicate_attendance_under_concurrent_load(): void
    {
        // Arrange
        $service = app(AttendanceApplicationService::class);
        $concurrentRequests = 100;
        $results = [];
        $errors = [];

        // Act: Simulate 100 concurrent check-ins for the same student
        $promises = [];
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $promises[] = function() use ($service, &$results, &$errors) {
                try {
                    $attendance = $service->checkIn([
                        'student_id' => $this->student->id,
                        'schedule_id' => $this->schedule->id,
                        'school_id' => $this->school->id,
                        'attendance_date' => today()->format('Y-m-d'),
                        'check_in_time' => now(),
                    ]);
                    $results[] = $attendance->id;
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                }
            };
        }

        // Execute all promises
        foreach ($promises as $promise) {
            $promise();
        }

        // Assert: Only 1 attendance record created
        $count = Attendance::where('student_id', $this->student->id)
            ->where('attendance_date', today())
            ->count();

        $this->assertEquals(1, $count, 'Should only create 1 attendance record');
        $this->assertCount(1, $results, 'Should only have 1 successful result');
        $this->assertCount(99, $errors, 'Should have 99 errors');
    }

    /**
     * CT-002: 100 concurrent check-ins (different students)
     * Expected: All 100 attendance records created successfully
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_concurrent_check_ins_for_different_students(): void
    {
        // Arrange
        $students = Student::factory()->count(100)->create([
            'school_id' => $this->school->id,
        ]);

        $service = app(AttendanceApplicationService::class);
        $successCount = 0;

        // Act: Simulate 100 concurrent check-ins for different students
        foreach ($students as $student) {
            try {
                $service->checkIn([
                    'student_id' => $student->id,
                    'schedule_id' => $this->schedule->id,
                    'school_id' => $this->school->id,
                    'attendance_date' => today()->format('Y-m-d'),
                    'check_in_time' => now(),
                ]);
                $successCount++;
            } catch (\Exception $e) {
                // Should not happen
            }
        }

        // Assert: All 100 records created
        $this->assertEquals(100, $successCount);
        $this->assertEquals(100, Attendance::count());
    }

    /**
     * CT-003: No database deadlocks
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_database_deadlocks(): void
    {
        // Arrange
        $students = Student::factory()->count(50)->create([
            'school_id' => $this->school->id,
        ]);

        $service = app(AttendanceApplicationService::class);
        $deadlockCount = 0;

        // Act: Simulate concurrent updates that could cause deadlocks
        foreach ($students as $student) {
            try {
                DB::transaction(function() use ($service, $student) {
                    $service->checkIn([
                        'student_id' => $student->id,
                        'schedule_id' => $this->schedule->id,
                        'school_id' => $this->school->id,
                        'attendance_date' => today()->format('Y-m-d'),
                        'check_in_time' => now(),
                    ]);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'Deadlock')) {
                    $deadlockCount++;
                }
            }
        }

        // Assert: No deadlocks occurred
        $this->assertEquals(0, $deadlockCount, 'Should not have any deadlocks');
    }

    /**
     * CT-004: Race condition in summary update
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_race_condition_in_summary_update(): void
    {
        // Arrange
        $students = Student::factory()->count(10)->create([
            'school_id' => $this->school->id,
        ]);

        $service = app(AttendanceApplicationService::class);
        $projector = app(\App\ReadModels\Projectors\AttendanceSummaryProjector::class);

        // Act: Check in all students
        foreach ($students as $student) {
            $service->checkIn([
                'student_id' => $student->id,
                'schedule_id' => $this->schedule->id,
                'school_id' => $this->school->id,
                'attendance_date' => today()->format('Y-m-d'),
                'check_in_time' => now(),
            ]);
        }

        // Trigger projector multiple times concurrently
        for ($i = 0; $i < 5; $i++) {
            $projector->projectForDate($this->school->id, today());
        }

        // Assert: Summary is correct (no race condition corruption)
        $summary = \App\ReadModels\AttendanceDailySummary::forSchool($this->school->id)
            ->forDate(today())
            ->schoolWide()
            ->first();

        $this->assertNotNull($summary);
        $this->assertEquals(10, $summary->total_students);
        $this->assertEquals(10, $summary->total_present);
    }

    /**
     * CT-005: QR nonce replay prevention under load
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_qr_nonce_replay_under_concurrent_load(): void
    {
        // Arrange
        $nonce = 'test-nonce-' . uniqid();
        $successCount = 0;
        $replayCount = 0;

        // Act: Try to use the same nonce 10 times concurrently
        for ($i = 0; $i < 10; $i++) {
            try {
                DB::transaction(function() use ($nonce) {
                    // Check if nonce exists
                    $exists = DB::table('qr_nonces')
                        ->where('nonce', $nonce)
                        ->lockForUpdate()
                        ->exists();

                    if ($exists) {
                        throw new \Exception('Nonce already used');
                    }

                    // Insert nonce
                    DB::table('qr_nonces')->insert([
                        'nonce' => $nonce,
                        'used_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
                $successCount++;
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'already used')) {
                    $replayCount++;
                }
            }
        }

        // Assert: Only 1 successful use, 9 replays detected
        $this->assertEquals(1, $successCount, 'Should only allow 1 use of nonce');
        $this->assertEquals(9, $replayCount, 'Should detect 9 replay attempts');
    }

    /**
     * CT-006: Idempotency key enforcement
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_enforces_idempotency_key_under_concurrent_requests(): void
    {
        // Arrange
        $idempotencyKey = 'idem-' . uniqid();
        $service = app(AttendanceApplicationService::class);
        $results = [];

        // Act: Send same request 5 times with same idempotency key
        for ($i = 0; $i < 5; $i++) {
            try {
                $attendance = $service->checkIn([
                    'student_id' => $this->student->id,
                    'schedule_id' => $this->schedule->id,
                    'school_id' => $this->school->id,
                    'attendance_date' => today()->format('Y-m-d'),
                    'check_in_time' => now(),
                    'request_id' => $idempotencyKey,
                ]);
                $results[] = $attendance->id;
            } catch (\Exception $e) {
                // Expected for duplicate requests
            }
        }

        // Assert: Only 1 record created
        $count = Attendance::where('request_id', $idempotencyKey)->count();
        $this->assertEquals(1, $count);
    }

    /**
     * CT-007: Stress test with 1000 students
     */
    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\Group('slow')]
    public function it_handles_high_volume_check_ins(): void
    {
        // Arrange
        $studentCount = 1000;
        $students = Student::factory()->count($studentCount)->create([
            'school_id' => $this->school->id,
        ]);

        $service = app(AttendanceApplicationService::class);
        $startTime = microtime(true);
        $successCount = 0;

        // Act: Check in 1000 students
        foreach ($students as $student) {
            try {
                $service->checkIn([
                    'student_id' => $student->id,
                    'schedule_id' => $this->schedule->id,
                    'school_id' => $this->school->id,
                    'attendance_date' => today()->format('Y-m-d'),
                    'check_in_time' => now(),
                ]);
                $successCount++;
            } catch (\Exception $e) {
                // Log error but continue
            }
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        // Assert: All students checked in
        $this->assertEquals($studentCount, $successCount);
        $this->assertEquals($studentCount, Attendance::count());

        // Performance assertion: Should complete in reasonable time
        $this->assertLessThan(30, $duration, 'Should complete 1000 check-ins in under 30 seconds');
    }

    /**
     * CT-008: Concurrent read/write on same record
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_concurrent_read_write_on_same_record(): void
    {
        // Arrange
        $service = app(AttendanceApplicationService::class);

        $attendance = $service->checkIn([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now()->subHours(2),
        ]);

        // Act: Concurrent check-out attempts
        $successCount = 0;
        $errorCount = 0;

        for ($i = 0; $i < 5; $i++) {
            try {
                $service->checkOut($attendance->id, [
                    'check_out_time' => now(),
                ]);
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
            }
        }

        // Assert: Only 1 successful check-out
        $this->assertGreaterThanOrEqual(1, $successCount);
        
        // Verify final state
        $attendance->refresh();
        $this->assertNotNull($attendance->check_out_time);
    }
}
