<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials()
    {
        $school = School::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'school_id' => $school->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
            'device_name' => 'test-device',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user' => [
                        'id',
                        'username',
                        'email',
                    ],
                ],
            ]);
    }

    public function test_user_cannot_login_with_invalid_credentials()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'invalid',
            'password' => 'wrong',
            'device_name' => 'test-device',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    public function test_inactive_user_cannot_login()
    {
        $school = School::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => bcrypt('password123'),
            'is_active' => false,
            'school_id' => $school->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
            'device_name' => 'test-device',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_rate_limiting_works()
    {
        // Attempt login 6 times (exceeds 5 per minute limit)
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'username' => 'invalid',
                'password' => 'wrong',
                'device_name' => 'test-device',
            ]);
        }

        $response->assertStatus(429); // Too Many Requests
    }
}
