<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\School;
use App\Models\User;
use App\Services\AdminAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminActivityAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $schoolAdmin;

    private School $school;

    private AdminAuditService $auditService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->school = School::factory()->create(['name' => 'Test School']);

        $this->superAdmin = User::factory()->create([
            'role_type' => 'super_admin',
            'school_id' => null,
            'is_active' => true,
        ]);

        $this->schoolAdmin = User::factory()->create([
            'role_type' => 'school_admin',
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);

        $this->auditService = app(AdminAuditService::class);
    }

    /** @test */
    public function it_creates_activity_log_record()
    {
        $this->actingAs($this->schoolAdmin);

        $log = $this->auditService->log(
            AdminActivityLog::ACTION_TEACHER_CREATE,
            'Teacher',
            123,
            'Created new teacher: John Doe',
            ['email' => 'john@example.com']
        );

        $this->assertNotNull($log);
        $this->assertDatabaseHas('admin_activity_logs', [
            'id' => $log->id,
            'admin_user_id' => $this->schoolAdmin->id,
            'action_type' => AdminActivityLog::ACTION_TEACHER_CREATE,
            'target_type' => 'Teacher',
            'target_id' => 123,
        ]);
    }

    /** @test */
    public function it_identifies_high_risk_actions()
    {
        $this->actingAs($this->schoolAdmin);

        $log = $this->auditService->log(
            AdminActivityLog::ACTION_TEACHER_DEVICE_RESET,
            'Teacher',
            123,
            'Device reset for teacher'
        );

        $this->assertTrue($log->isHighRisk());

        $normalLog = $this->auditService->log(
            AdminActivityLog::ACTION_TEACHER_CREATE,
            'Teacher',
            456,
            'Created teacher'
        );

        $this->assertFalse($normalLog->isHighRisk());
    }

    /** @test */
    public function it_prevents_update_on_model()
    {
        $this->actingAs($this->schoolAdmin);

        $log = $this->auditService->log(
            AdminActivityLog::ACTION_TEACHER_CREATE,
            'Teacher',
            123,
            'Test action'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $log->update(['description' => 'Modified']);
    }

    /** @test */
    public function it_prevents_delete_on_model()
    {
        $this->actingAs($this->schoolAdmin);

        $log = $this->auditService->log(
            AdminActivityLog::ACTION_TEACHER_CREATE,
            'Teacher',
            123,
            'Test action'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $log->delete();
    }

    /** @test */
    public function it_logs_login_action()
    {
        $log = $this->auditService->logLogin($this->schoolAdmin);

        $this->assertNotNull($log);
        $this->assertEquals(AdminActivityLog::ACTION_LOGIN, $log->action_type);
        $this->assertEquals($this->schoolAdmin->id, $log->admin_user_id);
    }

    /** @test */
    public function it_logs_device_reset_action()
    {
        $this->actingAs($this->schoolAdmin);

        $log = $this->auditService->logDeviceReset(
            123,
            'John Doe Teacher',
            ['reason' => 'Device lost']
        );

        $this->assertNotNull($log);
        $this->assertEquals(AdminActivityLog::ACTION_TEACHER_DEVICE_RESET, $log->action_type);
        $this->assertEquals('Teacher', $log->target_type);
        $this->assertEquals(123, $log->target_id);
        $this->assertTrue($log->isHighRisk());
    }

    /** @test */
    public function super_admin_can_list_all_activity()
    {
        // Create some activity as school admin
        $this->actingAs($this->schoolAdmin);
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_CREATE, 'Teacher', 1, 'Created teacher');
        $this->auditService->log(AdminActivityLog::ACTION_STUDENT_CREATE, 'Student', 2, 'Created student');

        // List as super admin
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/system/admin-activity');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'admin',
                        'role',
                        'action_type',
                        'action_display',
                        'description',
                        'is_high_risk',
                        'created_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                ],
            ]);

        // Should see at least 2 records + 1 for viewing audit log
        $this->assertGreaterThanOrEqual(2, $response->json('meta.total'));
    }

    /** @test */
    public function school_admin_only_sees_own_school_activity()
    {
        // Create another school and admin
        $otherSchool = School::factory()->create(['name' => 'Other School']);
        $otherAdmin = User::factory()->create([
            'role_type' => 'school_admin',
            'school_id' => $otherSchool->id,
            'is_active' => true,
        ]);

        // Create activity for other school
        $this->actingAs($otherAdmin);
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_CREATE, 'Teacher', 999, 'Other school teacher');

        // Create activity for this school
        $this->actingAs($this->schoolAdmin);
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_CREATE, 'Teacher', 1, 'This school teacher');

        // List as school admin
        $response = $this->actingAs($this->schoolAdmin)
            ->getJson('/api/v1/admin/system/admin-activity');

        $response->assertOk();

        // Should NOT see the other school's activity
        $data = $response->json('data');
        foreach ($data as $item) {
            if ($item['school']) {
                $this->assertEquals($this->school->id, $item['school']['id']);
            }
        }
    }

    /** @test */
    public function it_filters_by_action_type()
    {
        $this->actingAs($this->schoolAdmin);
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_CREATE, 'Teacher', 1, 'Created teacher');
        $this->auditService->log(AdminActivityLog::ACTION_STUDENT_CREATE, 'Student', 2, 'Created student');

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/system/admin-activity?action_type='.AdminActivityLog::ACTION_TEACHER_CREATE);

        $response->assertOk();

        $data = $response->json('data');
        foreach ($data as $item) {
            $this->assertEquals(AdminActivityLog::ACTION_TEACHER_CREATE, $item['action_type']);
        }
    }

    /** @test */
    public function it_filters_high_risk_actions()
    {
        $this->actingAs($this->schoolAdmin);
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_CREATE, 'Teacher', 1, 'Normal action');
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_DEVICE_RESET, 'Teacher', 2, 'High risk action');

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/system/admin-activity?high_risk=true');

        $response->assertOk();

        $data = $response->json('data');
        foreach ($data as $item) {
            $this->assertTrue($item['is_high_risk']);
        }
    }

    /** @test */
    public function it_returns_activity_summary()
    {
        $this->actingAs($this->schoolAdmin);
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_CREATE, 'Teacher', 1, 'Action 1');
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_DEVICE_RESET, 'Teacher', 2, 'Action 2');

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/system/admin-activity/summary?days=7');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'period_days',
                    'total_actions',
                    'high_risk_count',
                    'high_risk_percentage',
                    'actions_by_type',
                    'most_active_admins',
                    'recent_high_risk',
                    'daily_trend',
                ],
            ]);
    }

    /** @test */
    public function it_returns_action_types()
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/system/admin-activity/action-types');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'value',
                        'label',
                        'is_high_risk',
                    ],
                ],
            ]);

        // Should have at least some action types
        $this->assertNotEmpty($response->json('data'));
    }

    /** @test */
    public function it_returns_my_activity()
    {
        $this->actingAs($this->schoolAdmin);
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_CREATE, 'Teacher', 1, 'My action');

        $response = $this->actingAs($this->schoolAdmin)
            ->getJson('/api/v1/admin/system/admin-activity/my-activity');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
                'summary' => [
                    'total_actions',
                    'high_risk_actions',
                    'actions_by_type',
                    'period_days',
                ],
                'meta',
            ]);
    }

    /** @test */
    public function it_returns_activity_for_target()
    {
        $this->actingAs($this->schoolAdmin);
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_CREATE, 'Teacher', 123, 'Created');
        $this->auditService->log(AdminActivityLog::ACTION_TEACHER_UPDATE, 'Teacher', 123, 'Updated');
        $this->auditService->log(AdminActivityLog::ACTION_STUDENT_CREATE, 'Student', 456, 'Other');

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/system/admin-activity/target/teacher/123');

        $response->assertOk();

        $data = $response->json('data');
        foreach ($data as $item) {
            $this->assertEquals('Teacher', $item['target_type'] ?? $response->json('meta.target_type'));
        }

        $this->assertEquals('Teacher', $response->json('meta.target_type'));
        $this->assertEquals(123, $response->json('meta.target_id'));
    }

    /** @test */
    public function query_scopes_work_correctly()
    {
        $this->actingAs($this->schoolAdmin);

        // Create various logs
        $log1 = $this->auditService->log(AdminActivityLog::ACTION_TEACHER_CREATE, 'Teacher', 1, 'Log 1');
        $log2 = $this->auditService->log(AdminActivityLog::ACTION_TEACHER_DEVICE_RESET, 'Teacher', 2, 'Log 2');

        // Test forAdmin scope
        $forAdmin = AdminActivityLog::forAdmin($this->schoolAdmin->id)->count();
        $this->assertGreaterThanOrEqual(2, $forAdmin);

        // Test highRisk scope
        $highRisk = AdminActivityLog::highRisk()->count();
        $this->assertGreaterThanOrEqual(1, $highRisk);

        // Test forTarget scope
        $forTarget = AdminActivityLog::forTarget('Teacher', 1)->count();
        $this->assertGreaterThanOrEqual(1, $forTarget);

        // Test recent scope
        $recent = AdminActivityLog::recent(1)->count();
        $this->assertGreaterThanOrEqual(2, $recent);
    }
}
