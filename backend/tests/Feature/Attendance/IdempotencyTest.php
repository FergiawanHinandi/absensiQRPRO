<?php

namespace Tests\Feature\Attendance;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\IdempotencyKey;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
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
    protected Student $student;
    protected User $teacher;
    protected Schedule $schedule;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school (location aligned with scan coordinates)
        $this->school = School::factory()->create([
            'name' => 'Test School',
            'latitude' => -6.2,
            'longitude' => 106.816666,
            'radius_meters' => 500,
        ]);

        // Create academic year
        $academicYear = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
        ]);

        // Create class
        $class = ClassModel::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $academicYear->id,
        ]);

        // Create subject
        $subject = Subject::factory()->create([
            'school_id' => $this->school->id,
        ]);

        // Create teacher
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
            'email' => 'teacher@test.com',
        ]);

        // Create student
        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
            'email' => 'student@test.com',
        ]);

        // Create schedule for today
        $this->schedule = Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'subject_id' => $subject->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => Carbon::now()->dayOfWeek,
            'start_time' => Carbon::now()->subMinutes(10)->format('H:i:s'),
            'end_time' => Carbon::now()->addMinutes(50)->format('H:i:s'),
        ]);

        // Generate valid QR token (payload covers both QR verification layers)
        $this->token = $this->generateQrToken($this->schedule, $this->school->id);
    }

    /**
     * Generate a valid QR token for the schedule.
     */
    private function generateQrToken(Schedule $schedule, int $schoolId): string
    {
        $payload = [
            'sid' => $schedule->id,
            'sch' => $schoolId,
            'iat' => now()->timestamp,
            'schedule_id' => $schedule->id,
            'nonce' => Str::random(16),
        ];

        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));

        return $encoded.'.'.$signature;
    }

    /**
     * Send a scan request as the student with the given idempotency key.
     */
    private function scanRequest(string $idempotencyKey, ?Student $user = null)
    {
        Sanctum::actingAs($user ?? $this->student, ['*']);

        $response = $this->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $this->token,
                'latitude' => -6.200000,
                'longitude' => 106.816666,
                'accuracy' => 10,
                'device_fingerprint' => 'test-device-fingerprint',
                'request_id' => (string) Str::uuid(),
            ]);

        return $response;
    }

    /**
     * Test idempotency key prevents duplicate requests
     * SCENARIO:
     * - Send 5 requests with the same idempotency key
     * - Only the first request is processed (201 Created)
     * - Remaining 4 requests get the cached response (same 201 body)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_duplicate_requests_with_same_idempotency_key()
    {
        $idempotencyKey = Str::uuid()->toString();

        $results = [];

        // Send 5 requests with the same idempotency key
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->scanRequest($idempotencyKey);

            $results[] = [
                'request' => $i,
                'status' => $response->status(),
                'response' => $response->json(),
            ];
        }

        // Assertions
        $successCount = collect($results)->where('status', 201)->count();

        $this->assertEquals(5, $successCount, "Expected 1 processed request + 4 cached responses");

        // Verify first request succeeded
        $this->assertEquals(201, $results[0]['status'], "First request should succeed");
        $attendanceId = $results[0]['response']['data']['attendance']['id'] ?? null;

        // Verify remaining requests returned the cached response (same attendance)
        for ($i = 1; $i < 5; $i++) {
            $this->assertEquals(201, $results[$i]['status'], "Request #" . ($i + 1) . " should return cached response");
            $cachedAttendanceId = $results[$i]['response']['data']['attendance']['id'] ?? null;
            $this->assertEquals($attendanceId, $cachedAttendanceId, "Cached response should reference the same attendance");
        }

        // Verify idempotency key is stored
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $idempotencyKey,
            'response_status' => 201,
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
        // First request with idempotency key 1
        $response1 = $this->scanRequest(Str::uuid()->toString());

        $this->assertEquals(201, $response1->status());

        // Second request with idempotency key 2 (different student)
        $student2 = Student::factory()->create([
            'school_id' => $this->school->id,
        ]);

        $response2 = $this->scanRequest(Str::uuid()->toString(), $student2);

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

        // First request
        $response1 = $this->scanRequest($idempotencyKey);

        $this->assertEquals(201, $response1->status());

        // Manually expire the idempotency key
        IdempotencyKey::where('key', $idempotencyKey)
            ->update(['expires_at' => now()->subHour()]);

        // Delete the attendance to avoid unique constraint
        Attendance::where('student_id', $this->student->id)->delete();

        // Second request with same key (after expiry)
        $response2 = $this->scanRequest($idempotencyKey);

        // Should succeed because key expired
        $this->assertEquals(201, $response2->status());
    }

    /**
     * Test idempotency key validation
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_idempotency_key_format()
    {
        // Invalid idempotency key (too short)
        $response = $this->scanRequest('short');

        $this->assertEquals(400, $response->status());
        $this->assertStringContainsString('idempotency', strtolower($response->json('message')));
    }

    /**
     * Test idempotency key returns cached response
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_cached_response_for_duplicate_request()
    {
        $idempotencyKey = Str::uuid()->toString();

        // First request
        $response1 = $this->scanRequest($idempotencyKey);

        $this->assertEquals(201, $response1->status());
        $attendanceId = $response1->json('data.attendance.id');

        // Second request with same key
        $response2 = $this->scanRequest($idempotencyKey);

        // Should return the cached response (same 201, same attendance)
        $this->assertEquals(201, $response2->status());
        $this->assertEquals($attendanceId, $response2->json('data.attendance.id'));

        // Verify idempotency key has cached response
        $key = IdempotencyKey::where('key', $idempotencyKey)->first();
        $this->assertNotNull($key);
        $this->assertNotNull($key->response_payload);
        $this->assertEquals(201, $key->response_status);
    }

    /**
     * Test concurrent requests with same idempotency key
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_concurrent_requests_with_same_idempotency_key()
    {
        $idempotencyKey = Str::uuid()->toString();

        $results = [];

        // Simulate concurrent requests (in reality, these are sequential in PHPUnit)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->scanRequest($idempotencyKey);

            $results[] = $response->status();
        }

        // Count successes (1 processed + 4 cached responses)
        $successCount = collect($results)->filter(fn($status) => $status === 201)->count();

        // Should have exactly 5 successful (processed + cached) responses
        $this->assertEquals(5, $successCount);

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
            'endpoint' => '/api/v1/attendance/scan',
            'response_payload' => json_encode(['success' => true]),
            'response_status' => 201,
            'expires_at' => now()->subDay(),
        ]);

        // Create active idempotency key
        IdempotencyKey::create([
            'key' => Str::uuid()->toString(),
            'user_id' => $this->teacher->id,
            'endpoint' => '/api/v1/attendance/scan',
            'response_payload' => json_encode(['success' => true]),
            'response_status' => 201,
            'expires_at' => now()->addDay(),
        ]);

        // Run cleanup command
        $this->artisan('idempotency:cleanup', ['--force' => true])
            ->assertSuccessful();

        // Verify expired key is deleted
        $this->assertEquals(1, IdempotencyKey::count());

        // Verify active key remains
        $this->assertEquals(1, IdempotencyKey::where('expires_at', '>', now())->count());
    }
}
