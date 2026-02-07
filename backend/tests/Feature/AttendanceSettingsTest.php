<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $school;

    protected $schoolAdmin;

    protected $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();

        $this->schoolAdmin = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'school_admin',
        ]);

        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);
    }

    /**
     * Test QR Mode Settings
     */
    public function test_only_school_admin_can_update_qr_mode_settings()
    {
        $payload = [
            'qr_expiry_seconds' => 45,
            'qr_regeneration_cooldown' => 10,
        ];

        // Teacher attempt (should fail)
        $this->actingAs($this->teacher)
            ->postJson('/api/v1/admin/attendance-settings/qr-mode', $payload)
            ->assertForbidden();

        // Admin attempt (should success)
        $this->actingAs($this->schoolAdmin)
            ->postJson('/api/v1/admin/attendance-settings/qr-mode', $payload)
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_qr_mode_settings_validation()
    {
        $this->actingAs($this->schoolAdmin)
            ->postJson('/api/v1/admin/attendance-settings/qr-mode', [
                'qr_expiry_seconds' => 1, // Too small (min: 5)
                'qr_regeneration_cooldown' => 100, // Too big (max: 30)
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['qr_expiry_seconds', 'qr_regeneration_cooldown']);
    }

    public function test_qr_mode_settings_are_stored_correctly()
    {
        $payload = [
            'qr_expiry_seconds' => 50,
            'qr_regeneration_cooldown' => 15,
        ];

        $this->actingAs($this->schoolAdmin)
            ->postJson('/api/v1/admin/attendance-settings/qr-mode', $payload)
            ->assertOk();

        $this->school->refresh();
        $settings = $this->school->settings;

        $this->assertEquals(50, $settings['qr_expiry_seconds']);
        $this->assertEquals(15, $settings['qr_regeneration_cooldown']);
    }

    /**
     * Test Override Settings
     */
    public function test_only_school_admin_can_update_override_settings()
    {
        $payload = [
            'allow_teacher_override' => true,
            'require_override_reason' => true,
        ];

        $this->actingAs($this->teacher)
            ->postJson('/api/v1/admin/attendance-settings/override', $payload)
            ->assertForbidden();

        $this->actingAs($this->schoolAdmin)
            ->postJson('/api/v1/admin/attendance-settings/override', $payload)
            ->assertOk();
    }

    public function test_override_settings_validation()
    {
        $this->actingAs($this->schoolAdmin)
            ->postJson('/api/v1/admin/attendance-settings/override', [
                'allow_teacher_override' => 'not-boolean',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allow_teacher_override']);
    }

    /**
     * Test Tolerance Settings
     */
    public function test_only_school_admin_can_update_tolerance_settings()
    {
        $payload = [
            'late_tolerance_minutes' => 20,
            'early_check_in_allowed' => false,
        ];

        $this->actingAs($this->teacher)
            ->postJson('/api/v1/admin/attendance-settings/tolerance', $payload)
            ->assertForbidden();

        $this->actingAs($this->schoolAdmin)
            ->postJson('/api/v1/admin/attendance-settings/tolerance', $payload)
            ->assertOk();
    }

    public function test_tolerance_settings_validation()
    {
        $this->actingAs($this->schoolAdmin)
            ->postJson('/api/v1/admin/attendance-settings/tolerance', [
                'late_tolerance_minutes' => -5, // Invalid
            ])
            ->assertStatus(422);
    }

    /**
     * Test Data Integrity (Updates don't overwrite other settings)
     */
    public function test_updating_one_setting_does_not_overwrite_others()
    {
        // 1. Set QR Mode
        $this->actingAs($this->schoolAdmin)
            ->postJson('/api/v1/admin/attendance-settings/qr-mode', [
                'qr_expiry_seconds' => 40,
                'qr_regeneration_cooldown' => 10,
            ])
            ->assertOk();

        // 2. Set Tolerance (should not delete QR settings)
        $this->actingAs($this->schoolAdmin)
            ->postJson('/api/v1/admin/attendance-settings/tolerance', [
                'late_tolerance_minutes' => 25,
                'early_check_in_allowed' => true,
            ])
            ->assertOk();

        $this->school->refresh();
        $settings = $this->school->settings;

        // Check if QR settings still exist
        $this->assertEquals(40, $settings['qr_expiry_seconds']);
        $this->assertEquals(10, $settings['qr_regeneration_cooldown']);

        // Check if Tolerance settings exist
        $this->assertEquals(25, $settings['late_tolerance_minutes']);
        $this->assertTrue($settings['early_check_in_allowed']);
    }
}
