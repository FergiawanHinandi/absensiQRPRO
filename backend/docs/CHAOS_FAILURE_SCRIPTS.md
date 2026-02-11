# 🔥 Chaos Engineering - Failure Injection Scripts

**Site Reliability Engineer**  
**Date**: 2026-02-10

---

## 🎯 Failure Injection Overview

These scripts simulate real-world failures to test system resilience.

---

## 💥 Experiment 1: Redis Crash

### Script: `chaos-redis-crash.sh`

```bash
#!/bin/bash

echo "🔥 Chaos Experiment: Redis Crash"
echo "================================="

# Configuration
REDIS_CONTAINER="redis"
DURATION=120  # 2 minutes
APP_URL="https://staging.absensiqr.com"

# Pre-check
echo "📊 Pre-Failure Metrics:"
curl -s $APP_URL/api/health/redis | jq
echo ""

# Stop Redis
echo "💥 Stopping Redis..."
docker stop $REDIS_CONTAINER
echo "✅ Redis stopped"
echo ""

# Monitor system behavior
echo "📊 Monitoring system for $DURATION seconds..."
START_TIME=$(date +%s)

while [ $(($(date +%s) - START_TIME)) -lt $DURATION ]; do
  ELAPSED=$(($(date +%s) - START_TIME))
  echo "⏱️  Time: ${ELAPSED}s / ${DURATION}s"
  
  # Check health
  HEALTH=$(curl -s $APP_URL/api/health | jq -r '.status')
  echo "  Health: $HEALTH"
  
  # Check error rate
  ERROR_RATE=$(curl -s $APP_URL/api/metrics/error-rate | jq -r '.rate')
  echo "  Error Rate: ${ERROR_RATE}%"
  
  # Check if fallback is working
  CACHE_STATUS=$(curl -s $APP_URL/api/health/redis | jq -r '.status')
  echo "  Cache Status: $CACHE_STATUS"
  
  # Alert if error rate > 5%
  if (( $(echo "$ERROR_RATE > 5" | bc -l) )); then
    echo "  ⚠️  ERROR RATE EXCEEDED 5%!"
  fi
  
  echo ""
  sleep 10
done

# Restart Redis
echo "♻️  Restarting Redis..."
docker start $REDIS_CONTAINER
echo "✅ Redis restarted"
echo ""

# Wait for Redis to be ready
echo "⏳ Waiting for Redis to be ready..."
sleep 5

# Verify recovery
echo "📊 Post-Recovery Metrics:"
curl -s $APP_URL/api/health/redis | jq
echo ""

# Check if auto-reconnect worked
REDIS_STATUS=$(curl -s $APP_URL/api/health/redis | jq -r '.status')
if [ "$REDIS_STATUS" = "ok" ]; then
  echo "✅ EXPERIMENT PASSED: Redis auto-reconnected"
else
  echo "❌ EXPERIMENT FAILED: Redis did not auto-reconnect"
  exit 1
fi

echo ""
echo "🎉 Experiment Complete!"
```

**Run**:
```bash
chmod +x chaos-redis-crash.sh
./chaos-redis-crash.sh
```

---

## 💥 Experiment 2: Database Latency Injection

### Script: `chaos-db-latency.sh`

```bash
#!/bin/bash

echo "🔥 Chaos Experiment: Database Latency Injection"
echo "================================================"

# Configuration
DB_CONTAINER="mysql"
LATENCY="500ms"
DURATION=300  # 5 minutes
APP_URL="https://staging.absensiqr.com"

# Pre-check
echo "📊 Pre-Failure Metrics:"
curl -s $APP_URL/api/health/database | jq
echo ""

# Inject latency
echo "💥 Injecting ${LATENCY} latency to database..."
docker exec $DB_CONTAINER tc qdisc add dev eth0 root netem delay $LATENCY
echo "✅ Latency injected"
echo ""

# Monitor system behavior
echo "📊 Monitoring system for $DURATION seconds..."
START_TIME=$(date +%s)

while [ $(($(date +%s) - START_TIME)) -lt $DURATION ]; do
  ELAPSED=$(($(date +%s) - START_TIME))
  echo "⏱️  Time: ${ELAPSED}s / ${DURATION}s"
  
  # Check database response time
  DB_RESPONSE=$(curl -s $APP_URL/api/health/database | jq -r '.response_time_ms')
  echo "  DB Response Time: ${DB_RESPONSE}ms"
  
  # Check queue depth
  QUEUE_DEPTH=$(curl -s $APP_URL/api/metrics/queue-depth | jq -r '.depth')
  echo "  Queue Depth: $QUEUE_DEPTH"
  
  # Check error rate
  ERROR_RATE=$(curl -s $APP_URL/api/metrics/error-rate | jq -r '.rate')
  echo "  Error Rate: ${ERROR_RATE}%"
  
  # Alert if error rate > 5%
  if (( $(echo "$ERROR_RATE > 5" | bc -l) )); then
    echo "  ⚠️  ERROR RATE EXCEEDED 5%!"
  fi
  
  # Alert if queue depth > 5000
  if [ $QUEUE_DEPTH -gt 5000 ]; then
    echo "  ⚠️  QUEUE DEPTH EXCEEDED 5000!"
  fi
  
  echo ""
  sleep 30
done

# Remove latency
echo "♻️  Removing latency..."
docker exec $DB_CONTAINER tc qdisc del dev eth0 root
echo "✅ Latency removed"
echo ""

# Wait for system to stabilize
echo "⏳ Waiting for system to stabilize..."
sleep 30

# Verify recovery
echo "📊 Post-Recovery Metrics:"
curl -s $APP_URL/api/health/database | jq
echo ""

# Check if system recovered
DB_RESPONSE=$(curl -s $APP_URL/api/health/database | jq -r '.response_time_ms')
if (( $(echo "$DB_RESPONSE < 100" | bc -l) )); then
  echo "✅ EXPERIMENT PASSED: Database response time normalized"
else
  echo "⚠️  WARNING: Database response time still high: ${DB_RESPONSE}ms"
fi

echo ""
echo "🎉 Experiment Complete!"
```

