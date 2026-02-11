<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that school can be created with timezone
     */
    public function test_school_can_be_created_with_timezone(): void
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Tokyo',
        ]);

        $this->assertDatabaseHas('schools', [
            'id' => $school->id,
            'timezone' => 'Asia/Tokyo',
        ]);
    }

    /**
     * Test that school defaults to Asia/Jakarta timezone
     */
    public function test_school_defaults_to_jakarta_timezone(): void
    {
        $school = School::factory()->create();

        $this->assertEquals('Asia/Jakarta', $school->timezone);
    }

    /**
     * Test that super admin can create school with timezone
     */
    public function test_super_admin_can_create_school_with_timezone(): void
    {
        $superAdmin = User::factory()->create([
            'role_type' => 'super_admin',
        ]);
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/super-admin/schools', [
            'name' => 'Test School',
            'npsn' => '12345678',
            'school_level' => 'SMA',
            'address' => 'Test Address',
            'email' => 'test@school.com',
            'phone' => '081234567890',
            'timezone' => 'Asia/Makassar',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('schools', [
            'name' => 'Test School',
            'timezone' => 'Asia/Makassar',
        ]);
    }

    /**
     * Test that school admin can update school timezone
     */
    public function test_school_admin_can_update_school_timezone(): void
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Jakarta',
        ]);

        $schoolAdmin = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'school_admin',
        ]);
        $schoolAdmin->assignRole('school_admin');

        $response = $this->actingAs($schoolAdmin, 'sanctum')->putJson('/api/v1/school-admin/school/profile', [
            'timezone' => 'Asia/Jayapura',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('schools', [
            'id' => $school->id,
            'timezone' => 'Asia/Jayapura',
        ]);
    }

    /**
     * Test that invalid timezone is rejected
     */
    public function test_invalid_timezone_is_rejected(): void
    {
        $superAdmin = User::factory()->create([
            'role_type' => 'super_admin',
        ]);
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/super-admin/schools', [
            'name' => 'Test School',
            'npsn' => '12345678',
            'school_level' => 'SMA',
            'address' => 'Test Address',
            'email' => 'test@school.com',
            'phone' => '081234567890',
            'timezone' => 'Invalid/Timezone',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['timezone']);
    }
}
