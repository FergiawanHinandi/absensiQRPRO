import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// Custom metrics
const errorRate = new Rate('errors');
const attendanceDuration = new Trend('attendance_duration');
const duplicateRate = new Rate('duplicates');

// Test configuration
export const options = {
    stages: [
        { duration: '30s', target: 100 },   // Ramp up to 100 users
        { duration: '1m', target: 1000 },   // Ramp up to 1000 users
        { duration: '2m', target: 1000 },   // Stay at 1000 users
        { duration: '30s', target: 0 },     // Ramp down
    ],
    thresholds: {
        http_req_duration: ['p(95)<500'],   // 95% of requests < 500ms
        errors: ['rate<0.01'],              // Error rate < 1%
        duplicates: ['rate<0.001'],         // Duplicate rate < 0.1%
    },
};

// Test data
const BASE_URL = __ENV.BASE_URL || 'http://localhost';
const API_TOKEN = __ENV.API_TOKEN || 'test-token';

// Generate random student IDs (1-1000)
function getRandomStudentId() {
    return Math.floor(Math.random() * 1000) + 1;
}

// Main test scenario
export default function () {
    const studentId = getRandomStudentId();
    const payload = JSON.stringify({
        student_id: studentId,
        schedule_id: 1,
        qr_code_id: 1,
        latitude: -6.2088,
        longitude: 106.8456,
        device_id: `device_${__VU}`,
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${API_TOKEN}`,
        },
    };

    // Record attendance
    const startTime = new Date();
    const response = http.post(`${BASE_URL}/api/attendance/scan`, payload, params);
    const duration = new Date() - startTime;

    // Track metrics
    attendanceDuration.add(duration);

    const success = check(response, {
        'status is 200 or 201': (r) => r.status === 200 || r.status === 201,
        'response time < 500ms': (r) => r.timings.duration < 500,
        'no errors in response': (r) => !r.json('error'),
    });

    if (!success) {
        errorRate.add(1);
    } else {
        errorRate.add(0);
    }

    // Check for duplicate response
    if (response.json('message') && response.json('message').includes('already')) {
        duplicateRate.add(1);
    } else {
        duplicateRate.add(0);
    }

    // Think time
    sleep(1);
}

// Setup function (runs once per VU)
export function setup() {
    console.log('Starting concurrent scan test...');
    console.log(`Target: ${BASE_URL}`);
    console.log(`Max VUs: 1000`);
    return { startTime: new Date() };
}

// Teardown function (runs once after test)
export function teardown(data) {
    const duration = (new Date() - data.startTime) / 1000;
    console.log(`Test completed in ${duration}s`);
}
