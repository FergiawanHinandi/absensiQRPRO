/**
 * Load Test: Admin Report Export - Simultaneous PDF Generation
 * 
 * Simulates 10 admins exporting monthly reports simultaneously to test:
 * 1. Report generation jobs are queued (async)
 * 2. API response time < 1 second (not blocking)
 * 3. No memory spike or timeout
 * 
 * Test Profile:
 * - 10 concurrent admins
 * - Each exports monthly report
 * - Total: 10 PDF export requests
 * 
 * Pass Criteria:
 * - Response time < 1000ms (proves jobs are queued)
 * - All requests return 200/202
 * - No timeout or memory errors
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// Custom metrics
const responseTrend = new Trend('response_time');
const jobsQueued = new Counter('jobs_queued');
const slowRequests = new Counter('slow_requests');
const totalRequests = new Counter('total_requests');

// Test configuration
export const options = {
    scenarios: {
        simultaneous_exports: {
            executor: 'shared-iterations',
            vus: 10,                     // 10 concurrent admins
            iterations: 10,               // 10 total export requests
            maxDuration: '30s',           // Maximum 30 seconds
        },
    },
    thresholds: {
        'http_req_duration': ['p(95)<1000'],   // Response must be < 1s (queued, not executed)
        'http_req_duration': ['max<2000'],     // No request should take > 2s
        'slow_requests': ['count<2'],          // Max 2 slow requests (20%)
        'http_req_failed': ['rate<0.1'],       // Less than 10% failures
    },
};

// Configuration
const BASE_URL = __ENV.API_URL || 'http://localhost:8000';
const API_TOKEN = __ENV.API_TOKEN || 'test-admin-token';
const ENDPOINT = `${BASE_URL}/api/v1/admin/reports/export-pdf`;

// Generate report parameters
function getReportParams() {
    const now = new Date();
    const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
    const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0);

    return {
        report_type: 'monthly',
        date_from: monthStart.toISOString().split('T')[0],
        date_to: monthEnd.toISOString().split('T')[0],
        school_id: 1,
        format: 'pdf',
        include_charts: true,
        include_summary: true,
        admin_id: `admin-${__VU}`,
    };
}

export default function () {
    const reportParams = getReportParams();

    const payload = JSON.stringify(reportParams);

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${API_TOKEN}`,
            'X-Admin-ID': `admin-${__VU}`,
            'X-Test-Type': 'report-export',
        },
        timeout: '5s',
        tags: {
            name: 'AdminReportExport',
            test_type: 'pdf_export',
        },
    };

    // Measure request start time
    const startTime = Date.now();

    console.log(`Admin ${__VU} requesting report export...`);

    // Execute request
    const response = http.post(ENDPOINT, payload, params);

    // Measure response time
    const responseTime = Date.now() - startTime;
    responseTrend.add(responseTime);
    totalRequests.add(1);

    // Track slow requests (> 1000ms indicates job is being executed, not queued)
    if (responseTime > 1000) {
        slowRequests.add(1);
        console.warn(`⚠️  SLOW REQUEST (Admin ${__VU}): ${responseTime}ms - Job may NOT be queued!`);
    }

    // Validate response
    const isValidResponse = check(response, {
        'status is 200 or 202 (Accepted)': (r) => r.status === 200 || r.status === 202,
        'response time < 1000ms (job queued)': (r) => responseTime < 1000,
        'has success field': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.success !== undefined;
            } catch (e) {
                return false;
            }
        },
        'has job ID or message': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.data?.job_id !== undefined ||
                    body.message !== undefined ||
                    body.data?.message !== undefined;
            } catch (e) {
                return false;
            }
        },
        'no timeout': (r) => responseTime < 5000,
        'no server error': (r) => r.status < 500,
    });

    // Count queued jobs (status 202 or fast response)
    if ((response.status === 202 || response.status === 200) && responseTime < 1000) {
        jobsQueued.add(1);
        console.log(`✅ Admin ${__VU}: Job queued (${responseTime}ms)`);
    }

    // Log response details
    if (isValidResponse) {
        try {
            const body = JSON.parse(response.body);
            if (body.data?.job_id) {
                console.log(`   Job ID: ${body.data.job_id}`);
            }
        } catch (e) {
            // Ignore parsing errors
        }
    }

    // Log performance issues
    if (responseTime > 1000) {
        console.error(`❌ PERFORMANCE ISSUE (Admin ${__VU}): ${responseTime}ms`);
        console.error(`   Status: ${response.status}`);
        console.error(`   This indicates PDF generation is NOT queued!`);
    }

    // Log errors
    if (!isValidResponse || response.status >= 400) {
        console.error(`❌ ERROR (Admin ${__VU}): Status ${response.status}, Time ${responseTime}ms`);
        if (response.body) {
            console.error(`   Response: ${response.body.substring(0, 200)}`);
        }
    }

    // Small delay before next iteration
    sleep(0.1);
}

// Setup function
export function setup() {
    console.log('='.repeat(70));
    console.log('Admin Report Export - Simultaneous PDF Generation Test');
    console.log('='.repeat(70));
    console.log(`API URL: ${BASE_URL}`);
    console.log(`Endpoint: POST /api/v1/admin/reports/export-pdf`);
    console.log(`Concurrent Admins: 10`);
    console.log(`Report Type: Monthly PDF`);
    console.log('='.repeat(70));
    console.log('Testing:');
    console.log('  ✓ Jobs queued asynchronously');
    console.log('  ✓ API response < 1 second');
    console.log('  ✓ No memory spike or timeout');
    console.log('='.repeat(70));
    console.log('Pass Criteria:');
    console.log('  ✓ p95 response time < 1000ms');
    console.log('  ✓ All requests return 200/202');
    console.log('  ✓ No timeout (< 5s)');
    console.log('  ✓ Jobs appear in queue');
    console.log('='.repeat(70));
    console.log('\n🚀 Starting simultaneous export requests...\n');

    return {
        startTime: new Date().toISOString(),
    };
}

// Teardown function
export function teardown(data) {
    console.log('\n' + '='.repeat(70));
    console.log('Admin Report Export Test - Completed');
    console.log('='.repeat(70));
    console.log(`Started: ${data.startTime}`);
    console.log(`Ended: ${new Date().toISOString()}`);
    console.log('='.repeat(70));
    console.log('\n📊 Key Metrics to Review:');
    console.log('  • total_requests - Should be 10');
    console.log('  • jobs_queued - Should be ~10');
    console.log('  • response_time (p95) - Should be < 1000ms');
    console.log('  • slow_requests - Should be < 2 (< 20%)');
    console.log('\n✅ If p95 < 1000ms = Jobs are queued (ASYNC) ✓');
    console.log('❌ If p95 > 1000ms = Jobs executed immediately (SYNC) ✗');
    console.log('='.repeat(70));
    console.log('\n📋 Next Steps:');
    console.log('  1. Run: php tests/load/verify-export-jobs.php');
    console.log('  2. Check jobs table for queued export jobs');
    console.log('  3. Monitor queue worker processing');
    console.log('  4. Verify PDF files are generated');
    console.log('='.repeat(70));
}
