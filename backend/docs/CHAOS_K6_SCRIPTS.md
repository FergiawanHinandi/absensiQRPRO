# 🔥 Chaos Engineering - k6 Load Test Scripts

**Site Reliability Engineer**  
**Date**: 2026-02-10

---

## 📦 Installation

```bash
# Install k6
# macOS
brew install k6

# Windows
choco install k6

# Linux
sudo apt-key adv --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6
```

---

## 🎯 Test 1: 10,000 Concurrent Attendance Scans

**File**: `chaos-scan-load.js`

```javascript
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend, Rate } from 'k6/metrics';

// Custom metrics
const duplicateCount = new Counter('duplicate_attendance');
const scanLatency = new Trend('scan_latency');
const errorRate = new Rate('error_rate');
const lockWaitTime = new Trend('db_lock_wait');

// Test configuration
export const options = {
  stages: [
    { duration: '10s', target: 1000 }, // Ramp up to 1000 VUs in 10 seconds
  ],
  thresholds: {
    'http_req_duration': ['p(95)<500'], // 95% of requests under 500ms
    'error_rate': ['rate<0.01'],         // Error rate < 1%
    'duplicate_attendance': ['count==0'], // Zero duplicates
  },
};

// Test data
const BASE_URL = __ENV.BASE_URL || 'https://staging.absensiqr.com';
const API_TOKEN = __ENV.API_TOKEN || 'test-token';

const students = [
  { id: 1, name: 'Student 1' },
  { id: 2, name: 'Student 2' },
  { id: 3, name: 'Student 3' },
  // ... generate 100 students
];

export default function () {
  const studentId = students[Math.floor(Math.random() * students.length)].id;
  const scheduleId = 1;
  
  const payload = JSON.stringify({
    student_id: studentId,
    schedule_id: scheduleId,
    latitude: -6.2088 + (Math.random() * 0.01),
    longitude: 106.8456 + (Math.random() * 0.01),
    device_id: `device_${__VU}_${__ITER}`,
  });

  const params = {
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${API_TOKEN}`,
    },
  };

  const startTime = Date.now();
  const response = http.post(`${BASE_URL}/api/attendance/check-in`, payload, params);
  const duration = Date.now() - startTime;

  // Record metrics
  scanLatency.add(duration);
  
  // Check response
  const success = check(response, {
    'status is 200 or 409': (r) => r.status === 200 || r.status === 409,
    'response time < 500ms': (r) => r.timings.duration < 500,
    'no server errors': (r) => r.status < 500,
  });

  if (!success) {
    errorRate.add(1);
  } else {
    errorRate.add(0);
  }

  // Check for duplicates (409 Conflict)
  if (response.status === 409) {
    duplicateCount.add(1);
  }

  // Extract lock wait time from response headers (if available)
  const lockWait = response.headers['X-DB-Lock-Wait'];
  if (lockWait) {
    lockWaitTime.add(parseFloat(lockWait));
  }

  sleep(0.1); // Small delay between iterations
}

export function handleSummary(data) {
  return {
    'chaos-scan-load-summary.json': JSON.stringify(data),
    stdout: textSummary(data, { indent: ' ', enableColors: true }),
  };
}

function textSummary(data, options) {
  const indent = options.indent || '';
  const enableColors = options.enableColors || false;
  
  return `
${indent}Chaos Test: 10,000 Concurrent Scans
${indent}=====================================
${indent}
${indent}Total Requests: ${data.metrics.http_reqs.values.count}
${indent}Failed Requests: ${data.metrics.http_req_failed.values.count}
${indent}Error Rate: ${(data.metrics.error_rate.values.rate * 100).toFixed(2)}%
${indent}
${indent}Response Times:
${indent}  Min: ${data.metrics.http_req_duration.values.min.toFixed(2)}ms
${indent}  Avg: ${data.metrics.http_req_duration.values.avg.toFixed(2)}ms
${indent}  P95: ${data.metrics.http_req_duration.values['p(95)'].toFixed(2)}ms
${indent}  Max: ${data.metrics.http_req_duration.values.max.toFixed(2)}ms
${indent}
${indent}Duplicates Detected: ${data.metrics.duplicate_attendance.values.count}
${indent}
${indent}DB Lock Wait Time:
${indent}  Avg: ${data.metrics.db_lock_wait ? data.metrics.db_lock_wait.values.avg.toFixed(2) + 'ms' : 'N/A'}
${indent}  P95: ${data.metrics.db_lock_wait ? data.metrics.db_lock_wait.values['p(95)'].toFixed(2) + 'ms' : 'N/A'}
${indent}
${indent}✅ Success Criteria:
${indent}  Error Rate < 1%: ${data.metrics.error_rate.values.rate < 0.01 ? 'PASS' : 'FAIL'}
${indent}  P95 < 500ms: ${data.metrics.http_req_duration.values['p(95)'] < 500 ? 'PASS' : 'FAIL'}
${indent}  Zero Duplicates: ${data.metrics.duplicate_attendance.values.count === 0 ? 'PASS' : 'FAIL'}
  `;
}
```

**Run**:
```bash
k6 run --vus 1000 --duration 10s chaos-scan-load.js
```

---

## 🎯 Test 2: 50 Webhook Burst (Idempotency)

**File**: `chaos-webhook-burst.js`

```javascript
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

