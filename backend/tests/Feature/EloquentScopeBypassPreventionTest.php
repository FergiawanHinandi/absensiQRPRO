<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\School;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Eloquent Scope Bypass Prevention Tests
 * 
 * Verifies that DB::table() elimination and Eloquent usage properly respect
 * global scopes for tenant isolation. These tests ensure that replacing
 * DB::table() with Eloquent models maintains multi-tenant security.
 * 
 * CRITICAL: These tests prevent cross-tenant data leakage after Task 5.2
 * 
 * @see .kiro/specs/saas-hardening-30-days/requirements.md (Day 5)
 * @see backend/docs/DB_TABLE_USAGE_AUDIT.md
 */
class EloquentScopeBypassPreventionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Define database connection for tests
     */
    protected $connection = 'sqlite';

    /**
     * Test 1: Eloquent queries automatically filter by authenticated user's school_id
     * 
     * Verifies that when using Eloquent models (not DB::table), queries are
     * automatically scoped to the authenticated user's school.
     */
    public function test_eloquent_queries_automatically_filter_by_school_id(): void
    {
        // Create two schools with data
        $school1 = School::factory()->create(['name' => 'School 1']);
        $school2 = School::factory()->create(['name' => 'School 2']);

        // Create users for each school
        $userSchool1 = User::factory()->create(['school_id' => $school1->id]);
        $userSchool2 = User::factory()->create(['school_id' => $school2->id]);

        // Create attendance records for each school
        $attendanceSchool1 = Attendance::factory()->count(5)->create([
            'school_id' => $school1->id,
            'student_id' => $userSchool1->id,
        ]);
        $attendanceSchool2 = Attendance::factory()->count(3)->create([
            'school_id' => $school2->id,
            'student_id' => $userSchool2->id,
        ]);

        // Authenticate as school 1 user
        Auth::login($userSchool1);

        // Query using Eloquent (should only return school 1 data)
        $results = Attendance::all();

        // Assert only school 1 attendance is returned
        $this->assertCount(5, $results);
        $this->assertTrue($results->every(fn($a) => $a->school_id === $school1->id));

        // Authenticate as school 2 user
        Auth::login($userSchool2);

        // Query using Eloquent (should only return school 2 data)
        $results = Attendance::all();

        // Assert only school 2 attendance is returned
        $this->assertCount(3, $results);
        $this->assertTrue($results->every(fn($a) => $a->school_id === $school2->id));
    }

    /**
     * Test 2: Eloquent where clauses still respect global scope
     * 
     * Verifies that adding additional where clauses doesn't bypass the
     * automatic school_id filtering.
     */
    public function test_eloquent_where_clauses_respect_global_scope(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();

        $userSchool1 = User::factory()->create(['school_id' => $school1->id]);
        $userSchool2 = User::factory()->create(['school_id' => $school2->id]);

        // Create attendance with specific dates
        $targetDate = now()->toDateString();
        Attendance::factory()->create([
            'school_id' => $school1->id,
            'student_id' => $userSchool1->id,
            'attendance_date' => $targetDate,
        ]);
        Attendance::factory()->create([
            'school_id' => $school2->id,
            'student_id' => $userSchool2->id,
            'attendance_date' => $targetDate,
        ]);

        // Authenticate as school 1 user
        Auth::login($userSchool1);

        // Query with where clause
        $results = Attendance::where('attendance_date', $targetDate)->get();

        // Should only return school 1 data, even though both schools have the date
        $this->assertCount(1, $results);
        $this->assertEquals($school1->id, $results->first()->school_id);
    }

    /**
     * Test 3: Eloquent relationships respect global scope
     * 
     * Verifies that eager loading and relationship queries maintain
     * tenant isolation through global scopes.
     */
    public function test_eloquent_relationships_respect_global_scope(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();

        $userSchool1 = User::factory()->create(['school_id' => $school1->id]);
        $userSchool2 = User::factory()->create(['school_id' => $school2->id]);

        // Create schedules for each school
        $scheduleSchool1 = Schedule::factory()->create([
            'school_id' => $school1->id,
        ]);
        $scheduleSchool2 = Schedule::factory()->create([
            'school_id' => $school2->id,
        ]);

        // Create attendance linked to schedules
        Attendance::factory()->create([
            'school_id' => $school1->id,
            'schedule_id' => $scheduleSchool1->id,
            'student_id' => $userSchool1->id,
        ]);
        Attendance::factory()->create([
            'school_id' => $school2->id,
            'schedule_id' => $scheduleSchool2->id,
            'student_id' => $userSchool2->id,
        ]);

        // Authenticate as school 1 user
        Auth::login($userSchool1);

        // Query with eager loading
        $attendances = Attendance::with('schedule')->get();

        // Should only return school 1 data
        $this->assertCount(1, $attendances);
        $this->assertEquals($school1->id, $attendances->first()->school_id);
        $this->assertEquals($school1->id, $attendances->first()->schedule->school_id);
    }

    /**
     * Test 4: Eloquent aggregations respect global scope
     * 
     * Verifies that count(), sum(), avg() and other aggregations are
     * automatically scoped to the authenticated user's school.
     */
    public function test_eloquent_aggregations_respect_global_scope(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();

        $userSchool1 = User::factory()->create(['school_id' => $school1->id]);
        $userSchool2 = User::factory()->create(['school_id' => $school2->id]);

        // Create different counts for each school
        Attendance::factory()->count(7)->create([
            'school_id' => $school1->id,
            'student_id' => $userSchool1->id,
        ]);
        Attendance::factory()->count(4)->create([
            'school_id' => $school2->id,
            'student_id' => $userSchool2->id,
        ]);

        // Authenticate as school 1 user
        Auth::login($userSchool1);

        // Count should only include school 1 records
        $count = Attendance::count();
        $this->assertEquals(7, $count);

        // Authenticate as school 2 user
        Auth::login($userSchool2);

        // Count should only include school 2 records
        $count = Attendance::count();
        $this->assertEquals(4, $count);
    }

    /**
     * Test 5: Super admin can bypass global scope when needed
     * 
     * Verifies that super admins can access cross-school data when
     * explicitly using withoutGlobalScope() or allSchools() scope.
     */
    public function test_super_admin_can_bypass_scope_explicitly(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();

        $superAdmin = User::factory()->create([
            'school_id' => null,
            'role_type' => 'super_admin',
        ]);

        $userSchool1 = User::factory()->create(['school_id' => $school1->id]);
        $userSchool2 = User::factory()->create(['school_id' => $school2->id]);

        // Create attendance for both schools
        Attendance::factory()->count(3)->create([
            'school_id' => $school1->id,
            'student_id' => $userSchool1->id,
        ]);
        Attendance::factory()->count(2)->create([
            'school_id' => $school2->id,
            'student_id' => $userSchool2->id,
        ]);

        // Authenticate as super admin
        Auth::login($superAdmin);

        // Without explicit bypass, super admin sees all schools (no filter applied)
        $allResults = Attendance::all();
        $this->assertCount(5, $allResults);

        // Explicit bypass using allSchools() scope
        $allSchoolsResults = Attendance::allSchools()->get();
        $this->assertCount(5, $allSchoolsResults);

        // Can filter to specific school
        $school1Results = Attendance::forSchool($school1->id)->get();
        $this->assertCount(3, $school1Results);
        $this->assertTrue($school1Results->every(fn($a) => $a->school_id === $school1->id));
    }

    /**
     * Test 6: Eloquent firstOrCreate respects global scope
     * 
     * Verifies that firstOrCreate automatically includes school_id from
     * authenticated user, preventing cross-tenant record creation.
     */
    public function test_eloquent_first_or_create_respects_global_scope(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();

        $userSchool1 = User::factory()->create(['school_id' => $school1->id]);
        $userSchool2 = User::factory()->create(['school_id' => $school2->id]);

        $schedule = Schedule::factory()->create(['school_id' => $school1->id]);

        // Authenticate as school 1 user
        Auth::login($userSchool1);

        // Create attendance using firstOrCreate
        $attendance1 = Attendance::firstOrCreate(
            [
                'student_id' => $userSchool1->id,
                'schedule_id' => $schedule->id,
                'attendance_date' => now()->toDateString(),
            ],
            [
                'check_in_time' => now(),
            ]
        );

        // Verify school_id was auto-filled
        $this->assertEquals($school1->id, $attendance1->school_id);

        // Authenticate as school 2 user
        Auth::login($userSchool2);

        // Try to find the same attendance (should not find it due to scope)
        $attendance2 = Attendance::where('student_id', $userSchool1->id)
            ->where('schedule_id', $schedule->id)
            ->where('attendance_date', now()->toDateString())
            ->first();

        // Should not find school 1's attendance
        $this->assertNull($attendance2);

        // Verify total count is still 1 (no duplicate created)
        Auth::login($userSchool1);
        $this->assertEquals(1, Attendance::count());
    }
}
