# Chaos Engineering Experiments

This directory contains chaos engineering experiments to validate system resilience.

## Quick Start

### Prerequisites

```bash
# Install k6 (load testing)
brew install k6  # macOS
# or
sudo apt install k6  # Ubuntu

# Install jq (JSON parsing)
brew install jq  # macOS
# or
sudo apt install jq  # Ubuntu

# Python 3 (for metrics analysis)
python3 --version
```

### Running Experiments

#### 1. Redis Crash Test

```bash
# Make script executable
chmod +x chaos/redis-crash.sh

# Run experiment
./chaos/redis-crash.sh

# Analyze results
python3 chaos/analyze-metrics.py chaos/logs/redis-crash-metrics-*.csv
```

#### 2. Load Test (10K Concurrent Scans)

```bash
# Set environment variables
export BASE_URL=http://localhost
export API_TOKEN=your-api-token

# Run load test
k6 run --vus 1000 --duration 60s chaos/concurrent-scans.js
```

#### 3. Webhook Idempotency Test

```bash
# Run webhook duplicate test
k6 run chaos/webhook-duplicate.js

# Expected: 1 success, 49 idempotent rejections
```

## Experiment Files

### Load Tests (k6)

- `concurrent-scans.js` - 10,000 concurrent attendance scans
- `webhook-duplicate.js` - 50 duplicate webhook deliveries

### Chaos Scripts

- `redis-crash.sh` - Redis failure and recovery test
- `analyze-metrics.py` - Metrics analysis and resilience scoring

## Metrics Collected

Each experiment collects:

- **Response Time**: p50, p95, p99, max
- **Error Rate**: 4xx, 5xx errors
- **Circuit Breaker State**: closed, open, half_open
- **System Health**: Redis, database, queue status
- **Data Integrity**: Duplicate count, data consistency

## Interpreting Results

### Resilience Score

- **95-100 (A+)**: Excellent resilience
- **90-94 (A)**: Very good resilience
- **85-89 (A-)**: Good resilience
- **80-84 (B+)**: Acceptable resilience
- **<80**: Needs improvement

### Success Criteria

✅ **Pass**: 
- Error rate < 1%
- p95 response time < 500ms
- No data loss or duplicates
- Automatic recovery

❌ **Fail**:
- Error rate > 5%
- p95 response time > 2s
- Data corruption
- Manual intervention required

## Example Output

```
CHAOS EXPERIMENT METRICS ANALYSIS
============================================================

Experiment Duration: 300s (5.0 minutes)
Data Points: 300

RESPONSE TIME STATISTICS
------------------------------------------------------------
  Average:  85.23ms
  Median:   52.10ms
  p95:      180.45ms
  p99:      320.12ms
  Max:      450.00ms

REDIS STATUS
------------------------------------------------------------
  Up:       180 samples (60.0%)
  Down:     120 samples (40.0%)

CIRCUIT BREAKER STATE
------------------------------------------------------------
  Closed:     180 samples (60.0%)
  Open:       100 samples (33.3%)
  Half-Open:  20 samples (6.7%)

CIRCUIT BREAKER TRANSITIONS
------------------------------------------------------------
  T+ 35s: closed     → open
  T+155s: open       → half_open
  T+165s: half_open  → closed

RESILIENCE SCORE
------------------------------------------------------------
  Overall Score: 95/100
  Grade: A+
```

## Troubleshooting

### Experiment Fails to Start

```bash
# Check Docker is running
docker ps

# Check API is accessible
curl http://localhost/api/health

# Check permissions
chmod +x chaos/*.sh
```

### No Metrics Collected

```bash
# Check logs directory exists
mkdir -p chaos/logs

# Check API endpoints
curl http://localhost/api/health/circuit-breaker
```

### High Error Rate

This might be expected during chaos experiments. Check:
- Circuit breaker is working (state transitions)
- Fallback mechanisms activated
- No data corruption

## Safety

⚠️ **WARNING**: These experiments inject real failures!

**Best Practices**:
- Run during low-traffic periods
- Notify team before running
- Have rollback plan ready
- Monitor closely
- Start with low-risk experiments

**Rollback**:
```bash
# If experiment goes wrong
docker start redis
docker start postgres
php artisan queue:restart
```

## Next Steps

1. Review full plan: `docs/CHAOS_ENGINEERING_PLAN.md`
2. Schedule first game day
3. Set up monitoring dashboard
4. Run experiments in order of risk
5. Document findings

## Support

- **Documentation**: `docs/CHAOS_ENGINEERING_PLAN.md`
- **Logs**: `chaos/logs/`
- **Metrics**: `chaos/logs/*-metrics-*.csv`
