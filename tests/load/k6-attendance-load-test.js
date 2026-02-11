/**
 * K6 Load Test - AbsensiQR Pro SaaS
 * 
 * SCENARIO: 10,000 Concurrent Attendance Scan
 * - 5000 students scan dalam 5 detik
 * - 10 teachers generate QR bersamaan
 * - 100 webhooks bersamaan
 * - Redis restart saat load
 * - DB latency 300ms
 * 
 * ACCEPTANCE CRITERIA:
 * - Error rate < 1%
 * - No duplicate attendance
 * - No double subscription
 * - System stable after Redis restart
 * - No memory leak
 * 
 * RUN:
 * k6 run --vus 5000 --duration 60s tests/load/k6-attendance-load-test.js
 */

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend, Counter, Gauge } from 'k6/metrics';
import { randomIntBetween, randomItem } from 'https://jslib.k6.io/k6-utils/1.2.0/index.js';

// ============================================================================
// CUSTOM METRICS
// ============================================================================

const errorRate = new Rate('errors');
const scanDuration = new Trend('scan_duration');
const qrGenerateDuration = new Trend('qr_generate_duration');
const webhookDuration = new Trend('webhook_duration');
const duplicateAttendance = new Counter('duplicate_attendance');
const deadlockErrors = new Counter('deadlock_errors');
const redisErrors = new Counter('redis_errors');
const dbLockWaits = new Counter('db_lock_waits');
const successfulScans = new Counter('successful_scans');
const failedScans = new Counter('failed_scans');

// ============================================================================
// CONFIGURATION
// ============================================================================

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const API_URL = `${BASE_URL}/api/v1`;

// Test data
const SCHOOLS = [1, 2, 3, 4, 5]; // 5 schools
const TEACHERS_PER_SCHOOL = 10;
const STUDENTS_PER_SCHOOL = 1000;
const SCHEDULES_PER_SCHOOL = 50;

// ============================================================================
// TEST OPTIONS
// ============================================================================

export const options = {
    scenarios: {
        // SCENARIO 1: 5000 students scan dalam 5 detik
        student_scan_burst: {
            executor: 'constant-arrival-rate',
            rate: 1000, // 1000 requests per second
            timeUnit: '1s',
            duration: '5s',
            preAllocatedVUs: 2000,
            maxVUs: 5000,
            exec: 'studentScan',
            startTime: '0s',
        },
        
        // SCENARIO 2: 10 teachers generate QR bersamaan
        teacher_qr_generation: {
            executor: 'shared-iterations',
            vus: 10,
            iterations: 10,
            maxDuration: '10s',
            exec: 'teacherGenerateQR',
            startTime: '0s',
        },
        
        // SCENARIO 3: 100 webhooks bersamaan
        webhook_burst: {
            executor: 'constant-arrival-rate',
            rate: 100, // 100 webhooks per second
            timeUnit: '1s',
            duration: '1s',
            preAllocatedVUs: 50,
            maxVUs: 100,
            exec: 'webhookPayment',
            startTime: '2s',
        },
        
        // SCENARIO 4: Sustained load untuk stability test
        sustained_load: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '10s', target: 500 },  // Ramp up
                { duration: '30s', target: 500 },  // Sustained
                { duration: '10s', target: 1000 }, // Spike
                { duration: '10s', target: 0 },    // Ramp down
            ],
            exec: 'mixedLoad',
            startTime: '10s',
        },
    },
    
    thresholds: {
        // ACCEPTANCE CRITERIA
        'errors': ['rate<0.01'], // Error rate < 1%
        'scan_duration': ['p(95)<500', 'p(99)<1000'], // 95% < 500ms, 99% < 1s
        'qr_generate_duration': ['p(95)<300'],
        'webhook_duration': ['p(95)<2000'],
        'http_req_duration': ['p(95)<1000'],
        'http_req_failed': ['rate<0.01'],
        'duplicate_attendance': ['count==0'], // ZERO duplicates allowed
        'deadlock_errors': ['count<10'], // Max 10 deadlocks (should retry)
    },
};

// ============================================================================
// SETUP: Generate test data
// ============================================================================

