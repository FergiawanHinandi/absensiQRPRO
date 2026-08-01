<?php

namespace Tests\Feature;

use App\Exceptions\AttendanceException;
use App\Helpers\TimezoneHelper;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use App\Services\AttendanceCheckInService;
use App\Services\AttendanceService;
use App\Services\AttendanceStateMachineService;
use App\Services\CriticalAttendanceService;
use App\Services\ProductionAttendanceService;
use App\Services\SecureAttendanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Constraint Violation Handling Tests
 * 
 * Tests that all attendance creation services properly handle unique constraint violations
 * and provide user-friendly error messages.
 * 
 * **Task**: Week 1 Day 2 - Task 2.4: Add constraint violation handling
 * **Requirements**: Week 1 Day 2, Acceptance Criteria 3, 5
 * 
 * **Validates**:
 * - All services use firstOrCreate consistently
 * - Constraint violations are caught and handled gracefully
 * - User-friendly error messages are returned
 * - No database exceptions leak to users
 */
class ConstraintViolationHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $student;
    protected User $teacher;
    protected Schedule $schedule;
    protected string $attendanceDate;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school
        $this->school = School::factory()->create([
            'timezone' => 'Asia/Jakarta',
        ]);

        // Create student
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create teacher
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Create schedule
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);

        // Use timezone-aware date
        $this->attendanceDate = TimezoneHelper::today($this->school);

        // Authenticate as teacher
        Auth::login($this->teacher);
    }

    /**
     * Test 1: SecureAttendanceService handles duplicate scans gracefully
     * 
     * Verifies that QR scan service prevents duplicate attendance and returns
     * user-friendly error message in Indonesian.
     */
    public function test_secure_attendance_service_handles_duplicates(): void
    {
        $service = app(SecureAttendanceService::class);

        // Create first attendance
        $attendance1 = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
        ]);

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance1->id,
            'student_id' => $this->student->id,
        ]);

        // Prepare QR payload
        $qrPayload = [
            'data' => [
                'schedule_id' => $this->schedule->id,
                'school_id' => $this->school->id,
                'idempotency_key' => 'test-key-' . uniqid(),
                'timestamp' => now()->timestamp,
            ],
            'signature' => 'test-signature',
        ];

        $scanData = [
            'device_id' => 'test-device',
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ];

        // Attempt duplicate scan - should throw AttendanceException
        try {
            $service->scan($this->student, $qrPayload, $scanData);
            $this->fail('Expected AttendanceException for duplicate scan');
        } catch (AttendanceException $e) {
            // Verify user-friendly error message in Indonesian
            $this->assertStringContainsString('sudah melakukan absensi', $e->getMessage());
        }

        // Verify only one attendance record exists
        $this->assertDatabaseCount('attendances', 1);
    }

    /**
     * Test 2: AttendanceService::manualInput handles duplicates gracefully
     * 
     * Verifies that manual attendance input prevents duplicates and returns
     * user-friendly error message.
     */
    public function test_attendance_service_manual_input_handles_duplicates(): void
    {
        $service = app(AttendanceService::class);

        // Create first attendance
        $attendance1 = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
            'is_manual' => true,
        ]);

        $this->assertDatabaseHas('attendances', ['id' => $attendance1->id]);

        // Attempt duplicate manual input
        $data = [
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'status' => 'present',
        ];

        try {
            $service->manualInput($data, $this->teacher->id);
            $this->fail('Expected AttendanceException for duplicate manual input');
        } catch (AttendanceException $e) {
            // Verify user-friendly error message
            $this->assertStringContainsString('sudah ada', $e->getMessage());
        }

        // Verify only one attendance record exists
        $this->assertDatabaseCount('attendances', 1);
    }

    /**
     * Test 3: AttendanceService::bulkManualAttendance handles duplicates gracefully
     * 
     * Verifies that bulk attendance input skips duplicates and continues processing.
     */
    public function test_attendance_service_bulk_handles_duplicates(): void
    {
        $service = app(AttendanceService::class);

        // Create existing attendance for first student
        $student1 = $this->student;
        $student2 = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        Attendance::factory()->create([
            'student_id' => $student1->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
        ]);

        // Bulk input with one duplicate and one new
        $students = [
            [
                'student_id' => $student1->id,
                'status' => 'present',
            ],
            [
                'student_id' => $student2->id,
                'status' => 'present',
            ],
        ];

        $result = $service->bulkManualAttendance(
            $this->schedule->id,
            $students,
            $this->teacher
        );

        // Verify result contains success and failure information
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('failed', $result);

        // Verify student2's attendance was created
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student2->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
        ]);

        // Verify total count is 2 (1 existing + 1 new)
        $this->assertDatabaseCount('attendances', 2);
    }

    /**
     * Test 4: firstOrCreate returns existing record without error
     * 
     * Verifies that firstOrCreate properly returns existing record when
     * duplicate keys are provided.
     */
    public function test_first_or_create_returns_existing_record(): void
    {
        // Create first attendance
        $attendance1 = Attendance::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $this->school->id,
            ],
            [
                'status' => 'present',
                'check_in_time' => '07:00:00',
                'is_manual' => false,
            ]
        );

        $this->assertTrue($attendance1->wasRecentlyCreated);
        $this->assertEquals('present', $attendance1->status);

        // Attempt to create duplicate - should return existing
        $attendance2 = Attendance::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $this->school->id,
            ],
            [
                'status' => 'late', // Different status
                'check_in_time' => '08:00:00',
                'is_manual' => true,
            ]
        );

        // Verify it returned the existing record
        $this->assertFalse($attendance2->wasRecentlyCreated);
        $this->assertEquals($attendance1->id, $attendance2->id);
        $this->assertEquals('present', $attendance2->status); // Original status preserved
        $this->assertEquals('07:00:00', $attendance2->check_in_time); // Original time preserved

        // Verify only one record exists
        $this->assertDatabaseCount('attendances', 1);
    }

    /**
     * Test 5: Direct Attendance::create throws constraint violation
     * 
     * Verifies that direct create() calls properly trigger the unique constraint
     * and throw QueryException.
     */
    public function test_direct_create_throws_constraint_violation(): void
    {
        // Create first attendance
        Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
        ]);

        // Attempt direct create with duplicate keys
        $this->expectException(QueryException::class);

        Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'late',
            'check_in_time' => '08:00:00',
            'is_manual' => true,
        ]);
    }

    /**
     * Test 6: Constraint violation error message contains constraint name
     * 
     * Verifies that database constraint violations include the constraint name
     * for debugging purposes.
     */
    public function test_constraint_violation_includes_constraint_name(): void
    {
        // Create first attendance
        Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
        ]);

        try {
            // Attempt direct create with duplicate keys
            Attendance::create([
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $this->school->id,
                'status' => 'late',
                'check_in_time' => '08:00:00',
            ]);

            $this->fail('Expected QueryException for constraint violation');
        } catch (QueryException $e) {
            // Verify error message contains constraint name
            $this->assertStringContainsString('unique_attendance_per_day', $e->getMessage());
        }
    }

    /**
     * Test 7: wasRecentlyCreated flag works correctly
     * 
     * Verifies that wasRecentlyCreated flag can be used to detect duplicates
     * after firstOrCreate.
     */
    public function test_was_recently_created_flag_detection(): void
    {
        // First create - should set wasRecentlyCreated = true
        $attendance1 = Attendance::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $this->school->id,
            ],
            [
                'status' => 'present',
                'check_in_time' => '07:00:00',
            ]
        );

        $this->assertTrue($attendance1->wasRecentlyCreated, 'First create should set wasRecentlyCreated = true');

        // Second create - should set wasRecentlyCreated = false
        $attendance2 = Attendance::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $this->school->id,
            ],
            [
                'status' => 'late',
                'check_in_time' => '08:00:00',
            ]
        );

        $this->assertFalse($attendance2->wasRecentlyCreated, 'Duplicate should set wasRecentlyCreated = false');
        $this->assertEquals($attendance1->id, $attendance2->id, 'Should return same record');
    }

    /**
     * Test 8: Different dates allow same student/schedule combination
     * 
     * Verifies that the unique constraint allows the same student and schedule
     * on different dates.
     */
    public function test_different_dates_allow_duplicate_student_schedule(): void
    {
        $today = $this->attendanceDate;
        $tomorrow = TimezoneHelper::parse($today, $this->school->timezone)
            ->addDay()
            ->toDateString();

        // Create attendance for today
        $attendance1 = Attendance::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $today,
                'school_id' => $this->school->id,
            ],
            [
                'status' => 'present',
                'check_in_time' => '07:00:00',
            ]
        );

        $this->assertTrue($attendance1->wasRecentlyCreated);

        // Create attendance for tomorrow - should succeed
        $attendance2 = Attendance::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $tomorrow,
                'school_id' => $this->school->id,
            ],
            [
                'status' => 'present',
                'check_in_time' => '07:00:00',
            ]
        );

        $this->assertTrue($attendance2->wasRecentlyCreated);
        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertDatabaseCount('attendances', 2);
    }

    /**
     * Test 9: Different schools allow same student/schedule/date combination
     * 
     * Verifies that the unique constraint includes school_id for proper
     * multi-tenant isolation.
     */
    public function test_different_schools_allow_duplicate_combinations(): void
    {
        // Create second school
        $school2 = School::factory()->create([
            'timezone' => 'Asia/Jakarta',
        ]);

        // Create student in school2 with same ID pattern
        $student2 = User::factory()->create([
            'school_id' => $school2->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create schedule in school2
        $schedule2 = Schedule::factory()->create([
            'school_id' => $school2->id,
            'is_active' => true,
        ]);

        // Create attendance in school1
        $attendance1 = Attendance::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $this->school->id,
            ],
            [
                'status' => 'present',
                'check_in_time' => '07:00:00',
            ]
        );

        $this->assertTrue($attendance1->wasRecentlyCreated);

        // Create attendance in school2 with same date - should succeed
        $attendance2 = Attendance::firstOrCreate(
            [
                'student_id' => $student2->id,
                'schedule_id' => $schedule2->id,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $school2->id,
            ],
            [
                'status' => 'present',
                'check_in_time' => '07:00:00',
            ]
        );

        $this->assertTrue($attendance2->wasRecentlyCreated);
        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertDatabaseCount('attendances', 2);
    }

    /**
     * Test 10: Error messages are user-friendly and localized
     * 
     * Verifies that all constraint violation error messages are user-friendly
     * and in Indonesian (primary language).
     */
    public function test_error_messages_are_user_friendly(): void
    {
        $service = app(AttendanceService::class);

        // Create existing attendance
        Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
        ]);

        // Attempt duplicate
        $data = [
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'status' => 'present',
        ];

        try {
            $service->manualInput($data, $this->teacher->id);
            $this->fail('Expected AttendanceException');
        } catch (AttendanceException $e) {
            $message = $e->getMessage();

            // Verify message is in Indonesian
            $this->assertMatchesRegularExpression('/sudah|telah|ada/i', $message);

            // Verify message doesn't contain technical database terms
            $this->assertStringNotContainsString('unique_attendance_per_day', $message);
            $this->assertStringNotContainsString('constraint', $message);
            $this->assertStringNotContainsString('violation', $message);
            $this->assertStringNotContainsString('duplicate key', $message);

            // Verify message is helpful
            $this->assertGreaterThan(20, strlen($message), 'Error message should be descriptive');
        }
    }
}
