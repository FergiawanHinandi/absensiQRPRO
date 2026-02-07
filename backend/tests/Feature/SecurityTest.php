<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    // use RefreshDatabase; // Commented out to run on existing local DB for quick check if needed, but best practice is to use it.

    /**
     * Test SQL Injection Prevention.
     */
    public function test_sql_injection_is_blocked()
    {
        // Attempt to inject SQL into a likely query parameter (e.g. login)
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => "' OR '1'='1",
            'password' => "' OR '1'='1",
        ]);

        // Should return 422 (Validation Error) or 401 (Unauthorized), NOT 200 or 500
        $response->assertStatus(401);
    }

    /**
     * Test XSS Protection (Input Sanitization).
     */
    public function test_xss_payload_is_stripped()
    {
        $user = User::factory()->create();

        // Attempt to send a script tag in a text field (e.g., student name update)
        // Assuming there is an endpoint to update profile or similar.
        // For this test, we mock a request hitting the middleware directly or use a known endpoint.

        $payload = [
            'name' => '<script>alert("XSS")</script>John Doe',
        ];

        // Middleware should strip tags before it hits the controller
        $cleanName = 'John Doe';

        // Let's test via a POST request to a generic endpoint (or login if name logs it)
        // Ideally we test an endpoint that reflects input.

        // Simulating the middleware effect:
        $request = \Illuminate\Http\Request::create('/api/test', 'POST', $payload);
        $middleware = new \App\Http\Middleware\InputSanitization();

        $middleware->handle($request, function ($req) use ($cleanName) {
            $this->assertEquals($cleanName, $req->input('name'));
            return new \Illuminate\Http\Response();
        });
    }

    /**
     * Test Brute Force Rate Limiting.
     */
    public function test_brute_force_is_throttled()
    {
        $email = 'victim@example.com';

        // Exceed limit (5)
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => $email,
                'password' => 'wrong-password',
            ]);
        }

        // The 6th attempt should be blocked
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429); // Too Many Requests
    }

    /**
     * Test Unauthorized Data Access (IDOR).
     */
    public function test_unauthorized_access_blocked()
    {
        $userA = User::factory()->create(['role_type' => 'student', 'school_id' => 1]);
        $userB = User::factory()->create(['role_type' => 'student', 'school_id' => 2]); // Different school

        // User A tries to access User B's profile/data (assuming /api/v1/students/{id})
        $response = $this->actingAs($userA)
            ->getJson("/api/v1/students/{$userB->id}");

        // Application logic (Scoped bindings of BelongsToSchool) should return 404 or 403
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    /**
     * Test Malicious File Upload.
     */
    public function test_executed_file_upload_blocked()
    {
        $user = User::factory()->create(['role_type' => 'admin']);

        // Create a fake PHP file disguised as an image if validation is weak
        // But our validation rules should enforce mimes:jpg,png
        $file = UploadedFile::fake()->create('malicious.php', 100, 'application/x-php');

        // Target an upload endpoint (e.g. profile photo)
        $response = $this->actingAs($user)
            ->postJson('/api/v1/profile/photo', [
                'photo' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);
    }
}
