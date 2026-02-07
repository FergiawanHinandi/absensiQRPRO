/**
 * Load Test: Notification Dispatch - High Volume Generation
 * 
 * Simulates 1500 attendance events in 5 minutes to trigger notifications.
 * Verifies that notification jobs are dispatched to the queue.
 * 
 * Test Profile:
 * - Total Requests: 1500
 * - Duration: 5 minutes
 * - Throughput: ~5 requests/second
 * 
 * Pass Criteria:
 * - Response time < 500ms (dispatching should be async)
 * - Error rate < 1%
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Counter } from 'k6/metrics';

// Custom metrics
const jobsDispatched = new Counter('jobs_dispatched');
const errorRate = new Rate('errors');

// Test configuration
export const options = {
    scenarios: {
        notification_burst: {
            executor: 'constant-arrival-rate',
            rate: 5,                     // 5 requests per second
            timeUnit: '1s',
            duration: '5m',              // 5 minutes (Total ~1500 requests)
            preAllocatedVUs: 50,         // Reserve 50 VUs
            maxVUs: 100,
        },
    },
    thresholds: {
        'http_req_duration': ['p(95)<500'],    // Response must be fast (async dispatch)
        'errors': ['rate<0.01'],               // < 1% errors
    },
};

// Configuration
const BASE_URL = __ENV.API_URL || 'http://localhost:8000';
const ENDPOINT = `${BASE_URL}/api/v1/student/scan-attendance`;

// Generate unique student IDs to trigger distinct notifications
function getStudentPayload() {
    return JSON.stringify({
        // Using a specially prefixed ID to identify test data
        student_id: `notify-test-${__VU}-${__ITER}`,
        qr_token: `token-${Date.now()}-${__VU}-${__ITER}`,
        session_id: 'notify-test-session',
        latitude: -6.200000,
        longitude: 106.816666,
        timestamp: new Date().toISOString(),
    });
}

export default function () {
    const payload = getStudentPayload();

    // Header to signal test mode if backend supports it
    // (e.g., to force specific notification channels)
    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer test-notify-token',
            'X-Test-Group': 'notification-load',
        },
        tags: {
            name: 'NotificationTrigger',
        },
    };

    // Execute request
    const response = http.post(ENDPOINT, payload, params);

    // Validate response
    const success = check(response, {
        'status is 200/201': (r) => r.status === 200 || r.status === 201,
        'response time < 500ms': (r) => r.timings.duration < 500,
    });

    if (success) {
        jobsDispatched.add(1);
    } else {
        errorRate.add(1);
    }

    if (response.status >= 500) {
        console.error(`❌ Server Error: ${response.status}`);
    }
}

// Setup
export function setup() {
    console.log('='.repeat(70));
    console.log('Notification Dispatch Load Test');
    console.log('='.repeat(70));
    console.log('Target: 1500 Notifications in 5 minutes');
    console.log('Rate:   5 req/s');
    console.log('='.repeat(70));
    return { startTime: new Date().toISOString() };
}

// Teardown
export function teardown(data) {
    console.log('\n' + '='.repeat(70));
    console.log('Notification Test Completed');
    console.log('='.repeat(70));
    console.log('Next Step: Run verify-notifications.php to check queue processing');
    console.log('='.repeat(70));
}
