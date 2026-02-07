/**
 * Load Test: Security Events Logging - High Volume Invalid QR Scans
 * 
 * Simulates 2000 invalid QR scan attempts to test:
 * 1. Security events are logged asynchronously
 * 2. Attendance scan endpoint response time is not degraded
 * 
 * Test Profile:
 * - 2000 invalid QR scans
 * - Concurrent: 100 users
 * - Duration: until all requests complete
 * 
 * Pass Criteria:
 * - Response time remains < 500ms (not degraded by logging)
 * - All security events logged successfully
 * - Error responses returned correctly (401/422)
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// Custom metrics
const responseTrend = new Trend('response_time');
const slowRequests = new Counter('slow_requests');
const totalRequests = new Counter('total_requests');
const securityEvents = new Counter('security_events');

// Test configuration - 2000 requests total
export const options = {
    scenarios: {
        invalid_scans: {
            executor: 'shared-iterations',
            vus: 100,                    // 100 concurrent users
            iterations: 2000,             // 2000 total invalid scan attempts
            maxDuration: '5m',            // Maximum 5 minutes
        },
    },
    thresholds: {
        'http_req_duration': ['p(95)<500'],    // Response time should not degrade
        'slow_requests': ['count<100'],         // Less than 5% slow requests
        'http_req_failed': ['rate>0.95'],      // Should fail (invalid scans)
    },
};

// Configuration
const BASE_URL = __ENV.API_URL || 'http://localhost:8000';
const ENDPOINT = `${BASE_URL}/api/v1/student/scan-attendance`;

// Generate invalid QR tokens (various invalid patterns)
function getInvalidQRToken() {
    const patterns = [
        'invalid-token-expired',
        'malformed-qr-12345',
        'tampered-signature-xxx',
        'replayed-token-old',
        'wrong-school-token',
        '',                              // Empty token
        'a'.repeat(500),                 // Too long
        'special-chars-!@#$%',
        'sql-injection-attempt',
        'xss-attempt-<script>',
    ];

    const pattern = patterns[Math.floor(Math.random() * patterns.length)];
    const timestamp = Date.now() - (Math.random() * 3600000); // Random old timestamp

    return `${pattern}-${timestamp}-${Math.random().toString(36).substring(7)}`;
}

export default function () {
    const invalidToken = getInvalidQRToken();

    const payload = JSON.stringify({
        qr_token: invalidToken,
        student_id: `student-${__VU}-${__ITER}`,
        session_id: 'security-test-session',
        latitude: -6.200000 + (Math.random() * 0.01),
        longitude: 106.816666 + (Math.random() * 0.01),
        device_id: `device-test-${__VU}`,
        timestamp: new Date().toISOString(),
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer invalid-token-for-security-test',
            'X-Test-Type': 'security-logging',
        },
        tags: {
            name: 'InvalidQRScan',
            test_type: 'security_logging',
        },
    };

    // Measure request start time
    const startTime = Date.now();

    // Execute request (expecting failure)
    const response = http.post(ENDPOINT, payload, params);

    // Measure response time
    const responseTime = Date.now() - startTime;
    responseTrend.add(responseTime);
    totalRequests.add(1);

    // Track slow requests (> 500ms indicates logging is blocking)
    if (responseTime > 500) {
        slowRequests.add(1);
        console.warn(`⚠️  SLOW REQUEST: ${responseTime}ms (logging may be blocking)`);
    }

    // Validate response
    const isValidSecurityResponse = check(response, {
        'returns error status (401/422/403)': (r) =>
            r.status === 401 || r.status === 422 || r.status === 403,
        'response time < 500ms (not degraded)': (r) => responseTime < 500,
        'has error message': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.message !== undefined || body.error !== undefined;
            } catch (e) {
                return false;
            }
        },
        'returns JSON response': (r) => r.headers['Content-Type']?.includes('application/json'),
    });

    // Count as security event if proper error returned
    if (response.status === 401 || response.status === 422 || response.status === 403) {
        securityEvents.add(1);
    }

    // Log performance issues
    if (responseTime > 500) {
        console.error(`❌ PERFORMANCE DEGRADATION: ${responseTime}ms`);
        console.error(`   Status: ${response.status}`);
        console.error(`   Token: ${invalidToken.substring(0, 50)}...`);
    }

    // Small delay between requests
    sleep(0.05); // 50ms
}

// Setup function
export function setup() {
    console.log('='.repeat(70));
    console.log('Security Events Logging - High Volume Test');
    console.log('='.repeat(70));
    console.log(`API URL: ${BASE_URL}`);
    console.log(`Endpoint: POST /api/v1/student/scan-attendance`);
    console.log(`Total Invalid Scans: 2000`);
    console.log(`Concurrent Users: 100`);
    console.log('='.repeat(70));
    console.log('Testing:');
    console.log('  ✓ Security events logged asynchronously');
    console.log('  ✓ Response time NOT degraded by logging');
    console.log('  ✓ Proper error responses returned');
    console.log('='.repeat(70));
    console.log('Pass Criteria:');
    console.log('  ✓ p95 response time < 500ms');
    console.log('  ✓ < 5% slow requests (> 500ms)');
    console.log('  ✓ All requests return proper error codes');
    console.log('='.repeat(70));
    console.log('\nStarting test...\n');

    return {
        startTime: new Date().toISOString(),
    };
}

// Teardown function
export function teardown(data) {
    console.log('\n' + '='.repeat(70));
    console.log('Security Events Logging Test - Completed');
    console.log('='.repeat(70));
    console.log(`Started: ${data.startTime}`);
    console.log(`Ended: ${new Date().toISOString()}`);
    console.log('='.repeat(70));
    console.log('\n📊 Key Metrics to Review:');
    console.log('  • total_requests - Should be 2000');
    console.log('  • security_events - Should be ~2000');
    console.log('  • response_time (p95) - Should be < 500ms');
    console.log('  • slow_requests - Should be < 100 (< 5%)');
    console.log('\n✅ If p95 < 500ms = Logging is async (PASS)');
    console.log('❌ If p95 > 500ms = Logging is blocking (FAIL)');
    console.log('='.repeat(70));
    console.log('\n📋 Next Steps:');
    console.log('  1. Check security_events table for logged events');
    console.log('  2. Verify all 2000 events were recorded');
    console.log('  3. Confirm event details are accurate');
    console.log('='.repeat(70));
}
