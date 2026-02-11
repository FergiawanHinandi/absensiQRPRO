<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Models\School;
use App\Models\Attendance;
use App\Models\Student;
use App\Scopes\SchoolScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TenantIsolationTest
 *
 * Comprehensive test suite for multi-tenant school isolation.
 *
 * COVERAGE:
 * - School data isolation
 * - Super admin bypass
 * - Unauthorized access prevention
 * - Audit logging
 * - Cross-tenant access attempts
 *
 * @group tenancy
 * @group security
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;
    protected School $schoolB;
    protected User $adminA;
    protected User $adminB;
    protected User $superAdmin;
    protected Attendance $attendanceA;
    protected Attendance $attendanceB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two schools
        $this->schoolA = School::factory()->create(['name' => 'School A']);
        $this->schoolB = School::factory()->create(['name' => 'School B']);

        // Create school admins
        $this->adminA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role_type' => 'school_admin',
            'email' => 'admin.a@example.com',
        ]);

        $this->adminB = User::factory()->create([
            'school_id' => $this->schoolB->id,
            'role_type' => 'school_admin',
            'email' => 'admin.b@example.com',
        ]);

        // Create super admin
        $this->superAdmin = User::factory()->create([
            'school_id' => null,
            'role_type' => 'super_admin',
            'email' => 'super@example.com',
        ]);

        // Create students for each school
        $studentA = Student::factory()->create(['school_id' => $this->schoolA->id]);
        $studentB = Student::factory()->create(['school_id' => $this->schoolB->id]);

        // Create attendance records
        $this->attendanceA = Attendance::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $studentA->id,
        ]);

        $this->attendanceB = Attendance::factory()->create([
            'school_id' => $this->schoolB->id,
            'student_id' => $studentB->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SCHOOL ISOLATION TESTS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function school_admin_can_only_see_own_school_data()
    {
        $this->actingAs($this->adminA);

        $attendances = Attendance::all();

        // Should only see school A's attendance
        $this->assertCount(1, $attendances);
        $this->assertEquals($this->attendanceA->id, $attendances->first()->id);
        $this->assertEquals($this->schoolA->id, $attendances->first()->school_id);
    }

    /** @test */
    public function school_admin_cannot_see_other_school_data()
    {
        $this->actingAs($this->adminA);

        $attendances = Attendance::all();

        // Should NOT see school B's attendance
        $this->assertFalse($attendances->contains('id', $this->attendanceB->id));
    }

    /** @test */
    public function school_admin_cannot_find_other_school_record_by_id()
    {
        $this->actingAs($this->adminA);

        // Try to find school B's attendance
        $attendance = Attendance::find($this->attendanceB->id);

        // Should return null (not found due to scope)
        $this->assertNull($attendance);
    }

    /** @test */
    public function school_admin_cannot_query_other_school_data()
    {
        $this->actingAs($this->adminA);

        // Try to query with school B's ID
        $attendances = Attendance::where('school_id', $this->schoolB->id)->get();

        // Should return empty (scope prevents access)
        $this->assertCount(0, $attendances);
    }

    /** @test */
    public function different_school_admins_see_different_data()
    {
        // Admin A sees only school A data
        $this->actingAs($this->adminA);
        $attendancesA = Attendance::all();
        $this->assertCount(1, $attendancesA);
        $this->assertEquals($this->schoolA->id, $attendancesA->first()->school_id);

        // Admin B sees only school B data
        $this->actingAs($this->adminB);
        $attendancesB = Attendance::all();
        $this->assertCount(1, $attendancesB);
        $this->assertEquals($this->schoolB->id, $attendancesB->first()->school_id);

        // They should see different records
        $this->assertNotEquals($attendancesA->first()->id, $attendancesB->first()->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SUPER ADMIN BYPASS TESTS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function super_admin_can_see_all_schools_data()
    {
        $this->actingAs($this->superAdmin);

        $attendances = Attendance::all();

        // Should see both schools' attendance
        $this->assertCount(2, $attendances);
        $this->assertTrue($attendances->contains('id', $this->attendanceA->id));
        $this->assertTrue($attendances->contains('id', $this->attendanceB->id));
    }

    /** @test */
    public function super_admin_bypass_is_logged()
    {
        Log::shouldReceive('channel')
            ->with('security_json')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('SchoolScope bypassed', \Mockery::on(function ($data) {
                return $data['reason'] === 'super_admin'
                    && $data['model'] === Attendance::class;
            }));

        $this->actingAs($this->superAdmin);

        // This should trigger bypass logging
        Attendance::all();
    }

    /** @test */
    public function super_admin_can_use_allTenants_method()
    {
        $this->actingAs($this->superAdmin);

        $attendances = Attendance::allTenants('Testing cross-tenant access');

        // Should see all attendance records
        $this->assertCount(2, $attendances);
    }

    /** @test */
    public function super_admin_can_use_queryAllTenants_method()
    {
        $this->actingAs($this->superAdmin);

        $query = Attendance::queryAllTenants('Testing query builder');
        $attendances = $query->get();

        // Should see all attendance records
        $this->assertCount(2, $attendances);
    }

    /** @test */
    public function super_admin_can_use_findAnyTenant_method()
    {
        $this->actingAs($this->superAdmin);

        // Find school B's attendance (cross-tenant)
        $attendance = Attendance::findAnyTenant($this->attendanceB->id, 'Testing find any tenant');

        $this->assertNotNull($attendance);
        $this->assertEquals($this->attendanceB->id, $attendance->id);
        $this->assertEquals($this->schoolB->id, $attendance->school_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // UNAUTHORIZED BYPASS ATTEMPTS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function school_admin_cannot_use_allTenants_method()
    {
        $this->actingAs($this->adminA);

        $attendances = Attendance::allTenants('Unauthorized attempt');

        // Should return empty collection for unauthorized users
        $this->assertCount(0, $attendances);
    }

    /** @test */
    public function school_admin_cannot_use_queryAllTenants_method()
    {
        $this->actingAs($this->adminA);

        $query = Attendance::queryAllTenants('Unauthorized attempt');
        $attendances = $query->get();

        // Should return empty result
        $this->assertCount(0, $attendances);
    }

    /** @test */
    public function school_admin_cannot_use_findAnyTenant_method()
    {
        $this->actingAs($this->adminA);

        // Try to find school B's attendance
        $attendance = Attendance::findAnyTenant($this->attendanceB->id, 'Unauthorized attempt');

        // Should return null for unauthorized users
        $this->assertNull($attendance);
    }

    /** @test */
    public function unauthorized_bypass_attempt_is_logged()
    {
        Log::shouldReceive('channel')
            ->with('tenant_bypass')
            ->andReturnSelf();

        Log::shouldReceive('critical')
            ->once()
            ->with('UNAUTHORIZED tenant scope bypass attempt', \Mockery::on(function ($data) {
                return $data['event'] === 'tenant_scope_bypass_unauthorized'
                    && $data['user_role'] === 'school_admin';
            }));

        $this->actingAs($this->adminA);

        // This should trigger unauthorized attempt logging
        Attendance::allTenants('Unauthorized attempt');
    }

    // ─────────────────────────────────────────────────────────────────────
    // EXPLICIT SCHOOL CONTEXT TESTS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function can_set_explicit_school_context()
    {
        $this->actingAs($this->superAdmin);

        SchoolScope::forSchool($this->schoolA->id);

        $attendances = Attendance::all();

        // Should only see school A's attendance
        $this->assertCount(1, $attendances);
        $this->assertEquals($this->schoolA->id, $attendances->first()->school_id);

        SchoolScope::clearSchool();
    }

    /** @test */
    public function can_use_withSchool_callback()
    {
        $this->actingAs($this->superAdmin);

        $count = SchoolScope::withSchool($this->schoolB->id, function () {
            return Attendance::count();
        });

        // Should only count school B's attendance
        $this->assertEquals(1, $count);

        // After callback, should see all schools again
        $this->assertCount(2, Attendance::all());
    }

    // ─────────────────────────────────────────────────────────────────────
    // USER WITHOUT SCHOOL_ID TESTS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function user_without_school_id_gets_empty_result()
    {
        $userWithoutSchool = User::factory()->create([
            'school_id' => null,
            'role_type' => 'teacher', // Not super_admin
        ]);

        $this->actingAs($userWithoutSchool);

        $attendances = Attendance::all();

        // Should return empty result for safety
        $this->assertCount(0, $attendances);
    }

    /** @test */
    public function user_without_school_id_is_logged()
    {
        Log::shouldReceive('channel')
            ->with('security_json')
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->once()
            ->with('User without school_id accessing school-scoped model', \Mockery::on(function ($data) {
                return isset($data['user_id']) && isset($data['model']);
            }));

        $userWithoutSchool = User::factory()->create([
            'school_id' => null,
            'role_type' => 'teacher',
        ]);

        $this->actingAs($userWithoutSchool);

        Attendance::all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // API ENDPOINT TESTS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function api_endpoint_respects_tenant_isolation()
    {
        $this->actingAs($this->adminA);

        $response = $this->getJson('/api/v1/attendances');

        $response->assertStatus(200);
        
        // Should only return school A's data
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->schoolA->id, $data[0]['school_id']);
    }

    /** @test */
    public function api_endpoint_prevents_cross_tenant_access()
    {
        $this->actingAs($this->adminA);

        // Try to access school B's attendance
        $response = $this->getJson("/api/v1/attendances/{$this->attendanceB->id}");

        // Should return 404 (not found due to scope)
        $response->assertStatus(404);
    }

    /** @test */
    public function super_admin_api_can_access_all_schools()
    {
        $this->actingAs($this->superAdmin);

        $response = $this->getJson('/api/v1/attendances');

        $response->assertStatus(200);
        
        // Should return all schools' data
        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($data));
    }

    // ─────────────────────────────────────────────────────────────────────
    // QUERY BUILDER TESTS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function scope_applies_to_where_queries()
    {
        $this->actingAs($this->adminA);

        $attendances = Attendance::where('status', 'present')->get();

        // All results should be from school A
        foreach ($attendances as $attendance) {
            $this->assertEquals($this->schoolA->id, $attendance->school_id);
        }
    }

    /** @test */
    public function scope_applies_to_join_queries()
    {
        $this->actingAs($this->adminA);

        $attendances = Attendance::join('students', 'attendances.student_id', '=', 'students.id')
            ->select('attendances.*')
            ->get();

        // All results should be from school A
        foreach ($attendances as $attendance) {
            $this->assertEquals($this->schoolA->id, $attendance->school_id);
        }
    }

    /** @test */
    public function scope_applies_to_count_queries()
    {
        $this->actingAs($this->adminA);

        $count = Attendance::count();

        // Should only count school A's attendance
        $this->assertEquals(1, $count);
    }

    /** @test */
    public function scope_applies_to_aggregate_queries()
    {
        $this->actingAs($this->adminA);

        $max = Attendance::max('id');

        // Should only get max from school A
        $this->assertEquals($this->attendanceA->id, $max);
    }
}
