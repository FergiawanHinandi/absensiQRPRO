<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\SecurityPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityPolicyApiTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMiddleware;

    protected User $superAdmin;
    protected User $schoolAdmin;
    protected User $teacher;
    protected School $school;
    protected School $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        // Create schools
        $this->school = School::factory()->create([
            "name" => "Test School",
            "is_active" => true,
        ]);

        $this->otherSchool = School::factory()->create([
            "name" => "Other School",
            "is_active" => true,
        ]);

        // Create users
        $this->superAdmin = User::factory()->create([
            "role_type" => "super_admin",
            "school_id" => null,
            "is_active" => true,
        ]);

        $this->schoolAdmin = User::factory()->create([
            "role_type" => "school_admin",
            "school_id" => $this->school->id,
            "is_active" => true,
        ]);

        $this->teacher = User::factory()->create([
            "role_type" => "teacher",
            "school_id" => $this->school->id,
            "is_active" => true,
        ]);

        // Seed default policies
        $this->seedSecurityPolicies();
    }

    /**
     * Seed security policies directly instead of using artisan command
     */
    protected function seedSecurityPolicies(): void
    {
        $policies = [
            [
                "key" => "attendance.geofence_radius_meters",
                "value" => 50,
                "description" => "Default geofence radius in meters",
            ],
            [
                "key" => "attendance.max_scan_per_minute",
                "value" => 30,
                "description" => "Max attendance scans per minute",
            ],
            [
                "key" => "attendance.max_failed_scans_per_2min",
                "value" => 5,
                "description" => "Max failed scans per 2 minutes",
            ],
            [
                "key" => "attendance.qr_expiry_minutes",
                "value" => 10,
                "description" => "QR code expiry in minutes",
            ],
            [
                "key" => "behavior.anomaly_score_suspicious",
                "value" => 3,
                "description" => "Suspicious anomaly score threshold",
            ],
            [
                "key" => "behavior.anomaly_score_high",
                "value" => 6,
                "description" => "High anomaly score threshold",
            ],
            [
                "key" => "behavior.anomaly_score_critical",
                "value" => 9,
                "description" => "Critical anomaly score threshold",
            ],
            [
                "key" => "security.admin_session_max_ip_change",
                "value" => 1,
                "description" => "Max allowed admin IP changes per session",
            ],
        ];

        foreach ($policies as $policy) {
            DB::table("security_policies")->insert([
                "scope_type" => "global",
                "scope_id" => null,
                "key" => $policy["key"],
                "value" => json_encode($policy["value"]),
                "description" => $policy["description"],
                "updated_by" => 1,
                "created_at" => now(),
                "updated_at" => now(),
            ]);
        }
    }

    // =========================================================================
    // INDEX TESTS
    // =========================================================================

    public function test_super_admin_can_list_all_policies(): void
    {
        $response = $this->actingAs($this->superAdmin, "sanctum")->getJson(
            "/api/v1/admin/security-policies",
        );

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                "success",
                "data",
                "defaults",
                "descriptions",
            ])
            ->assertJson(["success" => true]);
    }

    public function test_school_admin_can_list_policies_for_their_school(): void
    {
        // Create a school-specific policy
        DB::table("security_policies")->insert([
            "scope_type" => "school",
            "scope_id" => $this->school->id,
            "key" => "attendance.geofence_radius_meters",
            "value" => json_encode(100),
            "description" => "Custom radius for school",
            "updated_by" => $this->schoolAdmin->id,
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $response = $this->actingAs($this->schoolAdmin, "sanctum")->getJson(
            "/api/v1/admin/security-policies",
        );

        $response
            ->assertStatus(200)
            ->assertJsonStructure(["success", "data", "effective", "school_id"])
            ->assertJson([
                "success" => true,
                "school_id" => $this->school->id,
            ]);
    }

    public function test_teacher_cannot_list_policies(): void
    {
        $response = $this->actingAs($this->teacher, "sanctum")->getJson(
            "/api/v1/admin/security-policies",
        );

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_list_policies(): void
    {
        $response = $this->getJson("/api/v1/admin/security-policies");

        $response->assertStatus(401);
    }

    // =========================================================================
    // STORE TESTS
    // =========================================================================

    public function test_super_admin_can_create_global_policy(): void
    {
        $response = $this->actingAs($this->superAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies",
            [
                "scope_type" => "global",
                "scope_id" => null,
                "key" => "attendance.teacher_geofence_radius_meters",
                "value" => 300,
                "description" => "Custom global teacher geofence",
            ],
        );

        $response->assertStatus(201)->assertJson([
            "success" => true,
            "data" => [
                "key" => "attendance.teacher_geofence_radius_meters",
                "value" => 300,
                "scope_type" => "global",
            ],
        ]);

        $this->assertDatabaseHas("security_policies", [
            "scope_type" => "global",
            "key" => "attendance.teacher_geofence_radius_meters",
            "value" => json_encode(300),
        ]);
    }

    public function test_super_admin_can_create_school_policy(): void
    {
        $response = $this->actingAs($this->superAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies",
            [
                "scope_type" => "school",
                "scope_id" => $this->school->id,
                "key" => "attendance.late_threshold_minutes",
                "value" => 20,
                "description" => "Custom late threshold for school",
            ],
        );

        $response->assertStatus(201)->assertJson([
            "success" => true,
            "data" => [
                "key" => "attendance.late_threshold_minutes",
                "value" => 20,
                "scope_type" => "school",
                "scope_id" => $this->school->id,
            ],
        ]);
    }

    public function test_school_admin_can_create_policy_for_own_school(): void
    {
        $response = $this->actingAs($this->schoolAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies",
            [
                "scope_type" => "school",
                "scope_id" => $this->school->id,
                "key" => "attendance.schedule_tolerance_before_minutes",
                "value" => 15,
                "description" => "Custom tolerance",
            ],
        );

        $response->assertStatus(201)->assertJson(["success" => true]);
    }

    public function test_school_admin_cannot_create_global_policy(): void
    {
        $response = $this->actingAs($this->schoolAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies",
            [
                "scope_type" => "global",
                "key" => "attendance.geofence_radius_meters",
                "value" => 100,
            ],
        );

        $response->assertStatus(403);
    }

    public function test_school_admin_cannot_create_policy_for_other_school(): void
    {
        $response = $this->actingAs($this->schoolAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies",
            [
                "scope_type" => "school",
                "scope_id" => $this->otherSchool->id,
                "key" => "attendance.geofence_radius_meters",
                "value" => 100,
            ],
        );

        $response->assertStatus(403);
    }

    public function test_cannot_create_duplicate_policy(): void
    {
        // First, create a global policy
        DB::table("security_policies")->insert([
            "scope_type" => "global",
            "scope_id" => null,
            "key" => "attendance.late_threshold_minutes",
            "value" => json_encode(15),
            "updated_by" => $this->superAdmin->id,
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        // Try to create duplicate
        $response = $this->actingAs($this->superAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies",
            [
                "scope_type" => "global",
                "key" => "attendance.late_threshold_minutes",
                "value" => 20,
            ],
        );

        $response->assertStatus(409)->assertJson([
            "success" => false,
            "message" => "Policy already exists. Use PUT to update.",
        ]);
    }

    public function test_cannot_create_policy_with_invalid_key(): void
    {
        $response = $this->actingAs($this->superAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies",
            [
                "scope_type" => "global",
                "key" => "invalid.policy.key",
                "value" => 100,
            ],
        );

        $response->assertStatus(422);
    }

    public function test_validation_fails_for_invalid_value(): void
    {
        $response = $this->actingAs($this->superAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies",
            [
                "scope_type" => "global",
                "key" => "attendance.geofence_radius_meters",
                "value" => 1, // min is 10
            ],
        );

        $response
            ->assertStatus(422)
            ->assertJsonStructure(["success", "message", "errors"]);
    }

    // =========================================================================
    // UPDATE TESTS
    // =========================================================================

    public function test_super_admin_can_update_global_policy(): void
    {
        $policyId = DB::table("security_policies")
            ->where("scope_type", "global")
            ->where("key", "attendance.geofence_radius_meters")
            ->value("id");

        $response = $this->actingAs($this->superAdmin, "sanctum")->putJson(
            "/api/v1/admin/security-policies/{$policyId}",
            [
                "value" => 75,
                "description" => "Updated radius",
            ],
        );

        $response->assertStatus(200)->assertJson([
            "success" => true,
            "data" => [
                "old_value" => 50,
                "new_value" => 75,
            ],
        ]);

        $this->assertDatabaseHas("security_policies", [
            "id" => $policyId,
            "value" => json_encode(75),
        ]);

        // Check history was logged
        $this->assertDatabaseHas("security_policy_history", [
            "policy_id" => $policyId,
            "old_value" => json_encode(50),
            "new_value" => json_encode(75),
            "changed_by" => $this->superAdmin->id,
        ]);
    }

    public function test_school_admin_can_update_own_school_policy(): void
    {
        // Create school-specific policy
        $policyId = DB::table("security_policies")->insertGetId([
            "scope_type" => "school",
            "scope_id" => $this->school->id,
            "key" => "attendance.late_threshold_minutes",
            "value" => json_encode(15),
            "updated_by" => $this->superAdmin->id,
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $response = $this->actingAs($this->schoolAdmin, "sanctum")->putJson(
            "/api/v1/admin/security-policies/{$policyId}",
            [
                "value" => 20,
            ],
        );

        $response->assertStatus(200)->assertJson(["success" => true]);
    }

    public function test_school_admin_cannot_update_global_policy(): void
    {
        $policyId = DB::table("security_policies")
            ->where("scope_type", "global")
            ->where("key", "attendance.geofence_radius_meters")
            ->value("id");

        $response = $this->actingAs($this->schoolAdmin, "sanctum")->putJson(
            "/api/v1/admin/security-policies/{$policyId}",
            [
                "value" => 100,
            ],
        );

        $response->assertStatus(403);
    }

    public function test_school_admin_cannot_update_other_school_policy(): void
    {
        // Create policy for other school
        $policyId = DB::table("security_policies")->insertGetId([
            "scope_type" => "school",
            "scope_id" => $this->otherSchool->id,
            "key" => "attendance.late_threshold_minutes",
            "value" => json_encode(15),
            "updated_by" => $this->superAdmin->id,
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $response = $this->actingAs($this->schoolAdmin, "sanctum")->putJson(
            "/api/v1/admin/security-policies/{$policyId}",
            [
                "value" => 20,
            ],
        );

        $response->assertStatus(403);
    }

    public function test_update_returns_404_for_nonexistent_policy(): void
    {
        $response = $this->actingAs($this->superAdmin, "sanctum")->putJson(
            "/api/v1/admin/security-policies/99999",
            [
                "value" => 100,
            ],
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // DELETE TESTS
    // =========================================================================

    public function test_super_admin_can_delete_global_policy(): void
    {
        // Create a deletable policy
        $policyId = DB::table("security_policies")->insertGetId([
            "scope_type" => "global",
            "scope_id" => null,
            "key" => "attendance.teacher_geofence_radius_meters",
            "value" => json_encode(250),
            "updated_by" => $this->superAdmin->id,
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $response = $this->actingAs($this->superAdmin, "sanctum")->deleteJson(
            "/api/v1/admin/security-policies/{$policyId}",
        );

        $response->assertStatus(200)->assertJson([
            "success" => true,
            "default_value" => 200, // Falls back to DEFAULTS constant
        ]);

        $this->assertDatabaseMissing("security_policies", [
            "id" => $policyId,
        ]);
    }

    public function test_school_admin_can_delete_own_school_policy(): void
    {
        $policyId = DB::table("security_policies")->insertGetId([
            "scope_type" => "school",
            "scope_id" => $this->school->id,
            "key" => "attendance.late_threshold_minutes",
            "value" => json_encode(20),
            "updated_by" => $this->schoolAdmin->id,
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $response = $this->actingAs($this->schoolAdmin, "sanctum")->deleteJson(
            "/api/v1/admin/security-policies/{$policyId}",
        );

        $response->assertStatus(200)->assertJson(["success" => true]);
    }

    public function test_school_admin_cannot_delete_global_policy(): void
    {
        $policyId = DB::table("security_policies")
            ->where("scope_type", "global")
            ->where("key", "attendance.geofence_radius_meters")
            ->value("id");

        $response = $this->actingAs($this->schoolAdmin, "sanctum")->deleteJson(
            "/api/v1/admin/security-policies/{$policyId}",
        );

        $response->assertStatus(403);
    }

    // =========================================================================
    // KEYS ENDPOINT TESTS
    // =========================================================================

    public function test_can_get_available_policy_keys(): void
    {
        $response = $this->actingAs($this->superAdmin, "sanctum")->getJson(
            "/api/v1/admin/security-policies/keys",
        );

        $response->assertStatus(200)->assertJsonStructure([
            "success",
            "data" => [
                "*" => ["key", "default", "description", "type"],
            ],
        ]);
    }

    // =========================================================================
    // SHOW ENDPOINT TESTS
    // =========================================================================

    public function test_can_get_single_policy_with_history(): void
    {
        $policyId = DB::table("security_policies")
            ->where("scope_type", "global")
            ->where("key", "attendance.geofence_radius_meters")
            ->value("id");

        $response = $this->actingAs($this->superAdmin, "sanctum")->getJson(
            "/api/v1/admin/security-policies/{$policyId}",
        );

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                "success",
                "data" => ["id", "scope_type", "key", "value", "description"],
                "history",
                "default_value",
            ]);
    }

    // =========================================================================
    // HISTORY ENDPOINT TESTS
    // =========================================================================

    public function test_can_get_policy_history(): void
    {
        $policyId = DB::table("security_policies")
            ->where("scope_type", "global")
            ->where("key", "attendance.geofence_radius_meters")
            ->value("id");

        // Create some history
        DB::table("security_policy_history")->insert([
            "policy_id" => $policyId,
            "old_value" => json_encode(50),
            "new_value" => json_encode(75),
            "changed_by" => $this->superAdmin->id,
            "created_at" => now()->subHour(),
        ]);

        $response = $this->actingAs($this->superAdmin, "sanctum")->getJson(
            "/api/v1/admin/security-policies/{$policyId}/history",
        );

        $response->assertStatus(200)->assertJsonStructure([
            "success",
            "data" => [
                "data" => [
                    "*" => [
                        "policy_id",
                        "old_value",
                        "new_value",
                        "changed_by",
                        "created_at",
                    ],
                ],
            ],
        ]);
    }

    // =========================================================================
    // BULK UPDATE TESTS
    // =========================================================================

    public function test_super_admin_can_bulk_update_policies(): void
    {
        $response = $this->actingAs($this->superAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies/bulk",
            [
                "policies" => [
                    [
                        "key" => "attendance.late_threshold_minutes",
                        "value" => 20,
                        "scope_type" => "global",
                        "scope_id" => null,
                    ],
                    [
                        "key" => "attendance.schedule_tolerance_before_minutes",
                        "value" => 15,
                        "scope_type" => "global",
                        "scope_id" => null,
                    ],
                ],
            ],
        );

        $response->assertStatus(200)->assertJson([
            "success" => true,
            "results" => [
                [
                    "key" => "attendance.late_threshold_minutes",
                    "status" => "updated",
                ],
                [
                    "key" => "attendance.schedule_tolerance_before_minutes",
                    "status" => "updated",
                ],
            ],
        ]);
    }

    public function test_school_admin_cannot_bulk_update(): void
    {
        $response = $this->actingAs($this->schoolAdmin, "sanctum")->postJson(
            "/api/v1/admin/security-policies/bulk",
            [
                "policies" => [
                    [
                        "key" => "attendance.late_threshold_minutes",
                        "value" => 20,
                        "scope_type" => "school",
                        "scope_id" => $this->school->id,
                    ],
                ],
            ],
        );

        $response->assertStatus(403);
    }

    // =========================================================================
    // SECURITY POLICY SERVICE INTEGRATION TESTS
    // =========================================================================

    public function test_policy_service_returns_school_specific_value(): void
    {
        // Create school-specific override
        DB::table("security_policies")->insert([
            "scope_type" => "school",
            "scope_id" => $this->school->id,
            "key" => "attendance.geofence_radius_meters",
            "value" => json_encode(100),
            "updated_by" => $this->superAdmin->id,
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $service = app(SecurityPolicyService::class);

        // School-specific value
        $schoolValue = $service->get(
            "attendance.geofence_radius_meters",
            $this->school->id,
        );
        $this->assertEquals(100, $schoolValue);

        // Global value for other school
        $globalValue = $service->get(
            "attendance.geofence_radius_meters",
            $this->otherSchool->id,
        );
        $this->assertEquals(50, $globalValue); // From seeded global policy
    }

    public function test_policy_service_falls_back_to_default(): void
    {
        // Delete all policies for a key
        DB::table("security_policies")
            ->where("key", "attendance.teacher_geofence_radius_meters")
            ->delete();

        $service = app(SecurityPolicyService::class);

        $value = $service->get("attendance.teacher_geofence_radius_meters");
        $this->assertEquals(200, $value); // From DEFAULTS constant
    }

    public function test_policy_service_caches_values(): void
    {
        $service = app(SecurityPolicyService::class);

        // First call - should hit database
        $value1 = $service->get("attendance.geofence_radius_meters");

        // Second call - should be cached
        $value2 = $service->get("attendance.geofence_radius_meters");

        $this->assertEquals($value1, $value2);

        // Verify cache key exists
        $cacheKey = "security_policy:attendance.geofence_radius_meters:global";
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_policy_service_clears_cache_on_update(): void
    {
        $service = app(SecurityPolicyService::class);

        // Populate cache
        $originalValue = $service->get("attendance.geofence_radius_meters");
        $this->assertEquals(50, $originalValue);

        // Update via API
        $policyId = DB::table("security_policies")
            ->where("scope_type", "global")
            ->where("key", "attendance.geofence_radius_meters")
            ->value("id");

        $this->actingAs($this->superAdmin, "sanctum")->putJson(
            "/api/v1/admin/security-policies/{$policyId}",
            [
                "value" => 75,
            ],
        );

        // Cache should be cleared, new value should be returned
        $newValue = $service->get("attendance.geofence_radius_meters");
        $this->assertEquals(75, $newValue);
    }

    // =========================================================================
    // AUDIT & ALERT TESTS
    // =========================================================================

    public function test_policy_change_logs_to_history_on_update(): void
    {
        $policyId = DB::table("security_policies")
            ->where("scope_type", "global")
            ->where("key", "attendance.geofence_radius_meters")
            ->value("id");

        $response = $this->actingAs($this->superAdmin, "sanctum")->putJson(
            "/api/v1/admin/security-policies/{$policyId}",
            [
                "value" => 75,
            ],
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas("security_policy_history", [
            "policy_id" => $policyId,
            "changed_by" => $this->superAdmin->id,
        ]);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    protected function createPolicyForSchool(
        int $schoolId,
        string $key,
        mixed $value,
    ): int {
        return DB::table("security_policies")->insertGetId([
            "scope_type" => "school",
            "scope_id" => $schoolId,
            "key" => $key,
            "value" => json_encode($value),
            "updated_by" => $this->superAdmin->id,
            "created_at" => now(),
            "updated_at" => now(),
        ]);
    }
}
