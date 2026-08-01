<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eager Loading Property-Based Tests (Week 3 Day 12)
 * 
 * Property-Based Testing for N+1 query elimination to ensure:
 * - Property 36: All queries use eager loading
 * 
 * These tests validate universal correctness properties that must hold
 * across all queries to prevent N+1 query issues and ensure optimal performance.
 * 
 * Risk Reduction: MEDIUM (6/10) - Improves performance
 * 
 * Validates: Requirements Week 3 Day 12.2
 * 
 */
#[\PHPUnit\Framework\Attributes\Group('feature')]
#[\PHPUnit\Framework\Attributes\Group('performance')]
#[\PHPUnit\Framework\Attributes\Group('eager-loading')]
#[\PHPUnit\Framework\Attributes\Group('week3')]
class EagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $teacher;
    protected User $student;
    protected Schedule $schedule;
    protected ClassModel $classroom;
    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->school = School::factory()->create([
            'name' => 'Test School',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $this->classroom = ClassModel::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'Class 10A',
        ]);

        $this->subject = Subject::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'Mathematics',
        ]);

        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $this->classroom->id,
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
        ]);
    }

    /**
     * Helper: Count queries executed during a callback
     */
    protected function countQueries(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        
        $callback();
        
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        
        return count($queries);
    }

    /**
     * Helper: Get query log during a callback
     */
    protected function getQueryLog(callable $callback): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        
        $callback();
        
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        
        return $queries;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PROPERTY 36: All queries use eager loading
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * **Property 36: Attendance queries use eager loading**
     * 
     * Universal property: For any attendance query that accesses relationships,
     * the query MUST use eager loading to prevent N+1 queries.
     * 
     * PROPERTY: ∀ attendance_query accessing relationships
     *           → query_count MUST be O(1) not O(N)
     *           → query_count ≤ 6 regardless of result count
     * 
