/**
 * Load Test: Teacher Dashboard Today Sessions
 * 
 * Simulates 50 concurrent teachers refreshing their dashboard every 10 seconds
 * 
 * Test Profile:
 * - 50 concurrent teachers
 * - Refresh interval: 10 seconds
 * - Duration: 5 minutes
 * 
 * Pass Criteria:
 * - Response time < 800ms
 * - No timeouts
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// Custom metrics
const errorRate = new Rate('errors');
const timeoutRate = new Rate('timeouts');
const responseTrend = new Trend('response_time');
const totalRequests = new Counter('total_requests');

// Test configuration
export const options = {
    stages: [
        { duration: '30s', target: 50 },   // Ramp up to 50 teachers over 30 seconds
        { duration: '5m', target: 50 },    // Stay at 50 teachers for 5 minutes
        { duration: '30s', target: 0 },    // Ramp down gracefully
    ],
    thresholds: {
        'http_req_duration': ['p(95)<800'],       // 95% of requests must complete below 800ms
        'http_req_duration': ['max<5000'],        // No request should take more than 5s (timeout)
        'errors': ['rate<0.05'],                   // Error rate must be below 5%
        'timeouts': ['rate<0.01'],                 // Timeout rate must be below 1%
        'http_req_failed': ['rate<0.05'],          // HTTP failures must be below 5%
    },
};

// Configuration
const BASE_URL = __ENV.API_URL || 'http://localhost:8000';
const API_TOKEN = __ENV.API_TOKEN || 'test-token-12345';

// Generate unique teacher IDs (50 teachers)
function getTeacherId() {
    const teacherId = (__VU % 50) + 1; // Cycle through 50 teacher IDs
    return `teacher-${teacherId}`;
}

export default function () {
    const teacherId = getTeacherId();

    const url = `${BASE_URL}/api/v1/teacher/today-sessions`;

    const params = {
        headers: {
            'Authorization': `Bearer ${API_TOKEN}`,
            'Accept': 'application/json',
            'X-Teacher-ID': teacherId, // For logging/tracking
        },
        timeout: '5s', // 5 second timeout (anything beyond is a failure)
        tags: {
            name: 'TeacherDashboard',
            teacher_id: teacherId,
        },
    };

    // Record request start time
    const startTime = Date.now();

    // Execute GET request
    const response = http.get(url, params);

    // Calculate response time
    const responseTime = Date.now() - startTime;
    responseTrend.add(responseTime);
    totalRequests.add(1);

    // Check for timeout (> 5000ms)
    const isTimeout = responseTime > 5000;
    timeoutRate.add(isTimeout);

    // Validate response
    const success = check(response, {
        'status is 200': (r) => r.status === 200,
        'response has success field': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.success !== undefined;
            } catch (e) {
                return false;
            }
        },
        'response has data field': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.data !== undefined;
            } catch (e) {
                return false;
            }
        },
        'response time < 800ms': (r) => responseTime < 800,
        'no timeout': (r) => !isTimeout,
        'no server errors': (r) => r.status < 500,
    });

    // Record errors
    errorRate.add(!success);

    // Log slow requests
    if (responseTime > 800) {
        console.warn(`⚠️  Slow request for ${teacherId}: ${responseTime}ms`);
    }

    // Log errors for debugging
    if (!success) {
        console.error(`❌ Error for ${teacherId}: Status ${response.status}, Time ${responseTime}ms`);
        if (response.body) {
            console.error(`   Body: ${response.body.substring(0, 200)}`);
        }
    }

    // Log timeouts
    if (isTimeout) {
        console.error(`⏱️  TIMEOUT for ${teacherId}: ${responseTime}ms`);
    }

    // Simulate realistic dashboard refresh behavior
    // Teachers check their dashboard every 10 seconds
    sleep(10);
}

// Setup function (runs once before test)
export function setup() {
    console.log('='.repeat(70));
    console.log('Teacher Dashboard Load Test - Today Sessions');
    console.log('='.repeat(70));
    console.log(`API URL: ${BASE_URL}`);
    console.log(`Endpoint: GET /api/v1/teacher/today-sessions`);
    console.log(`Concurrent Teachers: 50`);
    console.log(`Refresh Interval: 10 seconds`);
    console.log(`Test Duration: 5 minutes`);
    console.log('='.repeat(70));
    console.log('Pass Criteria:');
    console.log('  ✓ Response time < 800ms (p95)');
    console.log('  ✓ No timeouts (< 5 seconds)');
    console.log('  ✓ Error rate < 5%');
    console.log('='.repeat(70));

    // Test API availability
    console.log('\nTesting API availability...');
    const healthCheck = http.get(`${BASE_URL}/api/health`);
    if (healthCheck.status === 200) {
        console.log('✓ API is responsive');
    } else {
        console.warn(`⚠ Warning: Health check returned ${healthCheck.status}`);
    }

    // Test endpoint availability
    const testUrl = `${BASE_URL}/api/v1/teacher/today-sessions`;
    const testParams = {
        headers: {
            'Authorization': `Bearer ${API_TOKEN}`,
            'Accept': 'application/json',
        },
        timeout: '5s',
    };

    console.log('\nTesting endpoint availability...');
    const endpointTest = http.get(testUrl, testParams);
    if (endpointTest.status === 200 || endpointTest.status === 401) {
        console.log('✓ Endpoint is accessible');
    } else {
        console.warn(`⚠ Warning: Endpoint returned ${endpointTest.status}`);
    }

    console.log('\nStarting load test...\n');

    return {
        startTime: new Date().toISOString(),
    };
}

// Teardown function (runs once after test)
export function teardown(data) {
    console.log('\n' + '='.repeat(70));
    console.log('Load Test Completed');
    console.log('='.repeat(70));
    console.log(`Started: ${data.startTime}`);
    console.log(`Ended: ${new Date().toISOString()}`);
    console.log('='.repeat(70));
    console.log('\n📊 Key Metrics to Review:');
    console.log('  • http_req_duration (p95) - Should be < 800ms');
    console.log('  • http_req_duration (max) - Should be < 5000ms (no timeout)');
    console.log('  • errors rate - Should be < 5%');
    console.log('  • timeouts rate - Should be < 1%');
    console.log('\n📈 Expected Pattern:');
    console.log('  • ~5 requests/second (50 teachers / 10s interval)');
    console.log('  • Consistent response times');
    console.log('  • No gradual degradation');
    console.log('='.repeat(70));
}