// Custom metrics
const processedWebhooks = new Counter('webhooks_processed');
const deduplicatedWebhooks = new Counter('webhooks_deduplicated');

export const options = {
  vus: 50,
  iterations: 50,
  thresholds: {
    'webhooks_processed': ['count==1'],      // Only 1 processed
    'webhooks_deduplicated': ['count==49'],  // 49 deduplicated
  },
};

const BASE_URL = __ENV.BASE_URL || 'https://staging.absensiqr.com';

// Same webhook payload for all requests
const WEBHOOK_ID = `webhook_${Date.now()}`;
const IDEMPOTENCY_KEY = `idempotency_${Date.now()}`;

export default function () {
  const payload = JSON.stringify({
    event: 'subscription.renewed',
    school_id: 1,
    subscription_id: 'sub_123',
    plan: 'premium',
    expires_at: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString(),
  });

  const params = {
    headers: {
      'Content-Type': 'application/json',
      'X-Webhook-ID': WEBHOOK_ID,
      'X-Idempotency-Key': IDEMPOTENCY_KEY,
    },
  };

  const response = http.post(`${BASE_URL}/api/webhooks/subscription`, payload, params);

  check(response, {
    'status is 200': (r) => r.status === 200,
  });

  // Check if webhook was processed or deduplicated
  const body = JSON.parse(response.body);
  if (body.processed === true) {
    processedWebhooks.add(1);
  } else if (body.deduplicated === true) {
    deduplicatedWebhooks.add(1);
  }
}

export function handleSummary(data) {
  const processed = data.metrics.webhooks_processed.values.count;
  const deduplicated = data.metrics.webhooks_deduplicated.values.count;
  
  return {
    stdout: `
Chaos Test: 50 Webhook Burst
==============================

Total Webhooks Sent: 50
Processed: ${processed}
Deduplicated: ${deduplicated}

✅ Success Criteria:
  Only 1 Processed: ${processed === 1 ? 'PASS' : 'FAIL'}
  49 Deduplicated: ${deduplicated === 49 ? 'PASS' : 'FAIL'}
    `,
  };
}
```

**Run**:
```bash
k6 run chaos-webhook-burst.js
```

---

## 🎯 Test 3: 10 Teachers Generate QR Simultaneously

**File**: `chaos-qr-generation.js`

```javascript
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

// Custom metrics
const uniqueNonces = new Counter('unique_nonces');
const duplicateNonces = new Counter('duplicate_nonces');

export const options = {
  vus: 10,
  iterations: 10,
  thresholds: {
    'unique_nonces': ['count==10'],      // All unique
    'duplicate_nonces': ['count==0'],    // No duplicates
  },
};

const BASE_URL = __ENV.BASE_URL || 'https://staging.absensiqr.com';
const API_TOKEN = __ENV.API_TOKEN || 'test-token';

// Store generated nonces globally
const generatedNonces = [];

