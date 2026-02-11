# 🔄 Rollback Decision Tree

**DevOps Lead**  
**Date**: 2026-02-10  
**Purpose**: Automated decision-making for production rollbacks

---

## 🎯 Decision Tree Overview

```
                        ┌─────────────────────┐
                        │  Deployment Active  │
                        └──────────┬──────────┘
                                   │
                                   ▼
                        ┌─────────────────────┐
                        │  Monitor Metrics    │
                        │  (Every 30 sec)     │
                        └──────────┬──────────┘
                                   │
                    ┌──────────────┴──────────────┐
                    │                             │
                    ▼                             ▼
         ┌──────────────────┐          ┌──────────────────┐
         │  Critical Alert  │          │  Warning Alert   │
         │  (Auto Rollback) │          │  (Manual Review) │
         └──────────────────┘          └──────────────────┘
```

---

## 🚨 Critical Alerts (Automatic Rollback)

### 1. Error Rate >5%

**Trigger**:
```bash
ERROR_RATE=$(curl -s https://absensiqr.com/api/metrics/error-rate | jq -r '.rate')
if (( $(echo "$ERROR_RATE > 5" | bc -l) )); then
  ROLLBACK=true
fi
```

**Decision**: ✅ **AUTOMATIC ROLLBACK**

**Reason**: High error rate indicates critical failure

**Action**:
1. Trigger immediate rollback
2. Alert DevOps team
3. Capture error logs
4. Switch to previous environment

**Time to Rollback**: <2 minutes

---

### 2. Deadlock Spike

**Trigger**:
```bash
DEADLOCK_COUNT=$(mysql -e "SHOW ENGINE INNODB STATUS" | grep -c "LATEST DETECTED DEADLOCK")
if [ $DEADLOCK_COUNT -gt 5 ]; then
  ROLLBACK=true
fi
```

**Decision**: ✅ **AUTOMATIC ROLLBACK**

**Reason**: Deadlocks indicate race condition or locking issue

**Action**:
1. Trigger immediate rollback
2. Alert database team
3. Capture slow query log
4. Review recent code changes

**Time to Rollback**: <2 minutes

---

### 3. Redis Connection Timeout >2 min

**Trigger**:
```bash
REDIS_STATUS=$(curl -s https://absensiqr.com/api/health/redis | jq -r '.status')
REDIS_TIMEOUT=$(curl -s https://absensiqr.com/api/health/redis | jq -r '.timeout_duration')

if [ "$REDIS_STATUS" != "ok" ] && [ $REDIS_TIMEOUT -gt 120 ]; then
  ROLLBACK=true
fi
```

**Decision**: ✅ **AUTOMATIC ROLLBACK**

**Reason**: Redis failure affects sessions and cache

**Action**:
1. Trigger immediate rollback
2. Check Redis server health
3. Review Redis connection pool
4. Verify Redis configuration

**Time to Rollback**: <2 minutes

---

### 4. Database Connection Failures

**Trigger**:
```bash
DB_FAILURES=$(curl -s https://absensiqr.com/api/metrics/db-failures | jq -r '.count')
if [ $DB_FAILURES -gt 10 ]; then
  ROLLBACK=true
fi
```

**Decision**: ✅ **AUTOMATIC ROLLBACK**

**Reason**: Database unavailable = system down

**Action**:
1. Trigger immediate rollback
2. Check database server
3. Verify connection pool
4. Review migration logs

**Time to Rollback**: <2 minutes

---

### 5. Critical Feature Broken

**Trigger**:
```bash
# Test critical attendance scan
ATTENDANCE_TEST=$(curl -s https://absensiqr.com/api/health/attendance-test | jq -r '.status')
if [ "$ATTENDANCE_TEST" != "ok" ]; then
  ROLLBACK=true
fi
```

**Decision**: ✅ **AUTOMATIC ROLLBACK**

**Reason**: Core feature failure unacceptable

**Action**:
1. Trigger immediate rollback
2. Alert product team
3. Review feature changes
4. Test in staging

**Time to Rollback**: <2 minutes

---

## ⚠️ Warning Alerts (Manual Review)

### 1. Response Time >1000ms

**Trigger**:
```bash
RESPONSE_TIME=$(curl -s https://absensiqr.com/api/metrics/response-time | jq -r '.avg')
if (( $(echo "$RESPONSE_TIME > 1000" | bc -l) )); then
  ALERT=true
fi
```

