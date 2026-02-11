<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\IdempotencyMiddleware;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit tests for IdempotencyMiddleware
 *
 * @group middleware
 * @group idempotency
 */
class IdempotencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected IdempotencyMiddleware $middleware;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->middleware = new IdempotencyMiddleware();
        $this->user = User::factory()->create();
    }

    /**
     * @test
     */
    public function safe_methods_bypass_idempotency_check(): void
    {
        $request = Request::create('/api/v1/attendance', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $this->middleware->handle($request, fn($req) => new JsonResponse(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function missing_idempotency_key_returns_400(): void
    {
        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->setUserResolver(fn() => $this->user);

        $response = $this->middleware->handle($request, fn($req) => new JsonResponse(['ok' => true]));

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('MISSING_IDEMPOTENCY_KEY', $response->getData()->code);
    }

    /**
     * @test
     */
    public function invalid_uuid_format_returns_400(): void
    {
        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->headers->set('X-Idempotency-Key', 'not-a-valid-uuid');
        $request->setUserResolver(fn() => $this->user);

        $response = $this->middleware->handle($request, fn($req) => new JsonResponse(['ok' => true]));

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('INVALID_IDEMPOTENCY_KEY', $response->getData()->code);
    }

    /**
     * @test
     */
    public function valid_uuid_v4_is_accepted(): void
    {
        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->headers->set('X-Idempotency-Key', (string) Str::uuid());
        $request->setUserResolver(fn() => $this->user);

        $response = $this->middleware->handle($request, fn($req) => new JsonResponse(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function duplicate_key_returns_409_conflict(): void
    {
        $idempotencyKey = (string) Str::uuid();
        $endpoint = 'api/v1/attendance/scan';

        // Create existing key
        IdempotencyKey::create([
            'key' => $idempotencyKey,
            'user_id' => $this->user->id,
            'endpoint' => $endpoint,
            'http_method' => 'POST',
            'response_payload' => json_encode(['success' => true]),
            'response_status' => 200,
            'expires_at' => now()->addMinutes(2),
        ]);

        $request = Request::create("/{$endpoint}", 'POST');
        $request->headers->set('X-Idempotency-Key', $idempotencyKey);
        $request->setUserResolver(fn() => $this->user);

        $response = $this->middleware->handle($request, fn($req) => new JsonResponse(['ok' => true]));

        $this->assertEquals(409, $response->getStatusCode());
        $this->assertEquals('DUPLICATE_SUBMISSION', $response->getData()->code);
        $this->assertTrue($response->headers->has('X-Duplicate-Request'));
    }

    /**
     * @test
     */
    public function successful_request_stores_idempotency_key(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->headers->set('X-Idempotency-Key', $idempotencyKey);
        $request->setUserResolver(fn() => $this->user);

        $this->middleware->handle($request, fn($req) => new JsonResponse(['success' => true]));

        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $idempotencyKey,
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * @test
     */
    public function idempotency_key_respects_ttl_parameter(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->headers->set('X-Idempotency-Key', $idempotencyKey);
        $request->setUserResolver(fn() => $this->user);

        // Pass TTL of 5 minutes
        $this->middleware->handle($request, fn($req) => new JsonResponse(['success' => true]), 5);

        $storedKey = IdempotencyKey::where('key', $idempotencyKey)->first();
        
        $this->assertNotNull($storedKey);
        $this->assertTrue($storedKey->expires_at->isFuture());
        $this->assertEqualsWithDelta(
            now()->addMinutes(5)->timestamp,
            $storedKey->expires_at->timestamp,
            5 // Allow 5 seconds variance
        );
    }

    /**
     * @test
     */
    public function response_includes_idempotency_key_header(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->headers->set('X-Idempotency-Key', $idempotencyKey);
        $request->setUserResolver(fn() => $this->user);

        $response = $this->middleware->handle($request, fn($req) => new JsonResponse(['success' => true]));

        $this->assertEquals($idempotencyKey, $response->headers->get('X-Idempotency-Key'));
    }

    /**
     * @test
     */
    public function failed_response_does_not_store_key(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->headers->set('X-Idempotency-Key', $idempotencyKey);
        $request->setUserResolver(fn() => $this->user);

        $this->middleware->handle($request, fn($req) => new JsonResponse(['error' => 'failed'], 500));

        $this->assertDatabaseMissing('idempotency_keys', [
            'key' => $idempotencyKey,
        ]);
    }

    /**
     * @test
     */
    public function optional_idempotency_key_allows_request_without_header(): void
    {
        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->setUserResolver(fn() => $this->user);

        // Pass required=false
        $response = $this->middleware->handle(
            $request, 
            fn($req) => new JsonResponse(['ok' => true]),
            null,  // TTL
            false  // required=false
        );

        $this->assertEquals(200, $response->getStatusCode());
    }
}
