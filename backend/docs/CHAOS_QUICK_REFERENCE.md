# Chaos Engineering Quick Reference

## 🚀 Quick Commands

### Run Experiments

```bash
# 1. Redis Crash (5 minutes)
./chaos/redis-crash.sh

# 2. Load Test (1 minute)
k6 run --vus 1000 --duration 60s chaos/concurrent-scans.js

# 3. Webhook Duplicate (30 seconds)
k6 run chaos/webhook-duplicate.js
```

### Check System Health

```bash
# Overall health
curl http://localhost/api/health | jq

# Circuit breaker status
curl http://localhost/api/health/circuit-breaker | jq

# Check for duplicates
php artisan tinker --execute="
  echo \App\Models\Attendance::select('student_id', 'attendance_date')
    ->groupBy('student_id', 'attendance_date')
    ->havingRaw('COUNT(*) > 1')
    ->count();
"
```

### Analyze Results

```bash
# Analyze metrics
python3 chaos/analyze-metrics.py chaos/logs/redis-crash-metrics-*.csv

# View logs
cat chaos/logs/redis-crash-*.log

# Tail live logs
tail -f storage/logs/laravel.log | grep -i circuit
```

## 📊 Success Criteria Cheat Sheet

| Metric | Target | Critical |
|--------|--------|----------|
| Error Rate | <1% | >5% |
| p95 Response | <500ms | >2s |
| Duplicates | 0 | >0 |
| Data Loss | 0 | >0 |
| Recovery Time | <30s | >5min |

## 🎯 Experiment Checklist

### Before Running

```markdown
□ Team notified
□ Low traffic period
□ Monitoring ready
□ Rollback plan ready
```

### During Experiment

```markdown
□ Baseline recorded
□ Failure injected
□ Metrics monitored
□ Anomalies documented
```

### After Experiment

```markdown
□ Failure removed
□ System recovered
□ Data validated
□ Report written
```

## 🔧 Emergency Rollback

```bash
# If experiment goes wrong:

# 1. Stop experiment
Ctrl+C

# 2. Restore services
docker start redis postgres
php artisan queue:restart

# 3. Verify health
curl http://localhost/api/health

# 4. Check data
php artisan tinker --execute="
  echo 'Attendances: ' . \App\Models\Attendance::count();
"
```

## 📈 Resilience Score

```
95-100: A+  Excellent
90-94:  A   Very Good
85-89:  A-  Good
80-84:  B+  Acceptable
<80:    F   Needs Work
```

## 🎓 Common Issues

### Experiment Won't Start

```bash
# Check Docker
docker ps

# Check API
curl http://localhost/api/health

# Fix permissions
chmod +x chaos/*.sh
```

### No Metrics Collected

```bash
# Create logs directory
mkdir -p chaos/logs

# Check API endpoints
curl http://localhost/api/health/circuit-breaker
```

### High Error Rate

**Expected during chaos!** Check:
- Circuit breaker working?
- Fallback activated?
- No data corruption?

## 📞 Quick Links

- **Full Plan**: `docs/CHAOS_ENGINEERING_PLAN.md`
- **Summary**: `docs/CHAOS_ENGINEERING_SUMMARY.md`
- **Scripts**: `chaos/`
- **Health**: http://localhost/api/health