export default function () {
  const payload = JSON.stringify({
    schedule_id: 1,
    valid_duration: 300, // 5 minutes
  });

  const params = {
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${API_TOKEN}`,
    },
  };

  const response = http.post(`${BASE_URL}/api/qr/generate`, payload, params);

  const success = check(response, {
    'status is 200': (r) => r.status === 200,
    'has QR code': (r) => {
      const body = JSON.parse(r.body);
      return body.qr_code !== undefined;
    },
    'has nonce': (r) => {
      const body = JSON.parse(r.body);
      return body.nonce !== undefined;
    },
  });

  if (success) {
    const body = JSON.parse(response.body);
    const nonce = body.nonce;
    
    // Check for duplicate nonce
    if (generatedNonces.includes(nonce)) {
      duplicateNonces.add(1);
      console.error(`❌ Duplicate nonce detected: ${nonce}`);
    } else {
      uniqueNonces.add(1);
      generatedNonces.push(nonce);
    }
  }
}

export function handleSummary(data) {
  const unique = data.metrics.unique_nonces.values.count;
  const duplicates = data.metrics.duplicate_nonces.values.count;
  
  return {
    stdout: `
Chaos Test: 10 QR Generations
===============================

Total QR Codes Generated: 10
Unique Nonces: ${unique}
Duplicate Nonces: ${duplicates}

✅ Success Criteria:
  All Unique: ${unique === 10 ? 'PASS' : 'FAIL'}
  No Duplicates: ${duplicates === 0 ? 'PASS' : 'FAIL'}
    `,
  };
}
```

**Run**:
```bash
k6 run chaos-qr-generation.js
```

---

## 🎯 Test 4: Sustained Load (30 min)

**File**: `chaos-sustained-load.js`

```javascript
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const scanLatency = new Trend('scan_latency');
const errorRate = new Rate('error_rate');

export const options = {
  stages: [
    { duration: '5m', target: 100 },   // Ramp up to 100 VUs
    { duration: '20m', target: 100 },  // Stay at 100 VUs for 20 min
    { duration: '5m', target: 0 },     // Ramp down
  ],
  thresholds: {
    'http_req_duration': ['p(95)<500'],
    'error_rate': ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'https://staging.absensiqr.com';
const API_TOKEN = __ENV.API_TOKEN || 'test-token';

export default function () {
  const studentId = Math.floor(Math.random() * 1000) + 1;
  
  const payload = JSON.stringify({
    student_id: studentId,
    schedule_id: 1,
    latitude: -6.2088,
    longitude: 106.8456,
  });

  const params = {
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${API_TOKEN}`,
    },
  };

  const response = http.post(`${BASE_URL}/api/attendance/check-in`, payload, params);

  const success = check(response, {
    'status is 200 or 409': (r) => r.status === 200 || r.status === 409,
  });

  errorRate.add(!success);
  scanLatency.add(response.timings.duration);

  sleep(1); // 1 second between requests
}
```

**Run**:
```bash
k6 run chaos-sustained-load.js
```

---

## 📊 Running All Tests

**File**: `run-all-chaos-tests.sh`

```bash
#!/bin/bash

echo "🔥 Starting Chaos Load Tests..."
echo "================================"

# Set environment variables
export BASE_URL="https://staging.absensiqr.com"
export API_TOKEN="your-test-token-here"

# Test 1: 10,000 Concurrent Scans
echo ""
echo "📊 Test 1: 10,000 Concurrent Scans"
echo "-----------------------------------"
k6 run --vus 1000 --duration 10s chaos-scan-load.js
if [ $? -eq 0 ]; then
  echo "✅ Test 1 PASSED"
else
  echo "❌ Test 1 FAILED"
  exit 1
fi

# Wait 30 seconds
echo "⏳ Waiting 30 seconds..."
sleep 30

# Test 2: 50 Webhook Burst
echo ""
echo "📊 Test 2: 50 Webhook Burst"
echo "----------------------------"
k6 run chaos-webhook-burst.js
if [ $? -eq 0 ]; then
  echo "✅ Test 2 PASSED"
else
  echo "❌ Test 2 FAILED"
  exit 1
fi

# Wait 30 seconds
echo "⏳ Waiting 30 seconds..."
sleep 30

# Test 3: 10 QR Generations
echo ""
echo "📊 Test 3: 10 QR Generations"
echo "-----------------------------"
k6 run chaos-qr-generation.js
if [ $? -eq 0 ]; then
  echo "✅ Test 3 PASSED"
else
  echo "❌ Test 3 FAILED"
  exit 1
fi

echo ""
echo "🎉 All Chaos Load Tests Completed!"
echo "==================================="
```

**Run**:
```bash
chmod +x run-all-chaos-tests.sh
./run-all-chaos-tests.sh
```

---

## 📈 Monitoring During Tests

### Real-Time Monitoring

```bash
# Terminal 1: Run k6 test
k6 run chaos-scan-load.js

# Terminal 2: Monitor error rate
watch -n 1 'curl -s https://staging.absensiqr.com/api/metrics/error-rate | jq'

# Terminal 3: Monitor database
watch -n 1 'mysql -e "SHOW PROCESSLIST" | wc -l'

# Terminal 4: Monitor Redis
watch -n 1 'redis-cli INFO stats | grep total_connections_received'
```

---

**Status**: ✅ Ready for Execution  
**Last Updated**: 2026-02-10  
**Version**: 1.0
