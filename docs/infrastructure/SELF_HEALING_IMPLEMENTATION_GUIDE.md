# Self-Healing Infrastructure Implementation Guide

## Overview

This guide provides step-by-step instructions for implementing the autonomous self-healing infrastructure for the Laravel multi-tenant attendance system.

---

## Phase 1: Health Checks & Monitoring (Week 1-2)

### Step 1.1: Verify Health Check Endpoint

```bash
# Test the deep health check endpoint
curl http://localhost/api/v1/health/deep | jq .
```

**Expected Response:**
```json
{
  "status": "healthy",
  "timestamp": "2026-02-11T09:04:37+08:00",
  "response_time_ms": 45.23,
  "checks": {
    "database": { "status": "healthy", ... },
    "redis": { "status": "healthy", ... },
    "queue": { "status": "healthy", ... },
    "disk": { "status": "healthy", ... },
    "memory": { "status": "healthy", ... },
    "tenant_isolation": { "status": "healthy", ... }
  }
}
```

### Step 1.2: Configure Monitoring

Add to `.env`:
```env
# Self-Healing Configuration
SELF_HEALING_ENABLED=true
CIRCUIT_BREAKER_ENABLED=true
AUTO_REMEDIATION_ENABLED=true
DEGRADED_MODE_DURATION=600

# Queue Configuration
QUEUE_MAX_WORKERS=10
QUEUE_LAG_WARNING_THRESHOLD=500
QUEUE_LAG_CRITICAL_THRESHOLD=2000

# Redis Configuration
REDIS_MEMORY_WARNING_THRESHOLD=85
REDIS_MEMORY_CRITICAL_THRESHOLD=95
```

### Step 1.3: Set Up Health Check Monitoring

Configure external monitoring (e.g., Prometheus, Datadog) to scrape `/health/deep` every 30 seconds:

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'attendance-health'
    scrape_interval: 30s
    metrics_path: /api/v1/health/deep
    static_configs:
      - targets: ['attendance-app:80']
```

---

## Phase 2: Circuit Breakers (Week 3-4)

### Step 2.1: Implement Redis Circuit Breaker

Update your Redis cache calls to use circuit breaker:

```php
use App\Services\SelfHealing\CircuitBreaker;

class AttendanceService
{
    private CircuitBreaker $redisCircuitBreaker;
    
    public function __construct()
    {
        $config = config('self-healing.redis');
        $this->redisCircuitBreaker = new CircuitBreaker(
            'redis',
            $config['failure_threshold'],
            $config['success_threshold'],
            $config['timeout'],
            $config['fallback']
        );
    }
    
    public function cacheAttendance($data)
    {
        try {
            return $this->redisCircuitBreaker->call(function () use ($data) {
                return Cache::put('attendance:' . $data['id'], $data, 3600);
            });
        } catch (CircuitBreakerOpenException $e) {
            // Fallback to database cache
            Log::warning('Redis circuit breaker open, using DB cache');
            return DB::table('cache')->insert([
                'key' => 'attendance:' . $data['id'],
                'value' => serialize($data),
                'expiration' => now()->addHour()->timestamp,
            ]);
        }
    }
}
```

### Step 2.2: Test Circuit Breaker

```bash
# Check circuit breaker status
php artisan circuit-breaker:status redis

# Simulate Redis failure
docker stop redis

# Trigger attendance operations (should open circuit)
curl -X POST http://localhost/api/v1/attendance/scan \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"qr_token":"test"}'

# Check circuit breaker status (should be OPEN)
php artisan circuit-breaker:status redis

# Restore Redis
docker start redis

# Wait 10 seconds for circuit to attempt reset
sleep 10

# Check circuit breaker status (should be CLOSED)
php artisan circuit-breaker:status redis
```

### Step 2.3: Add Circuit Breaker Alerts

Create listener for circuit breaker events:

```php
// app/Listeners/AlertCircuitBreakerOpened.php
namespace App\Listeners;

use App\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Log;

