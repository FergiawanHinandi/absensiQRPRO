<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for Attendance Unique Constraint with Soft Delete Support
 *
 * BUSINESS RULES TESTED:
 * 1. Active duplicate check-in → MUST FAIL
 * 2. Active duplicate check-out → MUST FAIL
 * 3. Insert after soft delete → MUST SUCCEED
 * 4. Multiple soft-deleted records → MUST BE ALLOWED
 */
class AttendanceUniqueConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $teacher;
    protected User $student;
    protected ClassModel $class;
    protected Schedule $schedule;
    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->school = School::factory()->create();

        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);

        $this->class = ClassModel::factory()->create([
            'school_id' => $this->school->id,
        ]);

        $this->subject = Subject::factory()->create([
            'school_id' => $this->school->id,
        ]);

        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);
    }

    /**
     * Test 1: Insert duplicate active attendance → MUST FAIL
     *
     * @test
     */
    public function it_prevents_duplicate_active_check_in(): void
    {
        // Create first attendance (check-in)
        $attendance1 = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'in',
            'status' => 'present',
            'check_in_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);

        $this->assertNotNull($attendance1->id);

        // Attempt duplicate check-in → MUST FAIL
        $this->expectException(QueryException::class);

        Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'in', // Same type
            'status' => 'present',
            'check_in_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);
    }

    /**
     * Test 2: Insert duplicate active check-out → MUST FAIL
     *
     * @test
     */
    public function it_prevents_duplicate_active_check_out(): void
    {
        // Create check-in first
        Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'in',
            'status' => 'present',
            'check_in_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);

        // Create first check-out
        $checkout1 = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'out', // Check-out type
            'status' => 'present',
            'check_out_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);

        $this->assertNotNull($checkout1->id);

        // Attempt duplicate check-out → MUST FAIL
        $this->expectException(QueryException::class);

        Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'out', // Same type
            'status' => 'present',
            'check_out_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);
    }

    /**
     * Test 3: Insert after soft delete → MUST SUCCEED
     *
     * @test
     */
    public function it_allows_insert_after_soft_delete(): void
    {
        // Create first attendance
        $attendance1 = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'in',
            'status' => 'present',
            'check_in_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);

        // Soft delete the attendance (simulating correction)
        $attendance1->delete();

        // Verify it's soft deleted
        $this->assertSoftDeleted('attendances', ['id' => $attendance1->id]);

        // Create new attendance with same unique key → MUST SUCCEED
        $attendance2 = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'in', // Same combination
            'status' => 'late', // Different status (correction)
            'check_in_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);

        $this->assertNotNull($attendance2->id);
        $this->assertNotEquals($attendance1->id, $attendance2->id);

        // Verify both records exist
        $this->assertEquals(2, Attendance::withTrashed()
            ->where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->whereDate('attendance_date', today())
            ->where('attendance_type', 'in')
            ->count()
        );

        // Only one active record
        $this->assertEquals(1, Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->whereDate('attendance_date', today())
            ->where('attendance_type', 'in')
            ->count()
        );
    }

    /**
     * Test 4: Multiple soft-deleted records are allowed
     *
     * @test
     */
    public function it_allows_multiple_soft_deleted_records(): void
    {
        // Create and soft delete multiple times (simulating multiple corrections)
        for ($i = 1; $i <= 3; $i++) {
            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'class_id' => $this->class->id,
                'student_id' => $this->student->id,
                'attendance_date' => today(),
                'attendance_type' => 'in',
                'status' => 'present',
                'check_in_time' => now(),
                'recorded_by' => $this->teacher->id,
                'notes' => "Correction attempt #{$i}",
            ]);

            if ($i < 3) {
                $attendance->delete();
            }
        }

        // Should have 3 records total (2 soft-deleted + 1 active)
        $this->assertEquals(3, Attendance::withTrashed()
            ->where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->count()
        );

        // Only 1 active record
        $this->assertEquals(1, Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->count()
        );
    }

    /**
     * Test 5: Different attendance types are allowed simultaneously
     *
     * @test
     */
    public function it_allows_different_attendance_types_same_day(): void
    {
        // Create check-in
        $checkIn = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'in',
            'status' => 'present',
            'check_in_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);

        // Create check-out (different type) → MUST SUCCEED
        $checkOut = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'out', // Different type
            'status' => 'present',
            'check_out_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);

        $this->assertNotNull($checkIn->id);
        $this->assertNotNull($checkOut->id);
        $this->assertNotEquals($checkIn->id, $checkOut->id);
    }

    /**
     * Test 6: Different schedules are allowed same day
     *
     * @test
     */
    public function it_allows_different_schedules_same_day(): void
    {
        // Create another schedule
        $schedule2 = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);

        // Create attendance for schedule 1
        $attendance1 = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'in',
            'status' => 'present',
            'check_in_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);

        // Create attendance for schedule 2 → MUST SUCCEED
        $attendance2 = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $schedule2->id, // Different schedule
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'in',
            'status' => 'present',
            'check_in_time' => now(),
            'recorded_by' => $this->teacher->id,
        ]);

        $this->assertNotNull($attendance1->id);
        $this->assertNotNull($attendance2->id);
    }

    /**
     * Test 7: Verify index exists (PostgreSQL/SQLite)
     *
     * @test
     */
    public function it_has_soft_delete_safe_unique_index(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $indexes = DB::select("
                SELECT indexname FROM pg_indexes 
                WHERE tablename = 'attendances' 
                AND indexname LIKE '%uk_attendance%'
            ");

            $indexNames = collect($indexes)->pluck('indexname')->toArray();
            $this->assertContains('uk_attendance_active_unique', $indexNames);
        } elseif ($driver === 'mysql') {
            $indexes = DB::select("
                SHOW INDEX FROM attendances WHERE Key_name LIKE '%uk_attendance%'
            ");

            $indexNames = collect($indexes)->pluck('Key_name')->unique()->toArray();
            $this->assertContains('uk_attendance_active_unique', $indexNames);
        } elseif ($driver === 'sqlite') {
            $indexes = DB::select("
                SELECT name FROM sqlite_master 
                WHERE type = 'index' 
                AND tbl_name = 'attendances'
                AND name LIKE '%uk_attendance%'
            ");

            $indexNames = collect($indexes)->pluck('name')->toArray();
            $this->assertContains('uk_attendance_active_unique', $indexNames);
        }
    }

    /**
     * Test 8: Concurrent insert protection (race condition)
     *
     * @test
     */
    public function it_prevents_concurrent_duplicate_inserts(): void
    {
        // Simulate concurrent insert attempts using raw SQL
        $data = [
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'attendance_date' => today()->format('Y-m-d'),
            'attendance_type' => 'in',
            'status' => 'present',
            'state' => 'init',
            'check_in_time' => now(),
            'recorded_by' => $this->teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // First insert succeeds
        DB::table('attendances')->insert($data);

        // Second insert should fail due to unique constraint
        $this->expectException(QueryException::class);
        DB::table('attendances')->insert($data);
    }
}
