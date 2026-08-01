<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scope Bypass Detection Property Tests
 * 
 * Property-Based Testing for DB::table() elimination to ensure:
 * - Property 13: No DB::table('attendances') in application code
 * - Property 14: No DB::table('schedules') in application code
 * - Eloquent queries respect global scopes
 * - Tenant isolation is maintained
 * 
 * These tests validate universal correctness properties that must hold
 * for ALL queries across ALL inputs to prevent tenant scope bypass.
 * 
 * @see .kiro/specs/saas-hardening-30-days/tasks.md (Task 5.4)
 * @see .kiro/specs/saas-hardening-30-days/requirements.md (Week 1 Day 5)
 */
class ScopeBypassTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Property 13.1: No DB::table('attendances') in application code
     * 
     * PROPERTY: For all application code files (excluding migrations, tests, docs),
     * there must be zero occurrences of DB::table('attendances').
     * 
     * This ensures all attendance queries use Eloquent and respect global scopes.
     */
    public function test_property_no_db_table_attendances_in_application_code(): void
    {
        $appPath = base_path('app');
        $violations = [];

        // Search for DB::table('attendances') in app directory
        $command = sprintf(
            'grep -r "DB::table(\'attendances\')" %s --include="*.php" 2>/dev/null || true',
            escapeshellarg($appPath)
        );
        
        exec($command, $output);
        
        foreach ($output as $line) {
            // Parse file path and line content
            if (preg_match('/^(.+?):(.+)$/', $line, $matches)) {
                $violations[] = [
                    'file' => str_replace($appPath . DIRECTORY_SEPARATOR, '', $matches[1]),
                    'line' => trim($matches[2]),
                ];
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                "Found %d violation(s) of DB::table('attendances') in application code:\n%s\n\n" .
                "All attendance queries must use Eloquent: Attendance::query() instead of DB::table('attendances')",
                count($violations),
                json_encode($violations, JSON_PRETTY_PRINT)
            )
        );
    }

    /**
     * Property 13.2: No DB::table("attendances") with double quotes in application code
     * 
     * PROPERTY: For all application code files, there must be zero occurrences
     * of DB::table("attendances") with double quotes.
     */
    public function test_property_no_db_table_attendances_double_quotes_in_application_code(): void
    {
        $appPath = base_path('app');
        $violations = [];

        // Search for DB::table("attendances") in app directory
        $command = sprintf(
            'grep -r "DB::table(\"attendances\")" %s --include="*.php" 2>/dev/null || true',
            escapeshellarg($appPath)
        );
        
        exec($command, $output);
        
        foreach ($output as $line) {
            if (preg_match('/^(.+?):(.+)$/', $line, $matches)) {
                $violations[] = [
                    'file' => str_replace($appPath . DIRECTORY_SEPARATOR, '', $matches[1]),
                    'line' => trim($matches[2]),
                ];
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                "Found %d violation(s) of DB::table(\"attendances\") in application code:\n%s",
                count($violations),
                json_encode($violations, JSON_PRETTY_PRINT)
            )
        );
    }

    /**
     * Property 14.1: No DB::table('schedules') in application code
     * 
     * PROPERTY: For all application code files (excluding migrations, tests, docs),
     * there must be zero occurrences of DB::table('schedules').
     * 
     * This ensures all schedule queries use Eloquent and respect global scopes.
     */
    public function test_property_no_db_table_schedules_in_application_code(): void
    {
        $appPath = base_path('app');
        $violations = [];

        // Search for DB::table('schedules') in app directory
        $command = sprintf(
            'grep -r "DB::table(\'schedules\')" %s --include="*.php" 2>/dev/null || true',
            escapeshellarg($appPath)
        );
        
        exec($command, $output);
        
        foreach ($output as $line) {
            if (preg_match('/^(.+?):(.+)$/', $line, $matches)) {
                $violations[] = [
                    'file' => str_replace($appPath . DIRECTORY_SEPARATOR, '', $matches[1]),
                    'line' => trim($matches[2]),
                ];
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                "Found %d violation(s) of DB::table('schedules') in application code:\n%s\n\n" .
                "All schedule queries must use Eloquent: Schedule::query() instead of DB::table('schedules')",
                count($violations),
                json_encode($violations, JSON_PRETTY_PRINT)
            )
        );
    }

    /**
     * Property 14.2: No DB::table("schedules") with double quotes in application code
     * 
     * PROPERTY: For all application code files, there must be zero occurrences
     * of DB::table("schedules") with double quotes.
     */
    public function test_property_no_db_table_schedules_double_quotes_in_application_code(): void
    {
        $appPath = base_path('app');
        $violations = [];

        // Search for DB::table("schedules") in app directory
        $command = sprintf(
            'grep -r "DB::table(\"schedules\")" %s --include="*.php" 2>/dev/null || true',
            escapeshellarg($appPath)
        );
        
        exec($command, $output);
        
        foreach ($output as $line) {
            if (preg_match('/^(.+?):(.+)$/', $line, $matches)) {
                $violations[] = [
                    'file' => str_replace($appPath . DIRECTORY_SEPARATOR, '', $matches[1]),
                    'line' => trim($matches[2]),
                ];
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                "Found %d violation(s) of DB::table(\"schedules\") in application code:\n%s",
                count($violations),
                json_encode($violations, JSON_PRETTY_PRINT)
            )
        );
    }

    /**
     * Property 15: Eloquent Attendance queries respect global school scope
     * 
     * PROPERTY: For all Attendance queries using Eloquent, the global school scope
     * must automatically filter results by the authenticated user's school_id.
     * 
     * This validates that BelongsToSchool trait is working correctly.
     */
    public function test_property_eloquent_attendance_queries_respect_global_scope(): void
    {
        // Create two schools with attendance records
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();
        
        // Create students and schedules for each school
        $student1 = User::factory()->create(['school_id' => $school1->id, 'role_type' => 'student']);
        $student2 = User::factory()->create(['school_id' => $school2->id, 'role_type' => 'student']);
        $schedule1 = Schedule::factory()->create(['school_id' => $school1->id]);
        $schedule2 = Schedule::factory()->create(['school_id' => $school2->id]);
        
        // Create attendance records with unique dates to avoid constraint violations
        $attendancesSchool1 = collect();
        for ($i = 0; $i < 5; $i++) {
            $attendancesSchool1->push(Attendance::factory()->create([
                'school_id' => $school1->id,
                'student_id' => $student1->id,
                'schedule_id' => $schedule1->id,
                'attendance_date' => now()->subDays($i)->toDateString(),
            ]));
        }
        
        $attendancesSchool2 = collect();
        for ($i = 0; $i < 3; $i++) {
            $attendancesSchool2->push(Attendance::factory()->create([
                'school_id' => $school2->id,
                'student_id' => $student2->id,
                'schedule_id' => $schedule2->id,
                'attendance_date' => now()->subDays($i)->toDateString(),
            ]));
        }

        // Authenticate as user from school1
        $userSchool1 = User::factory()->create(['school_id' => $school1->id]);
        $this->actingAs($userSchool1);

        // Query without explicit school_id filter
        $results = Attendance::all();

        // PROPERTY: Results must only contain school1 attendances
        $this->assertCount(5, $results, 'Global scope should filter to school1 only');
        
        foreach ($results as $attendance) {
            $this->assertEquals(
                $school1->id,
                $attendance->school_id,
                'All results must belong to authenticated user\'s school'
            );
        }

        // Verify school2 attendances are not accessible
        $school2Ids = $attendancesSchool2->pluck('id')->toArray();
        $resultIds = $results->pluck('id')->toArray();
        
        $this->assertEmpty(
            array_intersect($school2Ids, $resultIds),
            'School2 attendances must not be accessible to school1 user'
        );
    }

    /**
     * Property 16: Eloquent Schedule queries respect global school scope
     * 
     * PROPERTY: For all Schedule queries using Eloquent, the global school scope
     * must automatically filter results by the authenticated user's school_id.
     */
    public function test_property_eloquent_schedule_queries_respect_global_scope(): void
    {
        // Create two schools with schedule records
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();
        
        $schedulesSchool1 = Schedule::factory()->count(4)->create(['school_id' => $school1->id]);
        $schedulesSchool2 = Schedule::factory()->count(6)->create(['school_id' => $school2->id]);

        // Authenticate as user from school1
        $userSchool1 = User::factory()->create(['school_id' => $school1->id]);
        $this->actingAs($userSchool1);

        // Query without explicit school_id filter
        $results = Schedule::all();

        // PROPERTY: Results must only contain school1 schedules
        $this->assertCount(4, $results, 'Global scope should filter to school1 only');
        
        foreach ($results as $schedule) {
            $this->assertEquals(
                $school1->id,
                $schedule->school_id,
                'All results must belong to authenticated user\'s school'
            );
        }

        // Verify school2 schedules are not accessible
        $school2Ids = $schedulesSchool2->pluck('id')->toArray();
        $resultIds = $results->pluck('id')->toArray();
        
        $this->assertEmpty(
            array_intersect($school2Ids, $resultIds),
            'School2 schedules must not be accessible to school1 user'
        );
    }

    /**
     * Property 17: Tenant isolation maintained across complex queries
     * 
     * PROPERTY: For all complex queries with joins and aggregations using Eloquent,
     * tenant isolation must be maintained and cross-tenant data must not leak.
     */
    public function test_property_tenant_isolation_maintained_in_complex_queries(): void
    {
        // Create two schools with related data
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();
        
        $teacher1 = User::factory()->create(['school_id' => $school1->id, 'role_type' => 'teacher']);
        $teacher2 = User::factory()->create(['school_id' => $school2->id, 'role_type' => 'teacher']);
        
        $student1 = User::factory()->create(['school_id' => $school1->id, 'role_type' => 'student']);
        $student2 = User::factory()->create(['school_id' => $school2->id, 'role_type' => 'student']);
        
        $schedule1 = Schedule::factory()->create(['school_id' => $school1->id, 'teacher_id' => $teacher1->id]);
        $schedule2 = Schedule::factory()->create(['school_id' => $school2->id, 'teacher_id' => $teacher2->id]);
        
        // Create attendance records with unique dates to avoid constraint violations
        for ($i = 0; $i < 3; $i++) {
            Attendance::factory()->create([
                'school_id' => $school1->id,
                'schedule_id' => $schedule1->id,
                'student_id' => $student1->id,
                'attendance_date' => now()->subDays($i)->toDateString(),
            ]);
        }
        
        for ($i = 0; $i < 5; $i++) {
            Attendance::factory()->create([
                'school_id' => $school2->id,
                'schedule_id' => $schedule2->id,
                'student_id' => $student2->id,
                'attendance_date' => now()->subDays($i)->toDateString(),
            ]);
        }

        // Authenticate as user from school1
        $this->actingAs($teacher1);

        // Complex query with joins
        $results = Attendance::query()
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->join('users', 'schedules.teacher_id', '=', 'users.id')
            ->select('attendances.*')
            ->get();

        // PROPERTY: All results must belong to school1
        $this->assertCount(3, $results, 'Complex query should only return school1 data');
        
        foreach ($results as $attendance) {
            $this->assertEquals(
                $school1->id,
                $attendance->school_id,
                'Complex query must maintain tenant isolation'
            );
        }

        // Verify no cross-tenant data leak
        $school2AttendanceIds = Attendance::withoutGlobalScope('school')
            ->where('school_id', $school2->id)
            ->pluck('id')
            ->toArray();
        
        $resultIds = $results->pluck('id')->toArray();
        
        $this->assertEmpty(
            array_intersect($school2AttendanceIds, $resultIds),
            'Complex queries must not leak cross-tenant data'
        );
    }
}
