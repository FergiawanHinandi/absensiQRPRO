# 🚀 Load Test Suite - AbsensiQR Pro SaaS

Comprehensive load testing suite untuk mensimulasikan **10,000 concurrent attendance scan** dengan chaos engineering.

## 📋 Test Scenarios

### 1. Student Scan Burst
- **5000 students** scan QR dalam **5 detik** (1000 RPS)
- Validates: Concurrency handling, duplicate prevention, Redis locks

### 2. Teacher QR Generation
- **10 teachers** generate QR bersamaan untuk schedule yang sama
- Validates: Single active QR per schedule, race condition handling

### 3. Webhook Burst
- **100 webhooks** bersamaan dari Midtrans
- Validates: Idempotency, double subscription prevention

### 4. Chaos Engineering
- **Redis restart** saat load tinggi
- **DB latency injection** (300ms)
- Validates: System resilience, graceful degradation

## 🎯 Acceptance Criteria

| Metric | Threshold | Status |
|--------|-----------|--------|
| Error Rate | < 1% | ⚠️ |
| P95 Response Time | < 1000ms | ⚠️ |
| P99 Response Time | < 2000ms | ⚠️ |
| Duplicate Attendance | 0 | ❌ |
| Deadlock Errors | < 10 | ⚠️ |
| CPU Usage | < 80% | ⚠️ |
| Memory Usage | < 80% | ⚠️ |
| Redis Memory | < 1GB | ⚠️ |

## 🛠️ Prerequisites

### Install k6
```bash
# macOS
brew install k6

# Linux
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6

# Windows
choco install k6
```

### Install Artillery
```bash
npm install -g artillery
```

### Install Python Dependencies
```bash
pip install -r requirements.txt
```

## 🚀 Running Tests

### Option 1: K6 Load Test (Recommended)

```bash
# 1. Start system monitoring
./monitor-system.sh > system-metrics.log &
MONITOR_PID=$!

# 2. Run k6 load test
k6 run --vus 5000 --duration 60s k6-attendance-load-test.js

# 3. Stop monitoring
kill $MONITOR_PID

# 4. Analyze results
python analyze-results.py load-test-results.json system-metrics.log
```

### Option 2: Artillery Load Test

```bash
# 1. Start system monitoring
./monitor-system.sh > system-metrics.log &
MONITOR_PID=$!

# 2. Run Artillery test
artillery run --output report.json artillery-chaos-test.yml

# 3. Generate HTML report
artillery report report.json

# 4. Stop monitoring
kill $MONITOR_PID

# 5. Analyze results
python analyze-results.py report.json system-metrics.log
```

### Option 3: Full Chaos Test

```bash
# Run complete chaos engineering test
./run-chaos-test.sh
```

## 📊 Monitoring During Test

### Real-time Metrics

```bash
# Terminal 1: System metrics
watch -n 1 'echo "=== CPU & Memory ===" && top -bn1 | head -20'

# Terminal 2: Redis metrics
watch -n 1 'redis-cli INFO stats | grep -E "instantaneous_ops_per_sec|used_memory_human"'

# Terminal 3: MySQL metrics
watch -n 1 'mysql -u root -ppassword -e "SHOW STATUS LIKE \"Threads_connected\"; SHOW STATUS LIKE \"Innodb_row_lock_waits\";"'

# Terminal 4: Application logs
tail -f backend/storage/logs/laravel.log | grep -E "ERROR|WARNING|CRITICAL"
```

### Grafana Dashboard (Optional)

If you have Grafana + Prometheus setup:

```bash
# Import dashboard
curl -X POST http://localhost:3000/api/dashboards/db \
  -H "Content-Type: application/json" \
  -d @grafana-dashboard.json
```

## 🔥 Chaos Engineering Scenarios

### Scenario 1: Redis Restart

```bash
# During load test (at 15s mark)
docker restart redis

# Expected: System should fallback to DB locks
# Acceptance: Error rate < 5% during restart, < 1% after recovery
```

### Scenario 2: Database Latency Injection

```bash
# Add 300ms latency
tc qdisc add dev eth0 root netem delay 300ms

# Run load test

# Remove latency
tc qdisc del dev eth0 root

# Expected: Response time increases but no errors
# Acceptance: P95 < 1500ms during latency, < 1000ms after
```

### Scenario 3: Memory Pressure

```bash
# Fill memory to 90%
stress-ng --vm 1 --vm-bytes 90% --vm-method all --verify -t 60s &

# Run load test

# Expected: System should not crash
# Acceptance: No OOM errors, graceful degradation
```

### Scenario 4: CPU Saturation

```bash
# Saturate CPU to 95%
stress-ng --cpu 8 --cpu-load 95 -t 60s &

# Run load test

# Expected: Slower response times but no errors
# Acceptance: Error rate < 1%, P99 < 3000ms
```

## 📈 Expected Results

### Baseline (No Load)
- Response Time: 50-100ms
- CPU Usage: 5-10%
- Memory Usage: 20-30%
- Redis Memory: 50-100MB

### Under Load (5000 concurrent)
- Response Time: 200-500ms (P95)
- CPU Usage: 60-70%
- Memory Usage: 50-60%
- Redis Memory: 200-300MB