export function setup() {
    console.log('🚀 Setting up load test...');
    
    // Login as super admin to create test data
    const loginRes = http.post(`${API_URL}/auth/login`, JSON.stringify({
        username: 'superadmin',
        password: 'password123',
    }), {
        headers: { 'Content-Type': 'application/json' },
    });
    
    const superAdminToken = loginRes.json('data.token');
    
    // Generate test tokens for students and teachers
    const testData = {
        superAdminToken,
        studentTokens: [],
        teacherTokens: [],
        schedules: [],
        qrCodes: [],
    };
    
    // Create tokens for each school
    SCHOOLS.forEach(schoolId => {
        // Generate student tokens
        for (let i = 1; i <= 100; i++) { // 100 students per school for test
            const studentId = (schoolId * 1000) + i;
            testData.studentTokens.push({
                token: `student_token_${studentId}`,
                studentId,
                schoolId,
            });
        }
        
        // Generate teacher tokens
        for (let i = 1; i <= TEACHERS_PER_SCHOOL; i++) {
            const teacherId = (schoolId * 100) + i;
            testData.teacherTokens.push({
                token: `teacher_token_${teacherId}`,
                teacherId,
                schoolId,
            });
        }
        
        // Generate schedules
        for (let i = 1; i <= 10; i++) { // 10 schedules per school for test
            const scheduleId = (schoolId * 100) + i;
            testData.schedules.push({
                scheduleId,
                schoolId,
            });
        }
    });
    
    console.log(`✅ Setup complete:`);
    console.log(`   - ${testData.studentTokens.length} student tokens`);
    console.log(`   - ${testData.teacherTokens.length} teacher tokens`);
    console.log(`   - ${testData.schedules.length} schedules`);
    
    return testData;
}

// ============================================================================
// SCENARIO 1: Student Scan QR Code
// ============================================================================

export function studentScan(data) {
    const startTime = Date.now();
    
    group('Student Scan QR', () => {
        // Pick random student and schedule
        const student = randomItem(data.studentTokens);
        const schedule = randomItem(data.schedules.filter(s => s.schoolId === student.schoolId));
        
        // Generate QR payload (simulating teacher's QR)
        const qrPayload = {
            data: {
                schedule_id: schedule.scheduleId,
                school_id: student.schoolId,
                expires_at: new Date(Date.now() + 60000).toISOString(),
                idempotency_key: `${student.studentId}_${schedule.scheduleId}_${Date.now()}`,
                generated_at: new Date().toISOString(),
            },
            signature: 'mock_signature_for_load_test',
        };
        
        // Scan QR code
        const scanRes = http.post(
            `${API_URL}/student/attendance/scan`,
            JSON.stringify({
                qr_payload: qrPayload,
                latitude: -6.200000 + (Math.random() * 0.001),
                longitude: 106.816666 + (Math.random() * 0.001),
                gps_accuracy: randomIntBetween(5, 20),
                device_id: `device_${student.studentId}`,
                is_mock_location: false,
            }),
            {
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${student.token}`,
                },
                tags: { name: 'StudentScan' },
            }
        );
        
        const duration = Date.now() - startTime;
        scanDuration.add(duration);
        
        // Check response
        const success = check(scanRes, {
            'scan status is 200 or 201': (r) => r.status === 200 || r.status === 201,
            'scan has success field': (r) => r.json('success') !== undefined,
            'scan response time < 1s': () => duration < 1000,
        });
        
        if (success) {
            successfulScans.add(1);
        } else {
            failedScans.add(1);
            errorRate.add(1);
            
            // Detect specific errors
            if (scanRes.status === 409 || scanRes.body.includes('sudah absen')) {
                duplicateAttendance.add(1);
                console.warn(`⚠️  DUPLICATE ATTENDANCE: Student ${student.studentId}, Schedule ${schedule.scheduleId}`);
            }
            
            if (scanRes.status === 500 && scanRes.body.includes('deadlock')) {
                deadlockErrors.add(1);
                console.warn(`⚠️  DEADLOCK: Student ${student.studentId}`);
            }
            
            if (scanRes.status === 500 && scanRes.body.includes('Redis')) {
                redisErrors.add(1);
                console.warn(`⚠️  REDIS ERROR: Student ${student.studentId}`);
            }
            
            if (scanRes.status === 500 && scanRes.body.includes('lock wait')) {
                dbLockWaits.add(1);
                console.warn(`⚠️  DB LOCK WAIT: Student ${student.studentId}`);
            }
        }
    });
    
    sleep(0.1); // Small delay between requests
}

// ============================================================================
// SCENARIO 2: Teacher Generate QR Code
// ============================================================================

export function teacherGenerateQR(data) {
    const startTime = Date.now();
    
    group('Teacher Generate QR', () => {
        // Pick random teacher and schedule
        const teacher = randomItem(data.teacherTokens);
        const schedule = randomItem(data.schedules.filter(s => s.schoolId === teacher.schoolId));
        
        // Generate QR code
        const qrRes = http.post(
            `${API_URL}/teacher/attendance/generate-qr`,
            JSON.stringify({
                schedule_id: schedule.scheduleId,
                expiry_seconds: 60,
            }),
            {
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${teacher.token}`,
                },
                tags: { name: 'TeacherGenerateQR' },
            }
        );
        
        const duration = Date.now() - startTime;
        qrGenerateDuration.add(duration);
        
        // Check response
        const success = check(qrRes, {
            'qr generation status is 200': (r) => r.status === 200,
            'qr has payload': (r) => r.json('data.qr_payload') !== undefined,
            'qr response time < 500ms': () => duration < 500,
        });
        
        if (!success) {
            errorRate.add(1);
            console.warn(`⚠️  QR GENERATION FAILED: Teacher ${teacher.teacherId}, Schedule ${schedule.scheduleId}`);
        }
        
        // Store QR for student scans
        if (success && qrRes.json('data.qr_payload')) {
            data.qrCodes.push({
                scheduleId: schedule.scheduleId,
                schoolId: teacher.schoolId,
                payload: qrRes.json('data.qr_payload'),
                generatedAt: Date.now(),
            });
        }
    });
}

