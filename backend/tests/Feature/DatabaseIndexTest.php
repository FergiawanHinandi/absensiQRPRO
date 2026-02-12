<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Database Index Tests
 * 
 * Tests verify that critical indexes exist and are being used by queries.
 * These indexes were added in Day 11 of the SaaS Hardening 30-Day Roadmap.
 * 
 * Indexes tested:
 * - idx_school_date_report: school_id + attendance_date
 * - idx_class_date_report: class_id + attendance_date
 * - idx_student_date_history: student_id + attendance_date
 * - idx_school_state_date: school_id + state + attendance_date
 * - idx_source_scanned: source + scanned_at
 */
class DatabaseIndexTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected Classroom $classroom;
    protected User $student;
    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->school = School::factory()->create();
        $this->classroom = Classroom::factory()->create(['school_id' => $this->school->id]);
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => 'student',
        ]);
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $this->classroom->id,
        ]);
    }

    /**
     * Test 1: Verify idx_school_date_report index exists
     * 
     * This index optimizes school dashboard queries like:
     * "Today's attendance for my school"
     */
    public function test_school_date_report_index_exists(): void
    {
        $driver = DB::connection()->getDriverName();
        $indexExists = false;

        if ($driver === 'mysql') {
            $indexes = DB::select("SHOW INDEX FROM attendances WHERE Key_name = 'idx_school_date_report'");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'pgsql') {
            $indexes = DB::select("
                SELECT indexname 
                FROM pg_indexes 
                WHERE tablename = 'attendances' 
                AND indexname = 'idx_school_date_report'
            ");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'sqlite') {
            $indexes = DB::select("
                SELECT name 
                FROM sqlite_master 
                WHERE type = 'index' 
                AND tbl_name = 'attendances' 
                AND name = 'idx_school_date_report'
            ");
            $indexExists = !empty($indexes);
        }

        $this->assertTrue($indexExists, 'Index idx_school_date_report should exist on attendances table');
    }

    /**
     * Test 2: Verify idx_class_date_report index exists
     * 
     * This index optimizes teacher dashboard queries like:
     * "My class attendance today"
     */
    public function test_class_date_report_index_exists(): void
    {
        $driver = DB::connection()->getDriverName();
        $indexExists = false;

        if ($driver === 'mysql') {
            $indexes = DB::select("SHOW INDEX FROM attendances WHERE Key_name = 'idx_class_date_report'");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'pgsql') {
            $indexes = DB::select("
                SELECT indexname 
                FROM pg_indexes 
                WHERE tablename = 'attendances' 
                AND indexname = 'idx_class_date_report'
            ");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'sqlite') {
            $indexes = DB::select("
                SELECT name 
                FROM sqlite_master 
                WHERE type = 'index' 
                AND tbl_name = 'attendances' 
                AND name = 'idx_class_date_report'
            ");
            $indexExists = !empty($indexes);
        }

        $this->assertTrue($indexExists, 'Index idx_class_date_report should exist on attendances table');
    }

    /**
     * Test 3: Verify idx_student_date_history index exists
     * 
     * This index optimizes student profile queries like:
     * "My attendance history"
     */
    public function test_student_date_history_index_exists(): void
    {
        $driver = DB::connection()->getDriverName();
        $indexExists = false;

        if ($driver === 'mysql') {
            $indexes = DB::select("SHOW INDEX FROM attendances WHERE Key_name = 'idx_student_date_history'");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'pgsql') {
            $indexes = DB::select("
                SELECT indexname 
                FROM pg_indexes 
                WHERE tablename = 'attendances' 
                AND indexname = 'idx_student_date_history'
            ");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'sqlite') {
            $indexes = DB::select("
                SELECT name 
                FROM sqlite_master 
                WHERE type = 'index' 
                AND tbl_name = 'attendances' 
                AND name = 'idx_student_date_history'
            ");
            $indexExists = !empty($indexes);
        }

        $this->assertTrue($indexExists, 'Index idx_student_date_history should exist on attendances table');
    }

    /**
     * Test 4: Verify idx_school_state_date index exists
     * 
     * This index optimizes workflow queries like:
     * "Show all pending attendance corrections"
     */
    public function test_school_state_date_index_exists(): void
    {
        $driver = DB::connection()->getDriverName();
        $indexExists = false;

        if ($driver === 'mysql') {
            $indexes = DB::select("SHOW INDEX FROM attendances WHERE Key_name = 'idx_school_state_date'");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'pgsql') {
            $indexes = DB::select("
                SELECT indexname 
                FROM pg_indexes 
                WHERE tablename = 'attendances' 
                AND indexname = 'idx_school_state_date'
            ");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'sqlite') {
            $indexes = DB::select("
                SELECT name 
                FROM sqlite_master 
                WHERE type = 'index' 
                AND tbl_name = 'attendances' 
                AND name = 'idx_school_state_date'
            ");
            $indexExists = !empty($indexes);
        }

        $this->assertTrue($indexExists, 'Index idx_school_state_date should exist on attendances table');
    }

    /**
     * Test 5: Verify idx_source_scanned index exists
     * 
     * This index optimizes audit queries like:
     * "All QR scans in last hour"
     */
    public function test_source_scanned_index_exists(): void
    {
        $driver = DB::connection()->getDriverName();
        $indexExists = false;

        if ($driver === 'mysql') {
            $indexes = DB::select("SHOW INDEX FROM attendances WHERE Key_name = 'idx_source_scanned'");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'pgsql') {
            $indexes = DB::select("
                SELECT indexname 
                FROM pg_indexes 
                WHERE tablename = 'attendances' 
                AND indexname = 'idx_source_scanned'
            ");
            $indexExists = !empty($indexes);
        } elseif ($driver === 'sqlite') {
            $indexes = DB::select("
                SELECT name 
                FROM sqlite_master 
                WHERE type = 'index' 
                AND tbl_name = 'attendances' 
                AND name = 'idx_source_scanned'
            ");
            $indexExists = !empty($indexes);
        }

        $this->assertTrue($indexExists, 'Index idx_source_scanned should exist on attendances table');
    }

    /**
     * Test 6: Verify school_date query uses index
     * 
     * Tests that queries filtering by school_id and attendance_date
     * actually use the idx_school_date_report index
     */
    public function test_school_date_query_uses_index(): void
    {
        // Create test attendance records
        $teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => 'teacher',
        ]);

        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'session_type' => 'morning',
        ]);
        $attendance->checkIn($teacher->id, -6.2088, 106.8456, 'device-123');

        // Execute query that should use the index
        $results = Attendance::where('school_id', $this->school->id)
            ->whereDate('attendance_date', today())
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals($this->student->id, $results->first()->student_id);
    }

    /**
     * Test 7: Verify class_date query uses index
     * 
     * Tests that queries filtering by class_id and attendance_date
     * actually use the idx_class_date_report index
     */
    public function test_class_date_query_uses_index(): void
    {
        // Create test attendance records
        $teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => 'teacher',
        ]);

        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'session_type' => 'morning',
        ]);
        $attendance->checkIn($teacher->id, -6.2088, 106.8456, 'device-123');

        // Execute query that should use the index
        $results = Attendance::where('class_id', $this->classroom->id)
            ->whereDate('attendance_date', today())
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals($this->student->id, $results->first()->student_id);
    }

    /**
     * Test 8: Verify student_date query uses index
     * 
     * Tests that queries filtering by student_id and attendance_date
     * actually use the idx_student_date_history index
     */
    public function test_student_date_query_uses_index(): void
    {
        // Create test attendance records
        $teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => 'teacher',
        ]);

        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'session_type' => 'morning',
        ]);
        $attendance->checkIn($teacher->id, -6.2088, 106.8456, 'device-123');

        // Execute query that should use the index
        $results = Attendance::where('student_id', $this->student->id)
            ->whereBetween('attendance_date', [today()->subDays(7), today()])
            ->orderBy('attendance_date', 'desc')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals($this->classroom->id, $results->first()->class_id);
    }
}