### Peak Load (10000 concurrent)
- Response Time: 500-1000ms (P95)
- CPU Usage: 70-80%
- Memory Usage: 60-70%
- Redis Memory: 300-500MB

## 🐛 Known Issues & Workarounds

### Issue 1: Duplicate Attendance
**Symptom**: Multiple attendance records for same student+schedule+date  
**Root Cause**: Multiple teachers can generate QR for same schedule  
**Workaround**: Implement single active QR per schedule  
**Fix**: See `PRODUCTION_APPROVAL_CHECKLIST_2026.md` BLOCKER #1

### Issue 2: Deadlock Errors
**Symptom**: 500 errors with "deadlock detected"  
**Root Cause**: No deadlock retry mechanism  
**Workaround**: Reduce concurrent load  
**Fix**: Implement `DeadlockRetryMiddleware`

### Issue 3: Redis Connection Errors
**Symptom**: "Connection refused" when Redis down  
**Root Cause**: No fallback mechanism  
**Workaround**: Keep Redis always running  
**Fix**: Implement Redis failure fallback to DB locks

### Issue 4: Slow Dashboard
**Symptom**: Dashboard takes 2-3 seconds to load  
**Root Cause**: No pre-aggregated data  
**Workaround**: Reduce date range  
**Fix**: Create `attendance_summaries` table

## 📊 Metrics Interpretation

### Response Time
- **< 200ms**: Excellent
- **200-500ms**: Good
- **500-1000ms**: Acceptable under load
- **> 1000ms**: Poor, needs optimization

### Error Rate
- **< 0.1%**: Excellent
- **0.1-1%**: Acceptable
- **1-5%**: Poor, needs investigation
- **> 5%**: Critical, system unstable

### CPU Usage
- **< 50%**: Underutilized
- **50-70%**: Optimal
- **70-80%**: High, consider scaling
- **> 80%**: Critical, scale immediately

### Memory Usage
- **< 60%**: Healthy
- **60-80%**: Acceptable
- **> 80%**: High, risk of OOM

## 🔧 Optimization Recommendations

Based on load test results, prioritize these optimizations:

### Priority 1: Critical (Fix Before Launch)
1. ✅ Implement single active QR per schedule
2. ✅ Add deadlock retry middleware
3. ✅ Implement Redis failure fallback
4. ✅ Fix timezone consistency

### Priority 2: High (Fix Within 7 Days)
5. ✅ Create dashboard summary table
6. ✅ Implement export chunking
7. ✅ Add missing database indexes
8. ✅ Replace DB::table() with Eloquent

### Priority 3: Medium (Fix Within 30 Days)
9. ⚠️ Implement cache stampede protection
10. ⚠️ Add health check endpoints
11. ⚠️ Configure alerting
12. ⚠️ Optimize N+1 queries

## 📝 Test Report Template

After running tests, generate report:

```markdown
# Load Test Report - [Date]

## Test Configuration
- Duration: 60 seconds
- Peak VUs: 5000
- Total Requests: [X]
- Test Tool: k6/Artillery

## Results Summary
- Error Rate: [X]%
- P95 Response Time: [X]ms
- P99 Response Time: [X]ms
- Duplicate Attendance: [X]
- Deadlock Errors: [X]

## System Metrics
- CPU Usage: Avg [X]%, Max [X]%
- Memory Usage: Avg [X]%, Max [X]%
- Redis Memory: Avg [X]MB, Max [X]MB

## Bottlenecks Identified
1. [Component]: [Issue]
2. [Component]: [Issue]

## Recommendations
1. [Recommendation]
2. [Recommendation]

## Verdict
✅ PASS / ❌ FAIL

## Next Steps
- [ ] Fix critical issues
- [ ] Re-run load test
- [ ] Deploy to staging
```

## 🆘 Troubleshooting

### Test Fails to Start
```bash
# Check if services are running
docker ps
systemctl status mysql
systemctl status redis

# Check if ports are available
netstat -tulpn | grep -E "8000|3306|6379"
```

### High Error Rate
```bash
# Check application logs
tail -f backend/storage/logs/laravel.log

# Check for specific errors
grep -E "ERROR|CRITICAL" backend/storage/logs/laravel.log | tail -50

# Check database connections
mysql -u root -ppassword -e "SHOW PROCESSLIST;"
```

### Memory Leak Detected
```bash
# Monitor memory over time
watch -n 5 'free -h && echo "---" && ps aux --sort=-%mem | head -10'

# Check for memory leaks in PHP
php artisan queue:work --memory=128 --tries=3

# Restart workers periodically
php artisan queue:restart
```

## 📚 References

- [k6 Documentation](https://k6.io/docs/)
- [Artillery Documentation](https://www.artillery.io/docs)
- [Chaos Engineering Principles](https://principlesofchaos.org/)
- [Laravel Performance Best Practices](https://laravel.com/docs/performance)

## 🤝 Contributing

To add new test scenarios:

1. Add scenario to `k6-attendance-load-test.js` or `artillery-chaos-test.yml`
2. Update acceptance criteria in `analyze-results.py`
3. Document expected behavior in this README
4. Run test and verify results

## 📞 Support

For issues or questions:
- Create issue in repository
- Contact: devops@absensi.com
- Slack: #load-testing

---

**Last Updated**: 9 Februari 2026  
**Maintained by**: Performance Engineering Team