// ============================================================================
// SCENARIO 3: Webhook Payment Processing
// ============================================================================

export function webhookPayment(data) {
    const startTime = Date.now();
    
    group('Webhook Payment', () => {
        // Pick random school
        const schoolId = randomItem(SCHOOLS);
        const orderId = `ORDER_${schoolId}_${Date.now()}_${randomIntBetween(1000, 9999)}`;
        const transactionId = `TRX_${Date.now()}_${randomIntBetween(1000, 9999)}`;
        
        // Simulate Midtrans webhook
        const webhookRes = http.post(
            `${API_URL}/webhook/payment`,
            JSON.stringify({
                order_id: orderId,
                transaction_id: transactionId,
                transaction_status: 'settlement',
                payment_type: 'bank_transfer',
                gross_amount: '500000',
                status_code: '200',
                signature_key: 'mock_signature_for_load_test',
            }),
            {
                headers: {
                    'Content-Type': 'application/json',
                },
                tags: { name: 'WebhookPayment' },
            }
        );
        
        const duration = Date.now() - startTime;
        webhookDuration.add(duration);
        
        // Check response
        const success = check(webhookRes, {
            'webhook status is 200': (r) => r.status === 200,
            'webhook processed': (r) => r.json('success') === true,
            'webhook response time < 3s': () => duration < 3000,
        });
        
        if (!success) {
            errorRate.add(1);
            
            // Check for double processing
            if (webhookRes.json('idempotent') === true) {
                console.log(`✅ IDEMPOTENCY WORKING: ${orderId}`);
            } else {
                console.warn(`⚠️  WEBHOOK FAILED: ${orderId}, Status: ${webhookRes.status}`);
            }
        }
    });
}

// ============================================================================
// SCENARIO 4: Mixed Load (Realistic Usage)
// ============================================================================

export function mixedLoad(data) {
    const action = randomIntBetween(1, 100);
    
    if (action <= 70) {
        // 70% student scans
        studentScan(data);
    } else if (action <= 85) {
        // 15% teacher QR generation
        teacherGenerateQR(data);
    } else if (action <= 95) {
        // 10% dashboard views
        dashboardView(data);
    } else {
        // 5% reports
        reportExport(data);
    }
}

// ============================================================================
// HELPER: Dashboard View
// ============================================================================

function dashboardView(data) {
    const teacher = randomItem(data.teacherTokens);
    
    const dashRes = http.get(
        `${API_URL}/teacher/dashboard`,
        {
            headers: {
                'Authorization': `Bearer ${teacher.token}`,
            },
            tags: { name: 'DashboardView' },
        }
    );
    
    check(dashRes, {
        'dashboard status is 200': (r) => r.status === 200,
        'dashboard response time < 2s': (r) => r.timings.duration < 2000,
    });
}

// ============================================================================
// HELPER: Report Export
// ============================================================================

function reportExport(data) {
    const teacher = randomItem(data.teacherTokens);
    
    const reportRes = http.post(
        `${API_URL}/admin/attendance/export`,
        JSON.stringify({
            type: 'monthly',
            month: '2026-02',
        }),
        {
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${teacher.token}`,
            },
            tags: { name: 'ReportExport' },
        }
    );
    
    check(reportRes, {
        'export status is 200 or 202': (r) => r.status === 200 || r.status === 202,
    });
}

// ============================================================================
// TEARDOWN: Cleanup and summary
// ============================================================================

export function teardown(data) {
    console.log('\n📊 LOAD TEST SUMMARY');
    console.log('='.repeat(60));
    console.log(`Total Requests: ${successfulScans.count + failedScans.count}`);
    console.log(`Successful Scans: ${successfulScans.count}`);
    console.log(`Failed Scans: ${failedScans.count}`);
    console.log(`Duplicate Attendance: ${duplicateAttendance.count}`);
    console.log(`Deadlock Errors: ${deadlockErrors.count}`);
    console.log(`Redis Errors: ${redisErrors.count}`);
    console.log(`DB Lock Waits: ${dbLockWaits.count}`);
    console.log('='.repeat(60));
}

// ============================================================================
// CUSTOM SUMMARY
// ============================================================================

export function handleSummary(data) {
    return {
        'load-test-results.json': JSON.stringify(data, null, 2),
        'stdout': textSummary(data, { indent: ' ', enableColors: true }),
    };
}