class AlertCircuitBreakerOpened
{
    public function handle(CircuitBreakerOpened $event)
    {
        Log::critical('Circuit breaker opened', [
            'name' => $event->name,
            'error' => $event->exception->getMessage(),
            'context' => $event->context,
        ]);
        
        // Send to PagerDuty/Slack
        // TODO: Implement alerting integration
    }
}
```

Register in `EventServiceProvider`:

```php
protected $listen = [
    CircuitBreakerOpened::class => [
        AlertCircuitBreakerOpened::class,
    ],
];
```

---

## Phase 3: Auto-Remediation (Week 5-6)

### Step 3.1: Integrate Auto-Remediation Service

Update exception handler to use auto-remediation:

```php
// app/Exceptions/Handler.php
use App\Services\SelfHealing\AutoRemediationService;
use App\Services\SelfHealing\FailureClassifier;

public function register()
{
    $this->reportable(function (Throwable $e) {
        $classifier = app(FailureClassifier::class);
        $remediation = app(AutoRemediationService::class);
        
        $context = [
            'redis_memory_percent' => $this->getRedisMemoryPercent(),
            'db_connection_percent' => $this->getDbConnectionPercent(),
            'queue_lag' => $this->getQueueLag(),
        ];
        
        $failureType = $classifier->classify($e, $context);
        
        if ($failureType !== FailureType::UNKNOWN) {
            $remediation->remediate($e, $context);
        }
    });
}
```

### Step 3.2: Configure Auto-Scaling Triggers

Create scheduled command to check metrics:

```php
// app/Console/Commands/CheckAutoScaling.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SelfHealing\AutoRemediationService;
use Illuminate\Support\Facades\DB;

class CheckAutoScaling extends Command
{
    protected $signature = 'self-healing:check-scaling';
    
    public function handle(AutoRemediationService $remediation)
    {
        $queueLag = DB::table('jobs')->count();
        
        if ($queueLag > config('self-healing.remediation.queue.lag_critical_threshold')) {
            $this->warn("Queue lag critical: {$queueLag}");
            $remediation->remediate(
                new \Exception('Queue lag critical'),
                ['queue_lag' => $queueLag]
            );
        }
    }
}
```

Schedule in `Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('self-healing:check-scaling')
        ->everyMinute()
        ->withoutOverlapping();
}
```

### Step 3.3: Test Auto-Remediation

```bash
# Monitor self-healing system
php artisan self-healing:monitor

# Simulate queue lag
for i in {1..1000}; do
  php artisan queue:push TestJob
done

# Check if auto-scaling triggered
php artisan self-healing:monitor

# Check queue worker scaling
kubectl get hpa attendance-queue-worker
```

---

## Phase 4: Kubernetes Auto-Scaling (Week 7-8)

### Step 4.1: Deploy HPA Configurations

```bash
# Deploy queue worker HPA
kubectl apply -f infrastructure/k8s/hpa-queue-worker.yaml

# Deploy web app HPA
kubectl apply -f infrastructure/k8s/hpa-web-app.yaml

# Verify HPA status
kubectl get hpa -n production
```

### Step 4.2: Configure Custom Metrics

Install Prometheus Adapter for custom metrics:

```bash
helm install prometheus-adapter prometheus-community/prometheus-adapter \
  --namespace monitoring \
  --values infrastructure/k8s/prometheus-adapter-values.yaml
```

Create custom metrics configuration:

```yaml
# infrastructure/k8s/prometheus-adapter-values.yaml
rules:
  - seriesQuery: 'queue_lag_jobs'
    resources:
      overrides:
        namespace: {resource: "namespace"}
        pod: {resource: "pod"}
    name:
      matches: "^(.*)$"
      as: "queue_lag_jobs"
    metricsQuery: 'avg(<<.Series>>{<<.LabelMatchers>>})'
```

### Step 4.3: Export Custom Metrics

Create metrics exporter job:

```php
// app/Console/Commands/ExportMetrics.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis;

class ExportMetrics extends Command
{
    protected $signature = 'metrics:export';
    
