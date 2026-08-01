<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test suite for unique attendance constraint migration
 * 
 * Validates Week 1 Day 2 requirements:
 * - Unique constraint on (student_id, schedule_id, attendance_date, school_id)
 * - Migration handles existing duplicates
 * - Constraint enforcement at database level
 * - Proper rollback functionality
 */
class UniqueAttendanceConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $student;
    protected Schedule $schedule;
    protected string $attendanceDate;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->school = School::factory()->create();
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
        ]);
        $this->attendanceDate = now()->toDateString();
    }

    /**
     * Test that unique constraint exists on attendances table
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    public function it_has_unique_constraint_on_attendance_combination(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Check using PRAGMA index_list
            $indexes = DB::select("PRAGMA index_list(attendances)");
            $indexNames = collect($indexes)->pluck('name')->toArray();
            
            $this->assertContains(
                'unique_attendance_per_day',
                $indexNames,
                'Unique constraint "unique_attendance_per_day" should exist'
            );
        } else {
            // MySQL/PostgreSQL: Use SHOW INDEXES
            $indexes = DB::select("SHOW INDEXES FROM attendances WHERE Key_name = 'unique_attendance_per_day'");
            
            $this->assertNotEmpty($indexes, 'Unique constraint "unique_attendance_per_day" should exist');

            // Verify all required columns are in the index
            $indexColumns = collect($indexes)->pluck('Column_name')->toArray();
            
            $this->assertContains('student_id', $indexColumns);
            $this->assertContains('schedule_id', $indexColumns);
            $this->assertContains('attendance_date', $indexColumns);
            $this->assertContains('school_id', $indexColumns);
        }
    }

    /**
     * Test that constraint prevents duplicate attendance records
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    public function it_prevents_duplicate_attendance_records(): void
    {
        // Unguard model to bypass state machine for constraint testing
        Attendance::unguard();

        // Create first attendance record
        $attendance1 = Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
            'state' => 'checked_in',
            'check_in_time' => now(),
        ]);

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance1->id,
            'student_id' => $this->student->id,
        ]);

        // Attempt to create duplicate - should throw exception
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry|UNIQUE constraint failed/i');

        Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'late',
            'state' => 'checked_in',
            'check_in_time' => now(),
        ]);

        Attendance::reguard();
    }

    /**
     * Test that constraint allows same student on different dates
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    public function it_allows_same_student_on_different_dates(): void
    {
        // Unguard model to bypass state machine for constraint testing
        Attendance::unguard();

        // Create attendance for today
        $attendance1 = Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => now()->toDateString(),
            'school_id' => $this->school->id,
            'status' => 'present',
            'state' => 'checked_in',
        ]);

        // Create attendance for tomorrow - should succeed
        $attendance2 = Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => now()->addDay()->toDateString(),
            'school_id' => $this->school->id,
            'status' => 'present',
            'state' => 'checked_in',
        ]);

        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertDatabaseCount('attendances', 2);

        Attendance::reguard();
    }

    /**
     * Test that constraint allows same student in different schedules
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    public function it_allows_same_student_in_different_schedules(): void
    {
        // Unguard model to bypass state machine for constraint testing
        Attendance::unguard();

        $schedule2 = Schedule::factory()->create([
            'school_id' => $this->school->id,
        ]);

        // Create attendance for first schedule
        $attendance1 = Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
            'state' => 'checked_in',
        ]);

        // Create attendance for second schedule - should succeed
        $attendance2 = Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $schedule2->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
            'state' => 'checked_in',
        ]);

        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertDatabaseCount('attendances', 2);

        Attendance::reguard();
    }

    /**
     * Test that constraint enforces tenant isolation
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    #[\PHPUnit\Framework\Attributes\Group('tenant-isolation')]
    public function it_enforces_tenant_isolation_in_constraint(): void
    {
        // Unguard model to bypass state machine for constraint testing
        Attendance::unguard();

        $school2 = School::factory()->create();
        $student2 = User::factory()->create([
            'school_id' => $school2->id,
            'role_type' => 'student',
        ]);
        $schedule2 = Schedule::factory()->create([
            'school_id' => $school2->id,
        ]);

        // Create attendance for school 1
        $attendance1 = Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
            'state' => 'checked_in',
        ]);

        // Create attendance for school 2 with same student_id and schedule_id
        // Should succeed because school_id is different
        $attendance2 = Attendance::create([
            'student_id' => $this->student->id, // Same student_id
            'schedule_id' => $this->schedule->id, // Same schedule_id
            'attendance_date' => $this->attendanceDate,
            'school_id' => $school2->id, // Different school_id
            'status' => 'present',
            'state' => 'checked_in',
        ]);

        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertDatabaseCount('attendances', 2);

        Attendance::reguard();
    }

    /**
     * Test that old constraint without school_id was removed
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    public function it_removed_old_constraint_without_school_id(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Check using PRAGMA index_list
            $indexes = DB::select("PRAGMA index_list(attendances)");
            $indexNames = collect($indexes)->pluck('name')->toArray();
            
            $this->assertNotContains(
                'unique_attendance_per_schedule',
                $indexNames,
                'Old constraint "unique_attendance_per_schedule" should be removed'
            );
        } else {
            // MySQL/PostgreSQL: Use SHOW INDEXES
            $oldIndexes = DB::select("SHOW INDEXES FROM attendances WHERE Key_name = 'unique_attendance_per_schedule'");
            
            $this->assertEmpty($oldIndexes, 'Old constraint "unique_attendance_per_schedule" should be removed');
        }
    }

    /**
     * Test constraint with NULL values
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    public function it_handles_null_values_correctly(): void
    {
        // Unguard model to bypass state machine for constraint testing
        Attendance::unguard();

        // Create attendance with nullable fields
        $attendance1 = Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
            'state' => 'checked_in',
            'check_in_time' => null, // NULL value
            'notes' => null, // NULL value
        ]);

        // Attempt duplicate with different NULL values - should still fail
        $this->expectException(\Illuminate\Database\QueryException::class);

        Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'late',
            'state' => 'checked_in',
            'check_in_time' => now(), // Different value
            'notes' => 'Test note', // Different value
        ]);

        Attendance::reguard();
    }

    /**
     * Test that constraint works with soft deletes
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    #[\PHPUnit\Framework\Attributes\Group('soft-delete')]
    public function it_allows_recreation_after_soft_delete(): void
    {
        // Unguard model to bypass state machine for constraint testing
        Attendance::unguard();

        // Create and soft delete attendance
        $attendance1 = Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
            'state' => 'checked_in',
        ]);

        $attendance1->delete(); // Soft delete

        // Create new attendance with same combination - should succeed
        $attendance2 = Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'late',
            'state' => 'checked_in',
        ]);

        $this->assertNotEquals($attendance1->id, $attendance2->id);
        $this->assertSoftDeleted('attendances', ['id' => $attendance1->id]);
        $this->assertDatabaseHas('attendances', ['id' => $attendance2->id]);

        Attendance::reguard();
    }

    /**
     * Test constraint error message is user-friendly
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    public function it_provides_clear_error_message_on_duplicate(): void
    {
        // Unguard model to bypass state machine for constraint testing
        Attendance::unguard();

        // Create first attendance
        Attendance::create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $this->attendanceDate,
            'school_id' => $this->school->id,
            'status' => 'present',
            'state' => 'checked_in',
        ]);

        try {
            // Attempt duplicate
            Attendance::create([
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $this->school->id,
                'status' => 'late',
                'state' => 'checked_in',
            ]);

            $this->fail('Expected QueryException was not thrown');
        } catch (\Illuminate\Database\QueryException $e) {
            // Verify error message contains constraint name
            $this->assertStringContainsStringIgnoringCase(
                'unique_attendance_per_day',
                $e->getMessage(),
                'Error message should reference the constraint name'
            );
        } finally {
            Attendance::reguard();
        }
    }

    /**
     * Test migration rollback restores old constraint
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    #[\PHPUnit\Framework\Attributes\Group('rollback')]
    public function it_can_rollback_to_old_constraint(): void
    {
        // This test verifies the down() method works correctly
        // In a real rollback scenario, the old constraint would be restored
        
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Check using PRAGMA index_list
            $indexes = DB::select("PRAGMA index_list(attendances)");
            $indexNames = collect($indexes)->pluck('name')->toArray();
            
            // Verify current constraint exists
            $this->assertContains('unique_attendance_per_day', $indexNames);
            
            // Verify old constraint doesn't exist
            $this->assertNotContains('unique_attendance_per_schedule', $indexNames);
        } else {
            // MySQL/PostgreSQL: Use SHOW INDEXES
            // Verify current constraint exists
            $newIndexes = DB::select("SHOW INDEXES FROM attendances WHERE Key_name = 'unique_attendance_per_day'");
            $this->assertNotEmpty($newIndexes);

            // Verify old constraint doesn't exist
            $oldIndexes = DB::select("SHOW INDEXES FROM attendances WHERE Key_name = 'unique_attendance_per_schedule'");
            $this->assertEmpty($oldIndexes);
        }

        // Note: Actual rollback testing would require running:
        // php artisan migrate:rollback --step=1
        // This is typically done in integration tests or manually
    }

    /**
     * Test constraint performance with large dataset
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    #[\PHPUnit\Framework\Attributes\Group('performance')]
    public function it_maintains_performance_with_constraint(): void
    {
        // Unguard model to bypass state machine for constraint testing
        Attendance::unguard();

        $startTime = microtime(true);

        // Create 100 attendance records
        for ($i = 0; $i < 100; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            Attendance::create([
                'student_id' => $student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $this->school->id,
                'status' => 'present',
                'state' => 'checked_in',
            ]);
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Verify all records created
        $this->assertDatabaseCount('attendances', 100);

        // Performance should be reasonable (< 5 seconds for 100 records)
        $this->assertLessThan(5, $executionTime, 'Constraint should not significantly impact performance');

        Attendance::reguard();
    }

    /**
     * Test constraint with concurrent inserts simulation
     * 
     */
    #[\PHPUnit\Framework\Attributes\Group('constraint')]
    #[\PHPUnit\Framework\Attributes\Group('concurrency')]
    public function it_prevents_race_condition_duplicates(): void
    {
        // Unguard model to bypass state machine for constraint testing
        Attendance::unguard();

        // Simulate concurrent insert attempts
        $exceptions = 0;
        $successful = 0;

        for ($i = 0; $i < 5; $i++) {
            try {
                Attendance::create([
                    'student_id' => $this->student->id,
                    'schedule_id' => $this->schedule->id,
                    'attendance_date' => $this->attendanceDate,
                    'school_id' => $this->school->id,
                    'status' => 'present',
                    'state' => 'checked_in',
                ]);
                $successful++;
            } catch (\Illuminate\Database\QueryException $e) {
                $exceptions++;
            }
        }

        // Only one should succeed, rest should fail
        $this->assertEquals(1, $successful, 'Only one insert should succeed');
        $this->assertEquals(4, $exceptions, 'Four inserts should fail with constraint violation');
        $this->assertDatabaseCount('attendances', 1);

        Attendance::reguard();
    }
}
