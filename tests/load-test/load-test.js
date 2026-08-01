// =============================================================================
// AbsensiQRPro - Load Test Script (K6)
// Usage: k6 run load-test.js --env BASE_URL=http://localhost:8000
// =============================================================================

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// Custom metrics
const errorRate = new Rate('errors');
const loginDuration = new Trend('login_duration');
const dashboardDuration = new Trend('dashboard_duration');
const scanDuration = new Trend('scan_duration');
const requestCount = new Counter('total_requests');

// Configuration
export const options = {
  stages: [
    { duration: '1m', target: 10 },   // Warm up
    { duration: '2m', target: 50 },   // Ramp up
    { duration: '5m', target: 100 },  // Peak load
    { duration: '2m', target: 50 },   // Ramp down
    { duration: '1m', target: 0 },    // Cool down
  ],
  thresholds: {
    http_req_duration: ['p(95)<500'],  // 95% of requests should be < 500ms
    http_req_failed: ['rate<0.01'],    // Error rate should be < 1%
    errors: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

// Test data
const TEST_USERS = [
  { email: 'superadmin@absensiqr.com', password: 'password', role: 'super_admin' },
  { email: 'admin@school.com', password: 'password', role: 'admin' },
  { email: 'guru@school.com', password: 'password', role: 'teacher' },
  { email: 'siswa@school.com', password: 'password', role: 'student' },
];

export default function () {
  const user = TEST_USERS[Math.floor(Math.random() * TEST_USERS.length)];
  
  // 1. Health Check
  const healthRes = http.get(`${BASE_URL}/health`);
  check(healthRes, {
    'health check status 200': (r) => r.status === 200,
  });
  requestCount.add(1);
  
  // 2. Login
  const loginPayload = JSON.stringify({
    email: user.email,
    password: user.password,
  });
  
  const loginParams = {
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
  };
  
  const loginRes = http.post(`${BASE_URL}/api/v1/auth/login`, loginPayload, loginParams);
  loginDuration.add(loginRes.timings.duration);
  
  const loginSuccess = check(loginRes, {
    'login status 200': (r) => r.status === 200,
    'login has token': (r) => r.json('data.access_token') !== undefined,
  });
  
  if (!loginSuccess) {
    errorRate.add(1);
    sleep(1);
    return;
  }
  
  const token = loginRes.json('data.access_token');
  requestCount.add(1);
  
  // 3. Get Dashboard (based on role)
  const dashboardParams = {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json',
    },
  };
  
  let dashboardUrl;
  switch (user.role) {
    case 'super_admin':
      dashboardUrl = `${BASE_URL}/api/v1/super-admin/dashboard/stats`;
      break;
    case 'admin':
      dashboardUrl = `${BASE_URL}/api/v1/admin/dashboard`;
      break;
    case 'teacher':
      dashboardUrl = `${BASE_URL}/api/v1/teacher/dashboard`;
      break;
    case 'student':
      dashboardUrl = `${BASE_URL}/api/v1/student/dashboard`;
      break;
    default:
      dashboardUrl = `${BASE_URL}/api/v1/dashboard`;
  }
  
  const dashboardRes = http.get(dashboardUrl, dashboardParams);
  dashboardDuration.add(dashboardRes.timings.duration);
  
  check(dashboardRes, {
    'dashboard status 200': (r) => r.status === 200,
  });
  requestCount.add(1);
  
  // 4. Simulate QR Scan (for teacher/student)
  if (user.role === 'teacher' || user.role === 'student') {
    sleep(0.5); // Simulate time between actions
    
    const scanPayload = JSON.stringify({
      qr_token: 'test_qr_token_' + Date.now(),
      student_id: 'test_student',
      latitude: -6.2088,
      longitude: 106.8456,
    });
    
    const scanRes = http.post(`${BASE_URL}/api/v1/attendance/scan`, scanPayload, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Idempotency-Key': `scan_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
      },
    });
    
    scanDuration.add(scanRes.timings.duration);
    requestCount.add(1);
    
    // Scan might fail due to invalid QR, that's ok for load testing
    check(scanRes, {
      'scan response received': (r) => r.status !== 0,
    });
  }
  
  // 5. Get Schedules (for teacher/student)
  if (user.role === 'teacher' || user.role === 'student') {
    const scheduleUrl = user.role === 'teacher' 
      ? `${BASE_URL}/api/v1/teacher/schedules`
      : `${BASE_URL}/api/v1/student/today-schedule`;
    
    const scheduleRes = http.get(scheduleUrl, dashboardParams);
    requestCount.add(1);
    
    check(scheduleRes, {
      'schedules status 200': (r) => r.status === 200,
    });
  }
  
  // 6. Get Notifications
  const notifRes = http.get(`${BASE_URL}/api/v1/notifications`, dashboardParams);
  requestCount.add(1);
  
  check(notifRes, {
    'notifications response received': (r) => r.status !== 0,
  });
  
  // Think time (simulated user behavior)
  sleep(Math.random() * 2 + 1); // 1-3 seconds
}

// Summary handler
export function handleSummary(data) {
  const summary = {
    'Total Requests': data.metrics.http_reqs?.values?.count || 0,
    'Failed Requests': data.metrics.http_req_failed?.values?.count || 0,
    'Error Rate': ((data.metrics.http_req_failed?.values?.rate || 0) * 100).toFixed(2) + '%',
    'Avg Response Time': (data.metrics.http_req_duration?.values?.avg || 0).toFixed(2) + 'ms',
    'P95 Response Time': (data.metrics.http_req_duration?.values?.['p(95)'] || 0).toFixed(2) + 'ms',
    'P99 Response Time': (data.metrics.http_req_duration?.values?.['p(99)'] || 0).toFixed(2) + 'ms',
    'Custom Login Duration': (data.metrics.login_duration?.values?.avg || 0).toFixed(2) + 'ms',
    'Custom Dashboard Duration': (data.metrics.dashboard_duration?.values?.avg || 0).toFixed(2) + 'ms',
  };
  
  console.log('\n========================================');
  console.log('  AbsensiQRPro Load Test Summary');
  console.log('========================================');
  for (const [key, value] of Object.entries(summary)) {
    console.log(`  ${key}: ${value}`);
  }
  console.log('========================================\n');
  
  return {
    'stdout': JSON.stringify(summary, null, 2),
    'load-test-results.json': JSON.stringify(data, null, 2),
  };
}