    public function handle()
    {
        $registry = new CollectorRegistry(new Redis());
        
        // Queue lag
        $queueLag = DB::table('jobs')->count();
        $activeWorkers = Cache::get('metrics:active_workers', 1);
        $lagPerWorker = $queueLag / $activeWorkers;
        
        $gauge = $registry->getOrRegisterGauge(
            'attendance',
            'queue_lag_jobs',
            'Queue lag per worker'
        );
        $gauge->set($lagPerWorker);
        
        // HTTP RPS
        $rps = Cache::get('metrics:http_rps', 0);
        $gauge = $registry->getOrRegisterGauge(
            'attendance',
            'http_requests_per_second',
            'HTTP requests per second'
        );
        $gauge->set($rps);
    }
}
```

Schedule metrics export:

```php
$schedule->command('metrics:export')
    ->everyMinute()
    ->withoutOverlapping();
```

---

## Phase 5: Chaos Testing (Week 9)

### Step 5.1: Run Chaos Tests

```bash
# Make script executable
chmod +x infrastructure/scripts/chaos-test.sh

# Run non-destructive tests
./infrastructure/scripts/chaos-test.sh

# Run all tests (including destructive)
RUN_DESTRUCTIVE_TESTS=true ./infrastructure/scripts/chaos-test.sh
```

### Step 5.2: Validate Recovery SLAs

Check the chaos test log for recovery times:

```bash
cat chaos-test-*.log | grep "complete"
```

**Expected Results:**
- Redis failure recovery: < 30 seconds
- Worker crash recovery: < 60 seconds
- Network partition recovery: < 30 seconds

### Step 5.3: Schedule Regular Chaos Tests

```yaml
# k8s/chaos-test-cronjob.yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: chaos-test
  namespace: production
spec:
  schedule: "0 2 * * 0"  # Every Sunday at 2 AM
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: chaos-test
            image: attendance/chaos-test:latest
            command: ["/bin/bash", "/scripts/chaos-test.sh"]
          restartPolicy: OnFailure
```

---

## Monitoring & Alerts

### Prometheus Alerts

```yaml
# infrastructure/prometheus/alerts.yml
groups:
  - name: self-healing
    rules:
      - alert: CircuitBreakerOpen
        expr: circuit_breaker_state{state="open"} > 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "Circuit breaker {{ $labels.name }} is OPEN"
          
      - alert: SystemDegradedMode
        expr: system_degraded_mode > 0
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "System in degraded mode"
          
      - alert: AutoRemediationFailed
        expr: rate(auto_remediation_failures[5m]) > 0.1
        labels:
          severity: critical
        annotations:
          summary: "Auto-remediation failing"
```

---

## Troubleshooting

### Circuit Breaker Stuck Open

```bash
# Check circuit breaker status
php artisan circuit-breaker:status redis

# Manually reset if needed
php artisan tinker
>>> $breaker = new \App\Services\SelfHealing\CircuitBreaker('redis', 3, 2, 10);
>>> $breaker->reset();
```

### Auto-Scaling Not Triggering

```bash
# Check HPA status
kubectl describe hpa attendance-queue-worker

# Check custom metrics
kubectl get --raw /apis/custom.metrics.k8s.io/v1beta1/namespaces/production/pods/*/queue_lag_jobs

# Check metrics exporter
php artisan metrics:export
```

### Degraded Mode Not Disabling

```bash
# Check degraded mode status
php artisan self-healing:monitor

# Manually disable
php artisan tinker
>>> $remediation = app(\App\Services\SelfHealing\AutoRemediationService::class);
>>> $remediation->disableDegradedMode();
```

---

## Production Checklist

- [ ] Health check endpoint accessible
- [ ] Circuit breakers configured for Redis, DB, external APIs
- [ ] Auto-remediation service integrated
- [ ] HPA deployed for queue workers and web app
- [ ] Custom metrics exported to Prometheus
- [ ] Alerts configured in Prometheus/PagerDuty
- [ ] Chaos tests scheduled
- [ ] Team trained on self-healing behavior
- [ ] Runbooks documented for manual escalation
- [ ] Monitoring dashboards created

---

## Next Steps

1. **Week 10+**: Monitor production behavior
2. **Month 2**: Tune thresholds based on real traffic
3. **Month 3**: Add more sophisticated ML-based anomaly detection
4. **Month 6**: Implement predictive scaling based on historical patterns