**Decision**: ⚠️ **MANUAL REVIEW REQUIRED**

**Investigation Steps**:
1. Check slow query log
2. Review recent code changes
3. Check server resources (CPU, RAM)
4. Review cache hit rate

**Rollback if**:
- Response time >2000ms for >5 minutes
- CPU usage >90%
- Memory usage >95%

**Time to Decide**: 5 minutes

---

### 2. Queue Depth >5000

**Trigger**:
```bash
QUEUE_DEPTH=$(curl -s https://absensiqr.com/api/metrics/queue-depth | jq -r '.depth')
if [ $QUEUE_DEPTH -gt 5000 ]; then
  ALERT=true
fi
```

**Decision**: ⚠️ **MANUAL REVIEW REQUIRED**

**Investigation Steps**:
1. Check queue workers status
2. Review job failure rate
3. Check for stuck jobs
4. Verify queue configuration

**Rollback if**:
- Queue depth >10000
- Jobs failing >50%
- Workers not processing

**Alternative Action**:
- Scale up queue workers
- Restart queue workers
- Clear failed jobs

**Time to Decide**: 10 minutes

---

### 3. Memory Usage >90%

**Trigger**:
```bash
MEMORY_USAGE=$(free | grep Mem | awk '{print ($3/$2) * 100.0}')
if (( $(echo "$MEMORY_USAGE > 90" | bc -l) )); then
  ALERT=true
fi
```

**Decision**: ⚠️ **MANUAL REVIEW REQUIRED**

**Investigation Steps**:
1. Check for memory leaks
2. Review recent code changes
3. Check cache size
4. Review session storage

**Rollback if**:
- Memory usage >95% for >5 minutes
- OOM errors detected
- Server unresponsive

**Alternative Action**:
- Clear caches
- Restart PHP-FPM
- Scale up server

**Time to Decide**: 5 minutes

---

### 4. Customer Complaints Spike

**Trigger**:
```bash
# Monitor support tickets or social media
COMPLAINTS=$(curl -s https://api.support.com/tickets/count | jq -r '.count')
if [ $COMPLAINTS -gt 10 ]; then
  ALERT=true
fi
```

**Decision**: ⚠️ **MANUAL REVIEW REQUIRED**

**Investigation Steps**:
1. Review complaint details
2. Check affected features
3. Verify error logs
4. Test user workflows

**Rollback if**:
- Multiple critical features affected
- Data integrity issues reported
- Security concerns raised

**Time to Decide**: 15 minutes

---

## 🤖 Automated Rollback Script

