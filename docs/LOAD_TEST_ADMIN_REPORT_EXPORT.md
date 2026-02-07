# Load Test: Admin Report Export

## 📋 Overview

Test to ensure PDF report generation is **queued asynchronously** and does **not block** the API response.

---

## 🎯 Test Scenario

**Simulate:** 10 admins exporting monthly reports simultaneously

| Parameter | Value |
|-----------|-------|
| **Concurrent Admins** | 10 |
| **Request Type** | POST |
| **Report Type** | Monthly PDF |
| **Endpoint** | `/api/v1/admin/reports/export-pdf` |

---

## ✅ Pass Criteria

| Metric | Threshold | Validates |
|--------|-----------|-----------|
| **Response Time (p95)** | < 1000ms | Jobs are queued, not executed |
| **Max Response Time** | < 2000ms | No blocking I/O |
| **Jobs Queued** | ~10 | All exports queued |
| **Failed Requests** | < 10% | Error handling works |
| **Memory Usage** | Normal | No memory spike |

---

## 🚀 Run the Test

```bash
# 1. Run load test (10 simultaneous exports)
k6 run tests/load/admin-report-export-load-test.js

# 2. Verify jobs were queued
cd backend
php ../tests/load/verify-export-jobs.php

# 3. Process queued jobs
php artisan queue:work
```

---

## 📊 Expected Results

### Load Test Output

```
✅ Admin 1: Job queued (245ms)
✅ Admin 2: Job queued (198ms)
✅ Admin 3: Job queued (312ms)
...

✓ status is 200 or 202 (Accepted)
✓ response time < 1000ms (job queued)  ← KEY METRIC
✓ has job ID or message
✓ no timeout

total_requests.................: 10
jobs_queued....................: 10     ✅
response_time (p95)............: 425ms  ✅ < 1000ms
slow_requests..................: 0      ✅
http_req_duration (p95)........: 425ms  ✅
```

### Verification Output

```
📊 Queued Jobs (in 'jobs' table): 10

✅ PASS: Job count matches expected (8-12 jobs)

📋 Queued Job Details:
  Job 1:
    Type: App\Jobs\GenerateMonthlyReport
    Queue: default
    Attempts: 0
    Created: 2026-02-02 16:10:15

  Job 2:
    Type: App\Jobs\GenerateMonthlyReport
    ...

❌ Failed Jobs Check:
✅ No failed jobs (good)

💾 Memory Usage Check:
  Memory limit: 256M
  Current usage: 45.23 MB
  ✅ Normal memory usage

✅ SUCCESS: Report export jobs queued correctly!
```

---

## 🔍 What This Validates

### 1. **Jobs Are Queued (Async)**

**If response < 1000ms** → PDF generation queued ✅  
**If response > 3000ms** → PDF generated synchronously ❌

**Proper Implementation:**
```php
// GOOD: Queue the job
dispatch(new GenerateMonthlyReport($params));

return response()->json([
    'success' => true,
    'message' => 'Report generation queued',
    'job_id' => $jobId,
], 202); // 202 Accepted
```

**Wrong Implementation:**
```php
// BAD: Generate PDF immediately (blocks request)
$pdf = PDF::loadView('reports.monthly', $data);
$pdf->save($path);

return response()->json([
    'success' => true,
    'file_url' => $url,
]);
```

### 2. **No Memory Spike**

**PDF Generation is memory-intensive:**
- Large reports = 50-200MB memory
- 10 simultaneous = 500-2000MB if not queued
- Queued jobs = processed one-by-one

**Test Proves:**
- ✅ Memory stays normal during requests
- ✅ Queue workers handle heavy processing
- ✅ API server not overloaded

### 3. **Proper Status Code**

**Expected Response:**
- Status: `202 Accepted` (job queued)
- OR Status: `200 OK` with job_id
- Body includes: `job_id` or `message`

---

## 📈 Performance Comparison

| Scenario | Response Time | Memory | Status |
|----------|---------------|--------|--------|
| **Sync (Bad)** | ~3-5 seconds | 500MB+ | ❌ |
| **Async (Good)** | ~200-500ms | Normal | ✅ |

**10x faster response with async processing!**

---

## 🔧 Implementation Requirements

### Laravel Queue Job Example

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMonthlyReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes max
    public $tries = 3;
    
    protected $params;

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    public function handle()
    {
        // Generate PDF report
        $pdf = PDF::loadView('reports.monthly', [
            'data' => $this->fetchReportData(),
        ]);
        
        // Save to storage
        $filename = 'monthly-' . date('Y-m') . '.pdf';
        $pdf->save(storage_path("reports/{$filename}"));
        
        // Notify admin
        // Send email or notification
    }
}
```

### Controller Example

```php
public function exportPdf(Request $request)
{
    $validated = $request->validate([
        'report_type' => 'required|in:daily,weekly,monthly',
        'date_from' => 'required|date',
        'date_to' => 'required|date',
    ]);
    
    // Dispatch job to queue
    $job = new GenerateMonthlyReport($validated);
    $jobId = dispatch($job);
    
    return response()->json([
        'success' => true,
        'message' => 'Report generation has been queued',
        'job_id' => $jobId,
        'estimated_time' => '2-5 minutes',
    ], 202); // 202 = Accepted (processing)
}
```

---

## 🎯 Why This Test Matters

**Real-World Impact:**

**Without Queueing (Sync):**
```
Admin 1: Request → Generate PDF (3s) → Response (3s)
Admin 2: Request → Generate PDF (3s) → Response (3s)
...
Total time: 30 seconds
API blocked for 30 seconds 🔴
```

**With Queueing (Async):**
```
Admin 1: Request → Queue job → Response (0.3s)
Admin 2: Request → Queue job → Response (0.3s)
...
Total time: 3 seconds for all requests
Worker processes PDFs in background 🟢
```

---

## 📋 Queue Configuration

### Enable Queue Worker

```bash
# Development
php artisan queue:work

# Production (with Supervisor)
php artisan queue:work --tries=3 --timeout=300
```

### Queue Driver (config/queue.php)

```php
'default' => env('QUEUE_CONNECTION', 'database'),

'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ],
],
```

### Run Migration

```bash
php artisan queue:table
php artisan migrate
```

---

## 🧹 Cleanup Test Data

```bash
# Clear test jobs from queue
php artisan queue:flush

# Or manually
php artisan tinker
>>> DB::table('jobs')->truncate();
>>> DB::table('failed_jobs')->truncate();
```

---

## 🔍 Troubleshooting

### Issue: Response Time > 1000ms

**Diagnosis:** PDF generated synchronously

**Fix:** Implement queue job as shown above

### Issue: Jobs Not in Queue

**Diagnosis:** Queue driver not configured

**Fix:**
```bash
# Set queue driver
# In .env
QUEUE_CONNECTION=database

# Run migration
php artisan queue:table
php artisan migrate
```

### Issue: Memory Errors

**Diagnosis:** Generating PDFs in request

**Fix:** Use queue + increase worker memory
```bash
php artisan queue:work --memory=512
```

---

## ✅ Success Indicators

**All green means:**
- ✅ Report exports are fully async
- ✅ API responds instantly (< 1s)
- ✅ No memory spike on API server
- ✅ Jobs queued for background processing
- ✅ Production-ready implementation

---

## 📁 Files Created

1. `tests/load/admin-report-export-load-test.js` - k6 load test
2. `tests/load/verify-export-jobs.php` - Job verification
3. `docs/LOAD_TEST_ADMIN_REPORT_EXPORT.md` - This documentation

---

**Test async report generation under concurrent load!** 📊
