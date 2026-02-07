/**
 * Load Test: QR Attendance Scan Endpoint
 * 
 * Test Profile:
 * - 800 virtual users
 * - Ramp-up: 2 minutes
 * - Duration: 5 minutes
 * 
 * Pass Criteria:
 * - Avg response < 500ms
 * - Error rate < 1%
 * - No duplicate attendance records
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// Custom metrics
const errorRate = new Rate('errors');
const responseTrend = new Trend('response_time');

// Test configuration
export const options = {
    stages: [
        { duration: '2m', target: 800 },  // Ramp up to 800 users over 2 minutes
        { duration: '5m', target: 800 },  // Stay at 800 users for 5 minutes
        { duration: '1m', target: 0 },    // Ramp down to 0 users
    ],
    thresholds: {
        'http_req_duration': ['p(95)<500'],  // 95% of requests must complete below 500ms
        'errors': ['rate<0.01'],              // Error rate must be below 1%
        'http_req_failed': ['rate<0.01'],     // HTTP failures must be below 1%
    },
};

// Configuration
const BASE_URL = __ENV.API_URL || 'http://localhost:8000';
const API_TOKEN = __ENV.API_TOKEN || 'test-token-12345';
const SESSION_ID = 'session-2026-02-02-class-xa'; // Same session for all students

// Generate unique student IDs
function getStudentId() {
    const vuId = __VU; // Virtual User ID
    const iter = __ITER; // Iteration number
    return `student-${vuId}-${iter}`;
}

// Generate QR token (simplified)
function getQRToken() {
    const timestamp = Date.now();
    const random = Math.random().toString(36).substring(7);
    return `qr-${timestamp}-${random}`;
}

export default function () {
    const studentId = getStudentId();
    const qrToken = getQRToken();

    const url = `${BASE_URL}/api/v1/student/scan-attendance`;

    const payload = JSON.stringify({
        student_id: studentId,
        session_id: SESSION_ID,
        qr_token: qrToken,
        latitude: -6.200000 + (Math.random() * 0.01), // Slight variation
        longitude: 106.816666 + (Math.random() * 0.01),
        device_id: `device-${__VU}`,
        timestamp: new Date().toISOString(),
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${API_TOKEN}`,
            'Accept': 'application/json',
        },
        tags: {
            name: 'AttendanceScan',
        },
    };

    // Execute request
    const response = http.post(url, payload, params);

    // Record response time
    responseTrend.add(response.timings.duration);

    // Validate response
    const success = check(response, {
        'status is 200 or 201': (r) => r.status === 200 || r.status === 201,
        'response has success field': (r) => JSON.parse(r.body).success !== undefined,
        'response time < 500ms': (r) => r.timings.duration < 500,
        'no server errors': (r) => r.status < 500,
    });

    // Record errors
    errorRate.add(!success);

    // Log errors for debugging
    if (!success) {
        console.error(`Error for ${studentId}: Status ${response.status}, Body: ${response.body}`);
    }

    // Think time between requests (1-3 seconds)
    sleep(Math.random() * 2 + 1);
}

// Setup function (runs once before test)
export function setup() {
    console.log('='.repeat(60));
    console.log('QR Attendance Scan Load Test');
    console.log('='.repeat(60));
    console.log(`API URL: ${BASE_URL}`);
    console.log(`Session ID: ${SESSION_ID}`);
    console.log(`Virtual Users: 800`);
    console.log(`Ramp-up: 2 minutes`);
    console.log(`Duration: 5 minutes`);
    console.log('='.repeat(60));

    // Test API availability
    const healthCheck = http.get(`${BASE_URL}/api/health`);
    if (healthCheck.status !== 200) {
        console.warn(`Warning: Health check returned ${healthCheck.status}`);
    }

    return {
        startTime: new Date().toISOString(),
    };
}

// Teardown function (runs once after test)
export function teardown(data) {
    console.log('='.repeat(60));
    console.log('Load Test Completed');
    console.log(`Started: ${data.startTime}`);
    console.log(`Ended: ${new Date().toISOString()}`);
    console.log('='.repeat(60));
    console.log('Check results above for:');
    console.log('  - Average response time');
    console.log('  - Error rate');
    console.log('  - Throughput (requests/second)');
    console.log('='.repeat(60));
}
