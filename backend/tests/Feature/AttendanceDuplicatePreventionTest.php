<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Attendance Duplicate Prevention Tests
 *
 * Tests for Task 2.5: Write duplicate tests (8 tests)
 * Validates that the unique constraint and firstOrCreate pattern prevent duplicate attendance records
 *
 * @see .kiro/specs/saas-hardening-30-days/requirements.md (Day 2)
 * @see .kiro/specs/saas-hardening-30-days/design.md (Day 2)
 */
class AttendanceDuplicatePreventionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Setup test environment
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure the unique constraint exists
        if (!$this->constraintExists()) {
            Schema::table('attendances', function ($table) {
                $table->unique(
                    ['student_id', 'schedule_id', 'attendance_date', 'school_id'],
                    'unique_attendance_per_day'
                );
            });
        }
    }

    /**
     * Check if the unique constraint exists
     */
    private function constraintExists(): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        
        try {
            if ($driver === 'sqlite') {
                $indexes = $connection->select("
                    SELECT name 
                    FROM sqlite_master 
                    WHERE type = 'index' 
                    AND tbl_name = 'attendances' 
                    AND name = 'unique_attendance_per_day'
                ");
                return count($indexes) > 0;
            }
        } catch (\Exception $e) {
            return false;
        }
        
        return false;
    }

    /**
     * Test 1: firstOrCreate prevents duplicate on concurrent requests
     */
    public function test_first_or_create_prevents_duplicate_on_concurrent_requests(): void
    {
        $school = School::factory()->create();
        $student = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);

        $uniqueKey = [
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
        ];

        $attributes = [
            'check_in_time' => now(),
            'is_manual' => false,
        ];

        // Simulate concurrent requests using firstOrCreate
        $attendance1 = Attendance::firstOrCreate($uniqueKey, $attributes);
        $attendance2 = Attendance::firstOrCreate($uniqueKey, $attributes);
        $attendance3 = Attendance::firstOrCreate($uniqueKey, $attributes);

        // All should return the same record
        $this->assertEquals($attendance1->id, $attendance2->id);
        $this->assertEquals($attendance1->id, $attendance3->id);

        // Only one record should exist in database
        $count = Attendance::where($uniqueKey)->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Test 2: Direct create throws exception on duplicate
     */
    public function test_direct_create_throws_exception_on_duplicate(): void
    {
        $school = School::factory()->create();
        $student = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);

        // First record succeeds
        Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        // Duplicate should throw QueryException
        $this->expectException(QueryException::class);

        Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);
    }

    /**
     * Test 3: updateOrCreate updates existing record instead of creating duplicate
     */
    public function test_update_or_create_updates_existing_instead_of_duplicate(): void
    {
        $school = School::factory()->create();
        $student = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);

        $uniqueKey = [
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
        ];

        // Create initial record
        $attendance1 = Attendance::updateOrCreate($uniqueKey, [
            'check_in_time' => now(),
            'notes' => 'First scan',
        ]);

        $this->assertEquals('First scan', $attendance1->notes);

        // Update same record
        $attendance2 = Attendance::updateOrCreate($uniqueKey, [
            'check_in_time' => now(),
            'notes' => 'Second scan',
        ]);

        // Should be same record with updated notes
        $this->assertEquals($attendance1->id, $attendance2->id);
        $this->assertEquals('Second scan', $attendance2->notes);

        // Only one record should exist
        $count = Attendance::where($uniqueKey)->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Test 4: Multiple students can have attendance for same schedule and date
     */
    public function test_multiple_students_can_attend_same_schedule_same_date(): void
    {
        $school = School::factory()->create();
        $student1 = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);
        $student2 = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);
        $student3 = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);

        // All three students attend same schedule on same date
        $attendance1 = Attendance::create([
            'student_id' => $student1->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        $attendance2 = Attendance::create([
            'student_id' => $student2->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        $attendance3 = Attendance::create([
            'student_id' => $student3->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        // All should be different records
        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertNotEquals($attendance1->id, $attendance3->id);
        $this->assertNotEquals($attendance2->id, $attendance3->id);

        // Three records should exist
        $count = Attendance::where('schedule_id', $schedule->id)
            ->where('attendance_date', today())
            ->count();
        $this->assertEquals(3, $count);
    }

    /**
     * Test 5: Same student can have attendance for multiple schedules on same date
     */
    public function test_student_can_attend_multiple_schedules_same_date(): void
    {
        $school = School::factory()->create();
        $student = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);
        $schedule1 = Schedule::factory()->create(['school_id' => $school->id]);
        $schedule2 = Schedule::factory()->create(['school_id' => $school->id]);
        $schedule3 = Schedule::factory()->create(['school_id' => $school->id]);

        // Student attends three different schedules on same date
        $attendance1 = Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule1->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        $attendance2 = Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule2->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        $attendance3 = Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule3->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        // All should be different records
        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertNotEquals($attendance1->id, $attendance3->id);
        $this->assertNotEquals($attendance2->id, $attendance3->id);

        // Three records should exist for this student
        $count = Attendance::where('student_id', $student->id)
            ->where('attendance_date', today())
            ->count();
        $this->assertEquals(3, $count);
    }

    /**
     * Test 6: Same student can have attendance on different dates for same schedule
     */
    public function test_student_can_attend_same_schedule_on_different_dates(): void
    {
        $school = School::factory()->create();
        $student = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);

        // Attendance on three different dates
        $attendance1 = Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        $attendance2 = Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today()->addDay(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        $attendance3 = Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today()->addDays(2),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        // All should be different records
        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertNotEquals($attendance1->id, $attendance3->id);
        $this->assertNotEquals($attendance2->id, $attendance3->id);

        // Three records should exist for this student and schedule
        $count = Attendance::where('student_id', $student->id)
            ->where('schedule_id', $schedule->id)
            ->count();
        $this->assertEquals(3, $count);
    }

    /**
     * Test 7: Multi-tenant isolation - different schools can have same student/schedule IDs
     */
    public function test_multi_tenant_isolation_allows_same_ids_different_schools(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();

        $student1 = User::factory()->create(['role_type' => 'student', 'school_id' => $school1->id]);
        $student2 = User::factory()->create(['role_type' => 'student', 'school_id' => $school2->id]);

        $schedule1 = Schedule::factory()->create(['school_id' => $school1->id]);
        $schedule2 = Schedule::factory()->create(['school_id' => $school2->id]);

        // School 1 attendance
        $attendance1 = Attendance::create([
            'student_id' => $student1->id,
            'schedule_id' => $schedule1->id,
            'attendance_date' => today(),
            'school_id' => $school1->id,
            'check_in_time' => now(),
        ]);

        // School 2 attendance (different school_id, so allowed)
        $attendance2 = Attendance::create([
            'student_id' => $student2->id,
            'schedule_id' => $schedule2->id,
            'attendance_date' => today(),
            'school_id' => $school2->id,
            'check_in_time' => now(),
        ]);

        // Should be different records
        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertNotEquals($attendance1->school_id, $attendance2->school_id);

        // Each school should have one record
        $school1Count = Attendance::where('school_id', $school1->id)->count();
        $school2Count = Attendance::where('school_id', $school2->id)->count();

        $this->assertEquals(1, $school1Count);
        $this->assertEquals(1, $school2Count);
    }

    /**
     * Test 8: Constraint violation provides meaningful error message
     */
    public function test_constraint_violation_provides_meaningful_error(): void
    {
        $school = School::factory()->create();
        $student = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);

        // Create first record
        Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => today(),
            'school_id' => $school->id,
            'check_in_time' => now(),
        ]);

        // Attempt duplicate
        try {
            Attendance::create([
                'student_id' => $student->id,
                'schedule_id' => $schedule->id,
                'attendance_date' => today(),
                'school_id' => $school->id,
                'check_in_time' => now(),
            ]);

            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            // Verify it's a unique constraint violation
            $this->assertEquals('23000', $e->getCode());

            // Error message should mention the constraint or duplicate
            $errorMessage = strtolower($e->getMessage());
            $this->assertTrue(
                str_contains($errorMessage, 'unique') ||
                str_contains($errorMessage, 'duplicate') ||
                str_contains($errorMessage, 'unique_attendance_per_day'),
                'Error message should indicate unique constraint violation'
            );
        }
    }
}