```bash
#!/bin/bash

# rollback.sh - Automated rollback script

set -e

# Configuration
PROD_DIR="/var/www/production"
SLACK_WEBHOOK="$SLACK_WEBHOOK_URL"

# Function to send alert
send_alert() {
  local message=$1
  curl -X POST -H 'Content-type: application/json' \
    --data "{\"text\":\"$message\"}" \
    $SLACK_WEBHOOK
}

# Function to check metrics
check_metrics() {
  echo "🔍 Checking metrics..."
  
  # Error rate
  ERROR_RATE=$(curl -s https://absensiqr.com/api/metrics/error-rate | jq -r '.rate')
  echo "Error rate: $ERROR_RATE%"
  
  if (( $(echo "$ERROR_RATE > 5" | bc -l) )); then
    echo "❌ Error rate exceeded threshold!"
    return 1
  fi
  
  # Deadlocks
  DEADLOCK_COUNT=$(mysql -e "SHOW ENGINE INNODB STATUS" | grep -c "LATEST DETECTED DEADLOCK" || echo 0)
  echo "Deadlock count: $DEADLOCK_COUNT"
  
  if [ $DEADLOCK_COUNT -gt 5 ]; then
    echo "❌ Deadlock spike detected!"
    return 1
  fi
  
  # Redis health
  REDIS_STATUS=$(curl -s https://absensiqr.com/api/health/redis | jq -r '.status')
  echo "Redis status: $REDIS_STATUS"
  
  if [ "$REDIS_STATUS" != "ok" ]; then
    REDIS_TIMEOUT=$(curl -s https://absensiqr.com/api/health/redis | jq -r '.timeout_duration')
    if [ $REDIS_TIMEOUT -gt 120 ]; then
      echo "❌ Redis timeout exceeded!"
      return 1
    fi
  fi
  
  # Database health
  DB_FAILURES=$(curl -s https://absensiqr.com/api/metrics/db-failures | jq -r '.count')
  echo "DB failures: $DB_FAILURES"
  
  if [ $DB_FAILURES -gt 10 ]; then
    echo "❌ Database failures exceeded threshold!"
    return 1
  fi
  
  # Attendance test
  ATTENDANCE_TEST=$(curl -s https://absensiqr.com/api/health/attendance-test | jq -r '.status')
  echo "Attendance test: $ATTENDANCE_TEST"
  
  if [ "$ATTENDANCE_TEST" != "ok" ]; then
    echo "❌ Attendance test failed!"
    return 1
  fi
  
  echo "✅ All metrics healthy"
  return 0
}

# Function to perform rollback
perform_rollback() {
  echo "🔄 Starting rollback..."
  send_alert "⚠️ ROLLBACK INITIATED - Critical metrics exceeded"
  
  cd $PROD_DIR
  
  # Determine current and previous environments
  CURRENT=$(readlink current | grep -o 'blue\|green')
  PREVIOUS=$([ "$CURRENT" = "blue" ] && echo "green" || echo "blue")
  
  echo "Rolling back from $CURRENT to $PREVIOUS..."
  
  # Switch symlink
  ln -sfn $PREVIOUS current
  
  # Reload services
  echo "♻️ Reloading services..."
  sudo systemctl reload nginx
  sudo systemctl reload php8.2-fpm
  
  # Clear caches
  echo "🧹 Clearing caches..."
  cd $PREVIOUS
  php artisan cache:clear
  php artisan config:cache
  php artisan route:cache
  
  # Restart queue workers
  echo "🔄 Restarting queue workers..."
  php artisan queue:restart
  sudo supervisorctl restart laravel-worker:*
  
  # Verify rollback
  echo "✅ Verifying rollback..."
  sleep 5
  
  if curl -f https://absensiqr.com/api/health; then
    echo "✅ Rollback successful!"
    send_alert "✅ ROLLBACK COMPLETE - System restored to $PREVIOUS"
    return 0
  else
    echo "❌ Rollback verification failed!"
    send_alert "❌ ROLLBACK FAILED - Manual intervention required!"
    return 1
  fi
}

# Main monitoring loop
main() {
  echo "🚀 Starting post-deployment monitoring..."
  send_alert "🚀 Deployment monitoring started"
  
  # Monitor for 30 minutes (60 checks at 30-second intervals)
  for i in {1..60}; do
    echo "Check $i/60..."
    
    if ! check_metrics; then
      echo "⚠️ Critical metrics detected - initiating rollback..."
      perform_rollback
      exit $?
    fi
    
    echo "✅ Check $i passed"
    sleep 30
  done
  
  echo "🎉 Monitoring complete - deployment stable!"
  send_alert "🎉 Deployment monitoring complete - all metrics healthy"
}

# Run main function
main
```

---

## 📊 Decision Matrix

| Metric | Threshold | Action | Auto/Manual | Time |
|--------|-----------|--------|-------------|------|
| Error Rate | >5% | Rollback | Auto | <2 min |
| Deadlocks | >5 in 5 min | Rollback | Auto | <2 min |
| Redis Timeout | >2 min | Rollback | Auto | <2 min |
| DB Failures | >10 in 5 min | Rollback | Auto | <2 min |
| Critical Feature | Failed | Rollback | Auto | <2 min |
| Response Time | >1000ms | Investigate | Manual | 5 min |
| Queue Depth | >5000 | Investigate | Manual | 10 min |
| Memory Usage | >90% | Investigate | Manual | 5 min |
| CPU Usage | >90% | Investigate | Manual | 5 min |

---

## 🔔 Alert Channels

### Critical Alerts (Automatic Rollback)
- 📱 SMS to DevOps Lead
- 📱 SMS to Backend Lead
- 💬 Slack #critical-alerts
- 📧 Email to engineering@
- 📞 PagerDuty escalation

### Warning Alerts (Manual Review)
- 💬 Slack #deployments
- 📧 Email to devops@
- 📊 Dashboard notification

---

## 📝 Post-Rollback Checklist

After rollback is complete:

- [ ] Verify system stability
- [ ] Document rollback reason
- [ ] Create incident report
- [ ] Schedule post-mortem
- [ ] Fix root cause
- [ ] Test fix in staging
- [ ] Plan re-deployment

---

**Status**: ✅ Ready for Production  
**Last Updated**: 2026-02-10  
**Version**: 1.0
