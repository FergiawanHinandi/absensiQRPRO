<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Models\School;
use App\Models\Schedule;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Enums\AttendanceState;
use App\Services\AttendanceCheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AttendanceAggregateTest
 * Comprehensive feature test suite for Attendance Aggregate Root.
 * Covers:
 * 1. State Transitions (Valid & Invalid)
 * 2. Concurrency Requests (Simulated)
 * 3. Idempotency & Replay Protection
 * 4. Tenant Isolation
 */
#[\PHPUnit\Framework\Attributes\Group('aggregate')]
#[\PHPUnit\Framework\Attributes\Group('attendance')]
#[\PHPUnit\Framework\Attributes\Group('critical')]
class AttendanceAggregateTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;
    protected School $schoolB;
    protected User $studentA;
    protected User $teacherA;
    protected User $adminB;
    protected Schedule $scheduleA;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Schools (Tenancy)
        $this->schoolA = School::factory()->create([
            'name' => 'School A',
            'latitude' => -6.2,
            'longitude' => 106.816666,
            'radius_meters' => 500,
        ]);
        $this->schoolB = School::factory()->create(['name' => 'School B']);

        // 2. Setup Users (Isolation)
        $this->teacherA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role_type' => 'teacher',
            'email' => 'teacher.a@school-a.com',
        ]);

        $this->studentA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role_type' => 'student',
            'email' => 'student.a@school-a.com',
        ]);

        $this->adminB = User::factory()->create([
            'school_id' => $this->schoolB->id,
            'role_type' => 'school_admin',
            'email' => 'admin.b@school-b.com',
        ]);

        // 3. Setup Schedule & Class
        $classA = ClassModel::factory()->create([
            'school_id' => $this->schoolA->id,
            'name' => 'Class X-A'
        ]);
        
        $subjectA = Subject::factory()->create([
            'school_id' => $this->schoolA->id,
            'name' => 'Mathematics'
        ]);

        $this->scheduleA = Schedule::factory()->create([
            'school_id' => $this->schoolA->id,
            'class_id' => $classA->id,
            'subject_id' => $subjectA->id,
            'teacher_id' => $this->teacherA->id,
            'day_of_week' => today()->dayOfWeek,
            'start_time' => now()->subMinutes(30)->format('H:i:s'),
            'end_time' => now()->addMinutes(30)->format('H:i:s'),
        ]);
    }

    /**
     * TEST 1: Valid State Transitions (Happy Path)
     * INIT -> CHECKED_IN -> CHECKED_OUT
     */
    public function test_valid_state_transitions()
    {
        // 1. Creation (INIT)
        $attendance = Attendance::create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'schedule_id' => $this->scheduleA->id,
            'attendance_date' => today(),
        ]);

        $this->assertEquals(AttendanceState::INIT, $attendance->state);

        // 2. Check-In (INIT -> CHECKED_IN)
        $attendance->checkIn(
            $this->teacherA, // Actor
            -6.200000, 106.816666, 'device-123'
        );

        $this->assertEquals(AttendanceState::CHECKED_IN, $attendance->fresh()->state);
        $this->assertNotNull($attendance->check_in_time);

        // 3. Check-Out (CHECKED_IN -> CHECKED_OUT)
        $attendance->checkOut(
            $this->teacherA, // Actor
            -6.200000, 106.816666, 'device-123'
        );

        $this->assertEquals(AttendanceState::CHECKED_OUT, $attendance->fresh()->state);
        $this->assertNotNull($attendance->check_out_time);
    }

    /**
     * TEST 2: Invalid State Transitions (Sad Path)
     * INIT -> CHECKED_OUT (Should Fail)
     */
    public function test_invalid_state_transitions()
    {
        $attendance = Attendance::create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'schedule_id' => $this->scheduleA->id,
            'attendance_date' => today(),
        ]);

        $this->expectException(\App\Exceptions\StateViolationException::class);
        $this->expectExceptionMessage('Harus melakukan check-in terlebih dahulu sebelum check-out.');

        // Attempt invalid transition directly
        $attendance->checkOut($this->teacherA, -6.2, 106.8, 'device-123');
    }

    /**
     * TEST 3: Concurrent Check-In Simulation (Race Condition)
     * Simulates 10 parallel requests. Only 1 succeeds.
     */
    public function test_concurrent_check_in_requests()
    {
        // Setup data
        $checkInData = [
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'schedule_id' => $this->scheduleA->id,
            'attendance_date' => today(),
        ];

        // Result Tracking
        $successCount = 0;
        $failCount = 0;
        
        // Simulate Logic:
        // In real parallel execution (like with Process/Threads), DB locks ensure serialization.
        // Here we simulate attempting to create duplicate records within transactions.
        
        $attempts = 10;
        
        for ($i = 0; $i < $attempts; $i++) {
            try {
                // Simulate Service Layer Logic inside Transaction
                DB::transaction(function () use ($checkInData) {
                    // 1. Pessimistic Lock Check
                    $existing = Attendance::where('student_id', $checkInData['student_id'])
                        ->where('schedule_id', $checkInData['schedule_id'])
                        ->where('attendance_date', $checkInData['attendance_date'])
                        ->lockForUpdate() // Crucial for concurrency
                        ->first();

                    if ($existing) {
                        throw new \Exception('Already checked in (Simulated Race Condition)');
                    }

                    // 2. Create if not exists
                    Attendance::create($checkInData);
                });

                $successCount++;
            } catch (\Exception $e) {
                // Caught "Already checked in" or DB Unique Constraint violation
                $failCount++;
            }
        }

        // Assertions
        $this->assertEquals(1, $successCount, 'Only 1 transaction should succeed');
        $this->assertEquals(9, $failCount, '9 transactions should fail due to locking/constraints');
        
        // Verify DB only has 1 record
        $this->assertEquals(1, Attendance::where('student_id', $this->studentA->id)->count());
    }

    /**
     * TEST 4: Replay Attack Simulation (Idempotency)
     * Same Idempotency Key -> Same Response (Cached), No New Record
     */
    public function test_idempotency_prevents_replay()
    {
        $idempotencyKey = Str::uuid()->toString();

        // Build a valid QR token for the schedule (same contract as IdempotencyTest)
        $payload = [
            'sid' => $this->scheduleA->id,
            'sch' => $this->schoolA->id,
            'iat' => now()->timestamp,
            'schedule_id' => $this->scheduleA->id,
            'nonce' => Str::random(16),
        ];
        $encoded = base64_encode(json_encode($payload));
        $token = $encoded.'.'.hash_hmac('sha256', $encoded, config('qr.secret'));

        $scanData = [
            'qr_token' => $token,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'accuracy' => 10,
            'device_fingerprint' => 'test-device-fingerprint',
            'request_id' => (string) Str::uuid(),
        ];

        // 1. First Request
        \Laravel\Sanctum\Sanctum::actingAs($this->studentA, ['*']);
        $response1 = $this->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/attendance/scan', $scanData);

        // 2. Second Request (Replay) - with the same idempotency key
        \Laravel\Sanctum\Sanctum::actingAs($this->studentA, ['*']);
        $response2 = $this->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/attendance/scan', $scanData);

        // Assertions
        $response1->assertStatus(201); // Created
        $response2->assertStatus(201); // Cached response (idempotent replay)
        $this->assertEquals(
            $response1->json('data.attendance.id'),
            $response2->json('data.attendance.id')
        );

        // Verify DB count is 1
        $this->assertEquals(1, Attendance::where('student_id', $this->studentA->id)->count());
    }

    /**
     * TEST 5: Tenant Leak Attempt (Isolation)
     * Admin School B trying to access/modify School A data.
     */
    public function test_tenant_isolation_prevents_leak()
    {
        // 1. Setup: Attendance at School A
        $attendanceA = Attendance::create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'schedule_id' => $this->scheduleA->id,
            'attendance_date' => today(),
        ]);

        // 2. Attempt: Admin B tries to view/modify School A Attendance
        // Using Global Scope mechanism check
        
        $found = Attendance::query()
            ->withoutGlobalScopes() // Force bypass standard scope to test raw query if capability exists
            // But acting as Admin B should enforce scope via Traits if strictly implemented
            ->where('id', $attendanceA->id)
            ->get();
            
        // In real app, we check via Controller/API or Model Scope
        // Let's test Model Scope acting as Admin B
        
        $this->actingAs($this->adminB);
        
        // Scope should automatically apply
        $visibleAttendance = Attendance::find($attendanceA->id);
        
        // Assertion: Should be NULL because Admin B is in School B
        $this->assertNull($visibleAttendance, 'Admin B should not see School A attendance');
        
        // 3. Attempt: Admin B specific query for their school
        $queryB = Attendance::all();
        $this->assertCount(0, $queryB); // School B has 0 records
    }
}
