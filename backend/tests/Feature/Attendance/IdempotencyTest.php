<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Models\School;
use App\Models\Schedule;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IdempotencyTest
 * Test idempotency key protection for attendance requests.
 * SCENARIO:
 * - Send 5 requests with the same idempotency key
 * - Only the first request should succeed (201 Created)
 * - Remaining 4 should be rejected (409 Conflict)
 * PROTECTION MECHANISMS:
 * - Idempotency key validation
 * - Database unique constraint on idempotency_keys table
 * - Request deduplication
 */
#[\PHPUnit\Framework\Attributes\Group('idempotency')]
#[\PHPUnit\Framework\Attributes\Group('attendance')]
#[\PHPUnit\Framework\Attributes\Group('critical')]
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $student;
    protected User $teacher;
    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school
        $this->school = School::factory()->create(['name' => 'Test School']);

        // Create teacher
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
            'email' => 'teacher@test.com',
        ]);

        // Create student
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'email' => 'student@test.com',
        ]);

        // Create schedule for today
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'date' => today(),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    /**
     * Test idempotency key prevents duplicate requests
     * SCENARIO:
     * - Send 5 requests with the same idempotency key
     * - Only first request succeeds (201)
     * - Remaining 4 requests rejected (409)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_duplicate_requests_with_same_idempotency_key()
    {
        $idempotencyKey = Str::uuid()->toString();

        $checkInData = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now()->format('Y-m-d H:i:s'),
            'lat_in' => -6.200000,
            'lng_in' => 106.816666,
            'device_id_in' => 'test-device-001',
        ];

        $results = [];

        // Send 5 requests with the same idempotency key
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->actingAs($this->teacher)
                ->withHeader('X-Idempotency-Key', $idempotencyKey)
                ->postJson('/api/v1/attendances/check-in', $checkInData);

            $results[] = [
                'request' => $i,
                'status' => $response->status(),
                'response' => $response->json(),
            ];

            // Log each request
            echo sprintf(
                "Request #%d: %d %s\n",
                $i,
                $response->status(),
                $response->status() === 201 ? 'Created' : 'Conflict'
            );
        }

        // Assertions
        $successCount = collect($results)->where('status', 201)->count();
        $conflictCount = collect($results)->where('status', 409)->count();

        $this->assertEquals(1, $successCount, "Expected exactly 1 successful request");
        $this->assertEquals(4, $conflictCount, "Expected exactly 4 rejected requests");

        // Verify first request succeeded
        $this->assertEquals(201, $results[0]['status'], "First request should succeed");

        // Verify remaining requests were rejected
        for ($i = 1; $i < 5; $i++) {
            $this->assertEquals(409, $results[$i]['status'], "Request #" . ($i + 1) . " should be rejected");
        }

        // Verify idempotency key is stored
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $idempotencyKey,
            'status' => 'completed',
        ]);

        // Verify only 1 idempotency key record exists
        $keyCount = IdempotencyKey::where('key', $idempotencyKey)->count();
        $this->assertEquals(1, $keyCount, "Expected exactly 1 idempotency key record");
    }

    /**
     * Test different idempotency keys allow multiple requests
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_multiple_requests_with_different_idempotency_keys()
    {
        $checkInData = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now()->format('Y-m-d H:i:s'),
            'lat_in' => -6.200000,
            'lng_in' => 106.816666,
            'device_id_in' => 'test-device-001',
        ];

        // First request with idempotency key 1
        $response1 = $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/v1/attendances/check-in', $checkInData);

        $this->assertEquals(201, $response1->status());

        // Second request with idempotency key 2 (different student to avoid unique constraint)
        $student2 = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);

        $checkInData['student_id'] = $student2->id;

        $response2 = $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/v1/attendances/check-in', $checkInData);

        $this->assertEquals(201, $response2->status());

        // Both requests should succeed with different idempotency keys
        $this->assertEquals(2, IdempotencyKey::count());
    }

    /**
     * Test idempotency key expires after TTL
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_request_after_idempotency_key_expires()
    {
        $idempotencyKey = Str::uuid()->toString();

        $checkInData = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now()->format('Y-m-d H:i:s'),
            'lat_in' => -6.200000,
            'lng_in' => 106.816666,
            'device_id_in' => 'test-device-001',
        ];

        // First request
        $response1 = $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/attendances/check-in', $checkInData);

        $this->assertEquals(201, $response1->status());

        // Manually expire the idempotency key
        IdempotencyKey::where('key', $idempotencyKey)
            ->update(['expires_at' => now()->subHour()]);

        // Delete the attendance to avoid unique constraint
        \App\Models\Attendance::where('student_id', $this->student->id)->delete();

        // Second request with same key (after expiry)
        $response2 = $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/attendances/check-in', $checkInData);

        // Should succeed because key expired
        $this->assertEquals(201, $response2->status());
    }

    /**
     * Test idempotency key validation
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_idempotency_key_format()
    {
        $checkInData = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now()->format('Y-m-d H:i:s'),
        ];

        // Invalid idempotency key (too short)
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', 'short')
            ->postJson('/api/v1/attendances/check-in', $checkInData);

        $this->assertEquals(422, $response->status());
        $this->assertStringContainsString('idempotency', strtolower($response->json('message')));
    }

    /**
     * Test idempotency key returns cached response
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_cached_response_for_duplicate_request()
    {
        $idempotencyKey = Str::uuid()->toString();

        $checkInData = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now()->format('Y-m-d H:i:s'),
            'lat_in' => -6.200000,
            'lng_in' => 106.816666,
            'device_id_in' => 'test-device-001',
        ];

        // First request
        $response1 = $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/attendances/check-in', $checkInData);

        $this->assertEquals(201, $response1->status());
        $attendanceId = $response1->json('data.id');

        // Second request with same key
        $response2 = $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/attendances/check-in', $checkInData);

        // Should return cached response
        $this->assertEquals(409, $response2->status());
        $this->assertStringContainsString('duplicate', strtolower($response2->json('message')));

        // Verify idempotency key has cached response
        $key = IdempotencyKey::where('key', $idempotencyKey)->first();
        $this->assertNotNull($key);
        $this->assertNotNull($key->response_body);
        $this->assertEquals(201, $key->response_status);
    }

    /**
     * Test concurrent requests with same idempotency key
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_concurrent_requests_with_same_idempotency_key()
    {
        $idempotencyKey = Str::uuid()->toString();

        $checkInData = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now()->format('Y-m-d H:i:s'),
            'lat_in' => -6.200000,
            'lng_in' => 106.816666,
            'device_id_in' => 'test-device-001',
        ];

        $results = [];

        // Simulate concurrent requests (in reality, these are sequential in PHPUnit)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($this->teacher)
                ->withHeader('X-Idempotency-Key', $idempotencyKey)
                ->postJson('/api/v1/attendances/check-in', $checkInData);

            $results[] = $response->status();
        }

        // Count successes and conflicts
        $successCount = collect($results)->filter(fn($status) => $status === 201)->count();
        $conflictCount = collect($results)->filter(fn($status) => $status === 409)->count();

        // Should have exactly 1 success and 4 conflicts
        $this->assertEquals(1, $successCount);
        $this->assertEquals(4, $conflictCount);

        // Verify only 1 idempotency key record
        $this->assertEquals(1, IdempotencyKey::where('key', $idempotencyKey)->count());
    }

    /**
     * Test idempotency key cleanup
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_cleans_up_expired_idempotency_keys()
    {
        // Create expired idempotency key
        IdempotencyKey::create([
            'key' => Str::uuid()->toString(),
            'user_id' => $this->teacher->id,
            'endpoint' => '/api/v1/attendances/check-in',
            'request_hash' => hash('sha256', 'test'),
            'status' => 'completed',
            'response_status' => 201,
            'response_body' => json_encode(['success' => true]),
            'expires_at' => now()->subDay(),
        ]);

        // Create active idempotency key
        IdempotencyKey::create([
            'key' => Str::uuid()->toString(),
            'user_id' => $this->teacher->id,
            'endpoint' => '/api/v1/attendances/check-in',
            'request_hash' => hash('sha256', 'test2'),
            'status' => 'completed',
            'response_status' => 201,
            'response_body' => json_encode(['success' => true]),
            'expires_at' => now()->addDay(),
        ]);

        // Run cleanup command
        $this->artisan('idempotency:cleanup')
            ->assertSuccessful();

        // Verify expired key is deleted
        $this->assertEquals(1, IdempotencyKey::count());

        // Verify active key remains
        $this->assertEquals(1, IdempotencyKey::where('expires_at', '>', now())->count());
    }
}
