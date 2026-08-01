<?php

namespace Tests\Feature\Services;

use App\Exceptions\AttendanceException;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * QR Generation Atomic Lock Tests
 * 
 * Tests the Redis atomic locking mechanism for QR generation
 * to ensure only 1 active QR per session and proper idempotent behavior.
 */
class QRGenerationAtomicLockTest extends TestCase
{
    use RefreshDatabase;

    protected AttendanceService $service;
    protected User $teacher;
    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = app(AttendanceService::class);
        
        // Create test teacher
        $this->teacher = User::factory()->create([
            'role_type' => 'teacher',
            'school_id' => 1,
        ]);
        
        // Create test schedule for today
        $this->schedule = Schedule::factory()->create([
            'school_id' => 1,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => now()->subMinutes(5)->format('H:i:s'),
            'end_time' => now()->addHour()->format('H:i:s'),
            'is_active' => true,
        ]);
        
        // Clear Redis before each test
        Redis::flushdb();
    }

    protected function tearDown(): void
    {
        Redis::flushdb();
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_new_qr_when_no_active_session_exists()
    {
        Log::shouldReceive('channel->info')
            ->once()
            ->with('qr_generated', \Mockery::any());

        $result = $this->service->generateQR($this->schedule->id, $this->teacher);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('signature', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertEquals(60, $result['valid_for_seconds']);
        $this->assertArrayNotHasKey('reused', $result);

        // Verify Redis key exists
        $key = "qr_active:{$this->teacher->school_id}:{$this->schedule->id}";
        $this->assertTrue(Redis::exists($key) > 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_existing_qr_when_active_session_exists()
    {
        // First request - generates new QR
        $firstResult = $this->service->generateQR($this->schedule->id, $this->teacher);
        $firstToken = $firstResult['data']['token'];

        Log::shouldReceive('channel->info')
            ->once()
            ->with('qr_reused_existing', \Mockery::any());

        // Second request - should return same QR
        $secondResult = $this->service->generateQR($this->schedule->id, $this->teacher);
        $secondToken = $secondResult['data']['token'];

        $this->assertEquals($firstToken, $secondToken);
        $this->assertTrue($secondResult['reused']);
        $this->assertLessThanOrEqual(60, $secondResult['valid_for_seconds']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_race_condition_correctly()
    {
        $tokens = [];
        
        // Simulate 10 concurrent requests
        for ($i = 0; $i < 10; $i++) {
            $result = $this->service->generateQR($this->schedule->id, $this->teacher);
            $tokens[] = $result['data']['token'];
        }

        // All tokens should be identical
        $uniqueTokens = array_unique($tokens);
        $this->assertCount(1, $uniqueTokens);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_new_qr_after_expiry()
    {
        // Generate first QR with short TTL
        $firstResult = $this->service->generateQR($this->schedule->id, $this->teacher, 1);
        $firstToken = $firstResult['data']['token'];

        // Wait for expiry
        sleep(2);

        // Generate second QR - should be new
        $secondResult = $this->service->generateQR($this->schedule->id, $this->teacher);
        $secondToken = $secondResult['data']['token'];

        $this->assertNotEquals($firstToken, $secondToken);
        $this->assertArrayNotHasKey('reused', $secondResult);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_correct_redis_key_format()
    {
        $this->service->generateQR($this->schedule->id, $this->teacher);

        $expectedKey = "qr_active:{$this->teacher->school_id}:{$this->schedule->id}";
        $this->assertTrue(Redis::exists($expectedKey) > 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_stores_complete_qr_data_in_redis()
    {
        $this->service->generateQR($this->schedule->id, $this->teacher);

        $key = "qr_active:{$this->teacher->school_id}:{$this->schedule->id}";
        $data = json_decode(Redis::get($key), true);

        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('payload', $data);
        $this->assertArrayHasKey('session_id', $data);
        $this->assertArrayHasKey('teacher_id', $data);
        $this->assertArrayHasKey('school_id', $data);
        $this->assertArrayHasKey('created_at', $data);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maintains_backward_compatibility_with_token_lookup()
    {
        $result = $this->service->generateQR($this->schedule->id, $this->teacher);
        $token = $result['data']['token'];

        // Verify token-based key also exists
        $tokenKey = "qr_session:{$token}";
        $this->assertTrue(Redis::exists($tokenKey) > 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_respects_custom_expiry_seconds()
    {
        $customExpiry = 120;
        $result = $this->service->generateQR($this->schedule->id, $this->teacher, $customExpiry);

        $this->assertEquals($customExpiry, $result['valid_for_seconds']);

        $key = "qr_active:{$this->teacher->school_id}:{$this->schedule->id}";
        $ttl = Redis::ttl($key);
        $this->assertGreaterThan(110, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_isolates_qr_by_school_id()
    {
        // Create another teacher from different school
        $otherTeacher = User::factory()->create([
            'role_type' => 'teacher',
            'school_id' => 2,
        ]);

        $otherSchedule = Schedule::factory()->create([
            'school_id' => 2,
            'teacher_id' => $otherTeacher->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => now()->subMinutes(5)->format('H:i:s'),
            'end_time' => now()->addHour()->format('H:i:s'),
            'is_active' => true,
        ]);

        // Generate QR for both schools
        $result1 = $this->service->generateQR($this->schedule->id, $this->teacher);
        $result2 = $this->service->generateQR($otherSchedule->id, $otherTeacher);

        // Tokens should be different (different schools)
        $this->assertNotEquals($result1['data']['token'], $result2['data']['token']);

        // Both Redis keys should exist
        $key1 = "qr_active:1:{$this->schedule->id}";
        $key2 = "qr_active:2:{$otherSchedule->id}";
        $this->assertTrue(Redis::exists($key1) > 0);
        $this->assertTrue(Redis::exists($key2) > 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_schedule_not_found()
    {
        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('Sesi tidak ditemukan');

        $this->service->generateQR(99999, $this->teacher);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_schedule_inactive()
    {
        $this->schedule->update(['is_active' => false]);

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->service->generateQR($this->schedule->id, $this->teacher);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_teacher_not_authorized()
    {
        $otherTeacher = User::factory()->create([
            'role_type' => 'teacher',
            'school_id' => 1,
        ]);

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('tidak memiliki akses');

        $this->service->generateQR($this->schedule->id, $otherTeacher);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_qr_generated_event()
    {
        Log::shouldReceive('channel')
            ->with('audit')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('qr_generated', \Mockery::on(function ($data) {
                return isset($data['teacher_id']) &&
                       isset($data['session_id']) &&
                       isset($data['token']) &&
                       isset($data['expires_at']);
            }));

        $this->service->generateQR($this->schedule->id, $this->teacher);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_qr_reused_event()
    {
        // First generation
        $this->service->generateQR($this->schedule->id, $this->teacher);

        Log::shouldReceive('channel')
            ->with('audit')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('qr_reused_existing', \Mockery::on(function ($data) {
                return isset($data['teacher_id']) &&
                       isset($data['token']) &&
                       isset($data['ttl_remaining']);
            }));

        // Second generation (reuse)
        $this->service->generateQR($this->schedule->id, $this->teacher);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_includes_session_info_in_response()
    {
        $result = $this->service->generateQR($this->schedule->id, $this->teacher);

        $this->assertArrayHasKey('session_info', $result);
        $this->assertArrayHasKey('class', $result['session_info']);
        $this->assertArrayHasKey('subject', $result['session_info']);
        $this->assertArrayHasKey('start_time', $result['session_info']);
        $this->assertArrayHasKey('end_time', $result['session_info']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_valid_hmac_signature()
    {
        $result = $this->service->generateQR($this->schedule->id, $this->teacher);

        $expectedSignature = hash_hmac(
            'sha256',
            json_encode($result['data']),
            config('app.key')
        );

        $this->assertEquals($expectedSignature, $result['signature']);
    }
}