**Run**:
```bash
chmod +x chaos-db-latency.sh
./chaos-db-latency.sh
```

---

## 💥 Experiment 3: Queue Kill

### Script: `chaos-queue-kill.sh`

```bash
#!/bin/bash

echo "🔥 Chaos Experiment: Queue Kill"
echo "================================"

# Configuration
DURATION=180  # 3 minutes
APP_URL="https://staging.absensiqr.com"

# Pre-check
echo "📊 Pre-Failure Metrics:"
curl -s $APP_URL/api/health/queue | jq
echo ""

# Get initial queue depth
INITIAL_DEPTH=$(curl -s $APP_URL/api/metrics/queue-depth | jq -r '.depth')
echo "Initial Queue Depth: $INITIAL_DEPTH"
echo ""

# Kill queue workers
echo "💥 Stopping all queue workers..."
sudo supervisorctl stop laravel-worker:*
echo "✅ Queue workers stopped"
echo ""

# Generate some load to queue jobs
echo "📊 Generating load (jobs will queue)..."
for i in {1..100}; do
  curl -s -X POST $APP_URL/api/attendance/check-in \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer test-token" \
    -d "{\"student_id\": $i, \"schedule_id\": 1}" > /dev/null &
done
wait
echo "✅ Load generated"
echo ""

# Monitor queue depth
echo "📊 Monitoring queue for $DURATION seconds..."
START_TIME=$(date +%s)

while [ $(($(date +%s) - START_TIME)) -lt $DURATION ]; do
  ELAPSED=$(($(date +%s) - START_TIME))
  echo "⏱️  Time: ${ELAPSED}s / ${DURATION}s"
  
  # Check queue depth
  QUEUE_DEPTH=$(curl -s $APP_URL/api/metrics/queue-depth | jq -r '.depth')
  echo "  Queue Depth: $QUEUE_DEPTH"
  
  # Check queue health
  QUEUE_STATUS=$(curl -s $APP_URL/api/health/queue | jq -r '.status')
  echo "  Queue Status: $QUEUE_STATUS"
  
  echo ""
  sleep 30
done

# Restart queue workers
echo "♻️  Restarting queue workers..."
sudo supervisorctl start laravel-worker:*
echo "✅ Queue workers restarted"
echo ""

# Wait for queue to process
echo "⏳ Waiting for queue to process backlog..."
sleep 60

# Verify recovery
echo "📊 Post-Recovery Metrics:"
curl -s $APP_URL/api/health/queue | jq
echo ""

# Check if queue processed jobs
FINAL_DEPTH=$(curl -s $APP_URL/api/metrics/queue-depth | jq -r '.depth')
echo "Final Queue Depth: $FINAL_DEPTH"

if [ $FINAL_DEPTH -lt $QUEUE_DEPTH ]; then
  echo "✅ EXPERIMENT PASSED: Queue processing backlog"
else
  echo "❌ EXPERIMENT FAILED: Queue not processing"
  exit 1
fi

echo ""
echo "🎉 Experiment Complete!"
```

**Run**:
```bash
chmod +x chaos-queue-kill.sh
./chaos-queue-kill.sh
```

---

## 💥 Experiment 4: Disk Almost Full

### Script: `chaos-disk-full.sh`