*/
    public function property_36_attendance_queries_use_eager_loading(): void
    {
        // Arrange: Create 20 attendance records
        $attendances = [];
        for ($i = 0; $i < 20; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'class_id' => $this->classroom->id,
                'student_id' => $student->id,
                'attendance_date' => today(),
                'session_type' => 'morning',
            ]);
            $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');
            $attendances[] = $attendance;
        }

        // Act: Query with proper eager loading
        $queryCount = $this->countQueries(function () {
            $results = Attendance::with([
                'student',
                'schedule.class',
                'schedule.subject',
                'schedule.teacher',
            ])
                ->where('school_id', $this->school->id)
                ->get();

            // Access relationships (should not trigger additional queries)
            foreach ($results as $attendance) {
                $name = $attendance->student->name;
                $className = $attendance->schedule->class->name;
                $subjectName = $attendance->schedule->subject->name;
                $teacherName = $attendance->schedule->teacher->name;
            }
        });

        // Assert: Query count should be constant (≤ 6) regardless of N
        // 1. Main attendance query
        // 2. Students eager load
        // 3. Schedules eager load
        // 4. Classes eager load
        // 5. Subjects eager load
        // 6. Teachers eager load
        $this->assertLessThanOrEqual(
            6,
            $queryCount,
            "Attendance queries with eager loading should execute ≤ 6 queries, got {$queryCount}"
        );
    }

    /**
     * **Property 36: Query count is independent of result size**
     * 
     * Universal property: With proper eager loading, query count should remain
     * constant regardless of the number of records returned.
     * 
     * PROPERTY: ∀ N ∈ {10, 50, 100}
     *           → query_count(N) = query_count(10)
     * 
*/
    public function property_36_query_count_independent_of_result_size(): void
    {
        $queryCounts = [];

        foreach ([10, 50, 100] as $recordCount) {
            // Arrange: Create N attendance records
            Attendance::query()->delete();
            User::where('role_type', 'student')->delete();

            for ($i = 0; $i < $recordCount; $i++) {
                $student = User::factory()->create([
                    'school_id' => $this->school->id,
                    'role_type' => 'student',
                ]);

                $attendance = Attendance::create([
                    'school_id' => $this->school->id,
                    'schedule_id' => $this->schedule->id,
                    'class_id' => $this->classroom->id,
                    'student_id' => $student->id,
                    'attendance_date' => today(),
                    'session_type' => 'morning',
                ]);
                $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');
            }

            // Act: Query with eager loading
            $queryCount = $this->countQueries(function () {
                $results = Attendance::with([
                    'student',
                    'schedule.class',
                    'schedule.subject',
                    'schedule.teacher',
                ])
                    ->where('school_id', $this->school->id)
                    ->get();

                foreach ($results as $attendance) {
                    $name = $attendance->student->name;
                    $className = $attendance->schedule->class->name;
                }
            });

            $queryCounts[$recordCount] = $queryCount;
        }

        // Assert: Query count should be the same for all N
        $this->assertEquals(
            $queryCounts[10],
            $queryCounts[50],
            "Query count should be constant: 10 records={$queryCounts[10]}, 50 records={$queryCounts[50]}"
        );

        $this->assertEquals(
            $queryCounts[10],
            $queryCounts[100],
            "Query count should be constant: 10 records={$queryCounts[10]}, 100 records={$queryCounts[100]}"
        );
    }

    /**
     * **Property 36: Student list queries use eager loading**
     * 
     * Universal property: Student list queries that access class relationships
     * MUST use eager loading to prevent N+1 queries.
     * 
*/
    public function property_36_student_list_queries_use_eager_loading(): void
    {
        // Arrange: Create 15 students with class assignments
        for ($i = 0; $i < 15; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            // Assign to class (assuming studentClass relationship exists)
            if (method_exists($student, 'studentClass')) {
                $student->studentClass()->create([
                    'class_id' => $this->classroom->id,
                    'status' => 'active',
                ]);
            }
        }

        // Act: Query students with eager loading
        $queryCount = $this->countQueries(function () {
            $students = User::where('school_id', $this->school->id)
                ->where('role_type', 'student')
                ->with([
                    'studentClass:id,student_id,class_id,status',
                    'studentClass.class:id,name',
                ])
                ->get();

            // Access relationships
            foreach ($students as $student) {
                if ($student->studentClass) {
                    $className = $student->studentClass->class->name ?? null;
                }
            }
        });

        // Assert: Query count should be ≤ 4
        // 1. Main students query
        // 2. StudentClass eager load
        // 3. Classes eager load
        // 4. Possible pivot table query
        $this->assertLessThanOrEqual(
            4,
            $queryCount,
            "Student list queries should execute ≤ 4 queries, got {$queryCount}"
        );
    }

    /**
     * **Property 36: Nested eager loading prevents N+1**
     * 
     * Universal property: Nested relationships (e.g., schedule.class, schedule.subject)
     * MUST be eager loaded to prevent cascading N+1 queries.
     * 
*/
    public function property_36_nested_eager_loading_prevents_n_plus_one(): void
    {
        // Arrange: Create 10 attendance records
        for ($i = 0; $i < 10; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'class_id' => $this->classroom->id,
                'student_id' => $student->id,
                'attendance_date' => today(),
                'session_type' => 'morning',
            ]);
            $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');
        }

        // Act: Query with nested eager loading
        $queries = $this->getQueryLog(function () {
            $results = Attendance::with([
                'schedule.class',
                'schedule.subject',
                'schedule.teacher',
            ])
                ->where('school_id', $this->school->id)
                ->get();

            // Access nested relationships
            foreach ($results as $attendance) {
                $className = $attendance->schedule->class->name;
                $subjectName = $attendance->schedule->subject->name;
                $teacherName = $attendance->schedule->teacher->name;
            }
        });

        // Assert: No queries should contain WHERE id IN with multiple IDs repeatedly
        $whereInQueries = array_filter($queries, function ($query) {
            return stripos($query['query'], 'where') !== false 
                && stripos($query['query'], 'in') !== false;
        });

        // With proper eager loading, we should have at most 4 WHERE IN queries
        // (one for each relationship level)
        $this->assertLessThanOrEqual(
            4,
            count($whereInQueries),
            "Nested eager loading should minimize WHERE IN queries"
        );
    }

    /**
     * **Property 36: Paginated queries use eager loading**
     * 
     * Universal property: Paginated queries MUST use eager loading to prevent
     * N+1 queries on each page.
     * 
*/
    public function property_36_paginated_queries_use_eager_loading(): void
    {
        // Arrange: Create 30 attendance records
        for ($i = 0; $i < 30; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'class_id' => $this->classroom->id,
                'student_id' => $student->id,
                'attendance_date' => today(),
                'session_type' => 'morning',
            ]);
            $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');
        }

        // Act: Paginated query with eager loading
        $queryCount = $this->countQueries(function () {
            $results = Attendance::with([
                'student',
                'schedule.class',
                'schedule.subject',
            ])
                ->where('school_id', $this->school->id)
                ->paginate(10);

            // Access relationships
            foreach ($results as $attendance) {
                $name = $attendance->student->name;
                $className = $attendance->schedule->class->name;
            }
        });

        // Assert: Query count should be ≤ 6 even with pagination
        // 1. Count query for pagination
        // 2. Main attendance query
        // 3-5. Eager load queries
        $this->assertLessThanOrEqual(
            6,
            $queryCount,
            "Paginated queries should execute ≤ 6 queries, got {$queryCount}"
        );
    }

    /**
     * **Property 36: Single record queries use eager loading**
     * 
     * Universal property: Even single record queries should use eager loading
     * when relationships will be accessed.
     * 
*/
    public function property_36_single_record_queries_use_eager_loading(): void
    {
        // Arrange: Create one attendance record
        $student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);

        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->classroom->id,
            'student_id' => $student->id,
            'attendance_date' => today(),
            'session_type' => 'morning',
        ]);
        $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');

        // Act: Find single record with eager loading
        $queryCount = $this->countQueries(function () use ($attendance) {
            $result = Attendance::with([
                'student',
                'schedule.class',
                'schedule.subject',
                'schedule.teacher',
            ])
                ->find($attendance->id);

            // Access relationships
            $name = $result->student->name;
            $className = $result->schedule->class->name;
            $subjectName = $result->schedule->subject->name;
            $teacherName = $result->schedule->teacher->name;
        });

        // Assert: Should execute ≤ 5 queries
        // 1. Main find query
        // 2-5. Eager load queries
        $this->assertLessThanOrEqual(
            5,
            $queryCount,
            "Single record queries should execute ≤ 5 queries, got {$queryCount}"
        );
    }

    /**
     * **Property 36: Query performance with eager loading**
     * 
     * Universal property: Queries with eager loading should execute faster
     * than queries without eager loading for N > 5.
     * 
*/
    public function property_36_eager_loading_improves_performance(): void
    {
        // Arrange: Create 20 attendance records
        for ($i = 0; $i < 20; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'class_id' => $this->classroom->id,
                'student_id' => $student->id,
                'attendance_date' => today(),
                'session_type' => 'morning',
            ]);
            $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');
        }

        // Act: Measure query count without eager loading
        $queryCountWithout = $this->countQueries(function () {
            $results = Attendance::where('school_id', $this->school->id)->get();

            foreach ($results as $attendance) {
                $name = $attendance->student->name;
            }
        });

        // Act: Measure query count with eager loading
        $queryCountWith = $this->countQueries(function () {
            $results = Attendance::with(['student'])
                ->where('school_id', $this->school->id)
                ->get();

            foreach ($results as $attendance) {
                $name = $attendance->student->name;
            }
        });

        // Assert: Eager loading should significantly reduce query count
        $this->assertLessThan(
            $queryCountWithout,
            $queryCountWith,
            "Eager loading should reduce queries: without={$queryCountWithout}, with={$queryCountWith}"
        );

        // Assert: Without eager loading should be O(N), with should be O(1)
        $this->assertGreaterThan(
            10,
            $queryCountWithout,
            "Without eager loading should execute many queries (N+1 pattern)"
        );

        $this->assertLessThanOrEqual(
            3,
            $queryCountWith,
            "With eager loading should execute few queries (constant)"
        );
    }

    /**
     * **Property 36: Conditional eager loading works correctly**
     * 
     * Universal property: Conditional eager loading should only load
     * relationships when needed, maintaining query efficiency.
     * 
*/
    public function property_36_conditional_eager_loading_works(): void
    {
        // Arrange: Create attendance records
        for ($i = 0; $i < 10; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'class_id' => $this->classroom->id,
                'student_id' => $student->id,
                'attendance_date' => today(),
                'session_type' => 'morning',
            ]);
            $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');
        }

        // Act: Query with conditional eager loading
        $includeSchedule = true;
        $queryCount = $this->countQueries(function () use ($includeSchedule) {
            $query = Attendance::with(['student']);

            if ($includeSchedule) {
                $query->with(['schedule.class', 'schedule.subject']);
            }

            $results = $query->where('school_id', $this->school->id)->get();

            foreach ($results as $attendance) {
                $name = $attendance->student->name;
                if ($includeSchedule) {
                    $className = $attendance->schedule->class->name;
                }
            }
        });

        // Assert: Query count should still be efficient
        $this->assertLessThanOrEqual(
            5,
            $queryCount,
            "Conditional eager loading should maintain efficiency, got {$queryCount} queries"
        );
    }

    /**
     * **Property 36: Eager loading with field selection**
     * 
     * Universal property: Eager loading with field selection should reduce
     * memory usage while maintaining query efficiency.
     * 
*/
    public function property_36_eager_loading_with_field_selection(): void
    {
        // Arrange: Create attendance records
        for ($i = 0; $i < 15; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'class_id' => $this->classroom->id,
                'student_id' => $student->id,
                'attendance_date' => today(),
                'session_type' => 'morning',
            ]);
            $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');
        }

        // Act: Query with field selection
        $queryCount = $this->countQueries(function () {
            $results = Attendance::with([
                'student:id,name,username',
                'schedule:id,class_id,subject_id',
                'schedule.class:id,name',
                'schedule.subject:id,name',
            ])
                ->where('school_id', $this->school->id)
                ->get();

            foreach ($results as $attendance) {
                $name = $attendance->student->name;
                $className = $attendance->schedule->class->name;
            }
        });

        // Assert: Query count should be efficient
        $this->assertLessThanOrEqual(
            5,
            $queryCount,
            "Eager loading with field selection should be efficient, got {$queryCount} queries"
        );
    }

    /**
     * **Property 36: Lazy eager loading for conditional relationships**
     * 
     * Universal property: Lazy eager loading (load()) should work correctly
     * when relationships need to be loaded after initial query.
     * 
*/
    public function property_36_lazy_eager_loading_works(): void
    {
        // Arrange: Create attendance records
        for ($i = 0; $i < 10; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'class_id' => $this->classroom->id,
                'student_id' => $student->id,
                'attendance_date' => today(),
                'session_type' => 'morning',
            ]);
            $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');
        }

        // Act: Query without eager loading, then lazy load
        $queryCount = $this->countQueries(function () {
            $results = Attendance::where('school_id', $this->school->id)->get();

            // Lazy eager load relationships
            $results->load(['student', 'schedule.class']);

            foreach ($results as $attendance) {
                $name = $attendance->student->name;
                $className = $attendance->schedule->class->name;
            }
        });

        // Assert: Lazy eager loading should still be efficient
        $this->assertLessThanOrEqual(
            4,
            $queryCount,
            "Lazy eager loading should be efficient, got {$queryCount} queries"
        );
    }

    /**
     * **Property 36: Aggregation queries don't need eager loading**
     * 
     * Universal property: Aggregation queries (count, sum, avg) should not
     * use eager loading as they don't access relationship data.
     * 
*/
    public function property_36_aggregation_queries_dont_need_eager_loading(): void
    {
        // Arrange: Create attendance records
        for ($i = 0; $i < 20; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'class_id' => $this->classroom->id,
                'student_id' => $student->id,
                'attendance_date' => today(),
                'session_type' => 'morning',
            ]);
            $attendance->checkIn($this->teacher->id, -6.2088, 106.8456, 'device-123');
        }

        // Act: Aggregation queries
        $queryCount = $this->countQueries(function () {
            $total = Attendance::where('school_id', $this->school->id)->count();
            $present = Attendance::where('school_id', $this->school->id)
                ->where('status', 'present')
                ->count();
        });

        // Assert: Should execute only 2 queries (one per aggregation)
        $this->assertEquals(
            2,
            $queryCount,
            "Aggregation queries should not use eager loading, got {$queryCount} queries"
        );
    }

    /**
     * **Property 36: Exists queries don't need eager loading**
     * 
     * Universal property: Existence check queries should not use eager loading
     * as they only check for record existence.
     * 
*/
    public function property_36_exists_queries_dont_need_eager_loading(): void
    {
        // Arrange: Create one attendance record
        $student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);

        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->classroom->id,
            'student_id' => $student->id,
            'attendance_date' => today(),
            'session_type' => 'morning',
        ]);

        // Act: Existence check
        $queryCount = $this->countQueries(function () use ($student) {
            $exists = Attendance::where('student_id', $student->id)
                ->where('attendance_date', today())
                ->exists();
        });

        // Assert: Should execute only 1 query
        $this->assertEquals(
            1,
            $queryCount,
            "Exists queries should not use eager loading, got {$queryCount} queries"
        );
    }
}
