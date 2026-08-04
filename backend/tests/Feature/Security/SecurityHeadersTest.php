<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_json_response_has_strict_csp()
    {
        // Any API endpoint returning JSON triggers the strict CSP
        $response = $this->postJson('/api/v1/attendance/scan', []);

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_security_headers_present_on_api()
    {
        $response = $this->postJson('/api/v1/attendance/scan', []);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Request-ID');
    }
}
