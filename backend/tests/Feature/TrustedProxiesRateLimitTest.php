<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SEC-3: trustProxies must not be '*' — otherwise attackers can spoof
 * X-Forwarded-For and bypass every IP-based rate limit (incl. login).
 *
 * With the fix, the test client (127.0.0.1) is NOT a trusted proxy, so
 * spoofed X-Forwarded-For headers must be ignored and the 6th login
 * attempt within one minute must be throttled (429).
 */
class TrustedProxiesRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function spoofed_x_forwarded_for_headers_cannot_bypass_login_rate_limit(): void
    {
        $payload = [
            'username' => 'nobody@example.com',
            'password' => 'wrong-password',
        ];

        $spoofedIps = [
            '203.0.113.1',
            '203.0.113.2',
            '203.0.113.3',
            '203.0.113.4',
            '203.0.113.5',
            '203.0.113.6',
        ];

        $statuses = [];
        foreach ($spoofedIps as $ip) {
            $statuses[] = $this->postJson('/api/v1/auth/login', $payload, [
                'X-Forwarded-For' => $ip,
                'X-Forwarded-Host' => 'spoofed.example.com',
            ])->getStatusCode();
        }

        // 5 attempts allowed per minute per IP; the 6th must be throttled.
        $this->assertSame(429, $statuses[5], 'IP-based rate limit was bypassed via X-Forwarded-For spoofing');
        $this->assertNotSame(429, $statuses[0]);
    }
}
