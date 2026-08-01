<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\Attendance;
use App\Models\School;
use App\Services\TenantScopeBypassAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TenantIsolationTest
 * Tests tenant isolation and scope bypass security.
 * CRITICAL SECURITY TESTS:
 * - Tenant isolation enforcement
 * - Unauthorized bypass prevention
 * - Audit logging verification
 * - Super admin bypass authorization
 */
#[\PHPUnit\Framework\Attributes\Group('security')]
#[\PHPUnit\Framework\Attributes\Group('tenant')]
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private School $school1;
    private School $school2;
    private User $adminSchool1;
    private User $adminSchool2;
    private User $superAdmin;
    private Attendance $attendanceSchool1;
    private Attendance $attendanceSchool2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two schools
        $this->school1 = School::factory()->create(['name' => 'School 1']);
        $this->school2 = School::factory()->create(['name' => 'School 2']);

        // Create admin for each school
        $this->adminSchool1 = User::factory()->create([
            'school_id' => $this->school1->id,
            'role_type' => 'admin',
        ]);

        $this->adminSchool2 = User::factory()->create([
            'school_id' => $this->school2->id,
            'role_type' => 'admin',
        ]);

        // Create super admin (no school_id)
        $this->superAdmin = User::factory()->create([
            'school_id' => null,
            'role_type' => 'super_admin',
        ]);

        // Create attendance records for each school
        $this->attendanceSchool1 = Attendance::factory()->create([
            'school_id' => $this->school1->id,
        ]);

        $this->attendanceSchool2 = Attendance::factory()->create([
            'school_id' => $this->school2->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_only_see_own_school_attendance()
    {
        $this->actingAs($this->adminSchool1);

        $attendances = Attendance::all();

        $this->assertCount(1, $attendances);
        $this->assertEquals($this->school1->id, $attendances->first()->school_id);
        $this->assertNotContains($this->attendanceSchool2->id, $attendances->pluck('id'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_access_other_school_attendance_by_id()
    {
        $this->actingAs($this->adminSchool1);

        $attendance = Attendance::find($this->attendanceSchool2->id);

        $this->assertNull($attendance);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function super_admin_can_see_all_schools_with_allTenants()
    {
        $this->actingAs($this->superAdmin);

        $attendances = Attendance::allTenants('Testing cross-tenant access');

        $this->assertCount(2, $attendances);
        $this->assertContains($this->attendanceSchool1->id, $attendances->pluck('id'));
        $this->assertContains($this->attendanceSchool2->id, $attendances->pluck('id'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_use_allTenants()
    {
        $this->actingAs($this->adminSchool1);

        $attendances = Attendance::allTenants();

        $this->assertCount(0, $attendances);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unauthorized_allTenants_attempt_is_logged()
    {
        Log::shouldReceive('channel')
            ->with('tenant_bypass')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('critical')
            ->once()
            ->with('UNAUTHORIZED tenant scope bypass attempt', \Mockery::type('array'));

        Log::shouldReceive('channel')
            ->with('security_json')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('critical')
            ->once()
            ->with('unauthorized_tenant_bypass', \Mockery::type('array'));

        $this->actingAs($this->adminSchool1);

        Attendance::allTenants();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function authorized_allTenants_is_logged()
    {
        Log::shouldReceive('channel')
            ->with('tenant_bypass')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->once()
            ->with('Tenant scope bypassed', \Mockery::type('array'));

        Log::shouldReceive('channel')
            ->with('audit')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('tenant_scope_bypass', \Mockery::type('array'));

        $this->actingAs($this->superAdmin);

        Attendance::allTenants('Testing authorized bypass');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function super_admin_can_query_all_tenants()
    {
        $this->actingAs($this->superAdmin);

        $query = Attendance::queryAllTenants('Testing query builder');
        $attendances = $query->get();

        $this->assertCount(2, $attendances);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_query_all_tenants()
    {
        $this->actingAs($this->adminSchool1);

        $query = Attendance::queryAllTenants();
        $attendances = $query->get();

        $this->assertCount(0, $attendances);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function super_admin_can_find_any_tenant()
    {
        $this->actingAs($this->superAdmin);

        $attendance = Attendance::findAnyTenant($this->attendanceSchool2->id, 'Testing find');

        $this->assertNotNull($attendance);
        $this->assertEquals($this->attendanceSchool2->id, $attendance->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_find_any_tenant()
    {
        $this->actingAs($this->adminSchool1);

        $attendance = Attendance::findAnyTenant($this->attendanceSchool2->id);

        $this->assertNull($attendance);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guest_cannot_bypass_tenant_scope()
    {
        // Not authenticated

        $attendances = Attendance::allTenants();
        $this->assertCount(0, $attendances);

        $query = Attendance::queryAllTenants();
        $this->assertCount(0, $query->get());

        $attendance = Attendance::findAnyTenant($this->attendanceSchool1->id);
        $this->assertNull($attendance);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function audit_service_correctly_identifies_authorization()
    {
        $auditService = app(TenantScopeBypassAuditService::class);

        // Super admin is authorized
        $this->actingAs($this->superAdmin);
        $this->assertTrue($auditService->isAuthorized());

        // Regular admin is not authorized
        $this->actingAs($this->adminSchool1);
        $this->assertFalse($auditService->isAuthorized());

        // Guest is not authorized
        auth()->logout();
        $this->assertFalse($auditService->isAuthorized());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function bypass_with_reason_is_logged_with_reason()
    {
        $reason = 'Testing cross-school report generation';

        Log::shouldReceive('channel')
            ->with('tenant_bypass')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->once()
            ->with('Tenant scope bypassed', \Mockery::on(function ($arg) use ($reason) {
                return isset($arg['reason']) && $arg['reason'] === $reason;
            }));

        Log::shouldReceive('channel')
            ->with('audit')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('tenant_scope_bypass', \Mockery::type('array'));

        $this->actingAs($this->superAdmin);

        Attendance::allTenants($reason);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function tenant_scope_applies_to_create_operations()
    {
        $this->actingAs($this->adminSchool1);

        $attendance = Attendance::factory()->create([
            // Don't specify school_id - should be auto-set
        ]);

        $this->assertEquals($this->school1->id, $attendance->school_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function super_admin_create_does_not_auto_set_school_id()
    {
        $this->actingAs($this->superAdmin);

        $attendance = Attendance::factory()->create([
            'school_id' => $this->school2->id,
        ]);

        $this->assertEquals($this->school2->id, $attendance->school_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function tenant_scope_applies_to_update_operations()
    {
        $this->actingAs($this->adminSchool1);

        // Try to update attendance from school 2
        $result = Attendance::where('id', $this->attendanceSchool2->id)
            ->update(['status' => 'present']);

        // Should not update (scope prevents access)
        $this->assertEquals(0, $result);

        // Verify not updated
        $this->attendanceSchool2->refresh();
        $this->assertNotEquals('present', $this->attendanceSchool2->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function tenant_scope_applies_to_delete_operations()
    {
        $this->actingAs($this->adminSchool1);

        // Try to delete attendance from school 2
        $result = Attendance::where('id', $this->attendanceSchool2->id)
            ->delete();

        // Should not delete (scope prevents access)
        $this->assertEquals(0, $result);

        // Verify still exists
        $this->assertDatabaseHas('attendances', [
            'id' => $this->attendanceSchool2->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function multiple_bypass_calls_are_all_logged()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->times(3);
        Log::shouldReceive('info')->times(3);

        $this->actingAs($this->superAdmin);

        Attendance::allTenants('First call');
        Attendance::queryAllTenants('Second call');
        Attendance::findAnyTenant($this->attendanceSchool1->id, 'Third call');
    }
}