```bash
#!/bin/bash

echo "🔥 Chaos Experiment: Disk Almost Full"
echo "======================================"

# Configuration
FILL_SIZE="10G"  # Fill 10GB
DURATION=600     # 10 minutes
APP_URL="https://staging.absensiqr.com"

# Pre-check
echo "📊 Pre-Failure Disk Usage:"
df -h /
echo ""

# Fill disk to 95%
echo "💥 Filling disk with ${FILL_SIZE}..."
dd if=/dev/zero of=/tmp/chaos-fillfile bs=1M count=10000 2>/dev/null
echo "✅ Disk filled"
echo ""

# Check disk usage
echo "📊 Current Disk Usage:"
df -h /
echo ""

# Monitor system behavior
echo "📊 Monitoring system for $DURATION seconds..."
START_TIME=$(date +%s)

while [ $(($(date +%s) - START_TIME)) -lt $DURATION ]; do
  ELAPSED=$(($(date +%s) - START_TIME))
  echo "⏱️  Time: ${ELAPSED}s / ${DURATION}s"
  
  # Check disk usage
  DISK_USAGE=$(df / | tail -1 | awk '{print $5}' | sed 's/%//')
  echo "  Disk Usage: ${DISK_USAGE}%"
  
  # Check application health
  HEALTH=$(curl -s $APP_URL/api/health | jq -r '.status')
  echo "  App Health: $HEALTH"
  
  # Check error rate
  ERROR_RATE=$(curl -s $APP_URL/api/metrics/error-rate | jq -r '.rate')
  echo "  Error Rate: ${ERROR_RATE}%"
  
  # Alert if disk > 95%
  if [ $DISK_USAGE -gt 95 ]; then
    echo "  ⚠️  DISK USAGE CRITICAL!"
  fi
  
  echo ""
  sleep 60
done

# Clean up
echo "♻️  Cleaning up..."
rm -f /tmp/chaos-fillfile
echo "✅ Disk space freed"
echo ""

# Verify recovery
echo "📊 Post-Recovery Disk Usage:"
df -h /
echo ""

# Check if system recovered
HEALTH=$(curl -s $APP_URL/api/health | jq -r '.status')
if [ "$HEALTH" = "healthy" ]; then
  echo "✅ EXPERIMENT PASSED: System recovered"
else
  echo "❌ EXPERIMENT FAILED: System not healthy"
  exit 1
fi

echo ""
echo "🎉 Experiment Complete!"
```

**Run**:
```bash
chmod +x chaos-disk-full.sh
./chaos-disk-full.sh
```

---

## 💥 Experiment 5: Network Partition

### Script: `chaos-network-partition.sh`

```bash
#!/bin/bash

echo "🔥 Chaos Experiment: Network Partition"
echo "======================================="

# Configuration
DURATION=120  # 2 minutes
APP_CONTAINER="laravel-app"
DB_CONTAINER="mysql"

# Pre-check
echo "📊 Pre-Failure Network:"
docker exec $APP_CONTAINER ping -c 3 $DB_CONTAINER
echo ""

# Create network partition
echo "💥 Creating network partition..."
docker network disconnect bridge $APP_CONTAINER
echo "✅ Network partition created"
echo ""

# Monitor system behavior
echo "📊 Monitoring system for $DURATION seconds..."
sleep $DURATION

# Restore network
echo "♻️  Restoring network..."
docker network connect bridge $APP_CONTAINER
echo "✅ Network restored"
echo ""

# Wait for reconnection
echo "⏳ Waiting for reconnection..."
sleep 10

# Verify recovery
echo "📊 Post-Recovery Network:"
docker exec $APP_CONTAINER ping -c 3 $DB_CONTAINER
echo ""

echo "🎉 Experiment Complete!"
```

**Run**:
```bash
chmod +x chaos-network-partition.sh
./chaos-network-partition.sh
```

---

## 🎯 Run All Failure Experiments

### Script: `run-all-failure-experiments.sh`

```bash
#!/bin/bash

echo "🔥 Starting All Failure Injection Experiments"
echo "=============================================="

# Experiment 1: Redis Crash
echo ""
echo "📊 Experiment 1: Redis Crash"
./chaos-redis-crash.sh
if [ $? -ne 0 ]; then
  echo "❌ Experiment 1 FAILED"
  exit 1
fi

# Wait 5 minutes
echo "⏳ Waiting 5 minutes before next experiment..."
sleep 300

# Experiment 2: Database Latency
echo ""
echo "📊 Experiment 2: Database Latency"
./chaos-db-latency.sh
if [ $? -ne 0 ]; then
  echo "❌ Experiment 2 FAILED"
  exit 1
fi

# Wait 5 minutes
echo "⏳ Waiting 5 minutes before next experiment..."
sleep 300

# Experiment 3: Queue Kill
echo ""
echo "📊 Experiment 3: Queue Kill"
./chaos-queue-kill.sh
if [ $? -ne 0 ]; then
  echo "❌ Experiment 3 FAILED"
  exit 1
fi

# Wait 5 minutes
echo "⏳ Waiting 5 minutes before next experiment..."
sleep 300

# Experiment 4: Disk Full
echo ""
echo "📊 Experiment 4: Disk Full"
./chaos-disk-full.sh
if [ $? -ne 0 ]; then
  echo "❌ Experiment 4 FAILED"
  exit 1
fi

echo ""
echo "🎉 All Failure Injection Experiments Completed!"
echo "==============================================="
```

**Run**:
```bash
chmod +x run-all-failure-experiments.sh
./run-all-failure-experiments.sh
```

---

**Status**: ✅ Ready for Execution  
**Last Updated**: 2026-02-10  
**Version**: 1.0
