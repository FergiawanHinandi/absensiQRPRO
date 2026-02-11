#!/bin/bash

# Chaos Engineering - Redis Crash Experiment
# This script simulates Redis failure and monitors system behavior

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$SCRIPT_DIR/logs/redis-crash-$(date +%Y%m%d-%H%M%S).log"
METRICS_FILE="$SCRIPT_DIR/logs/redis-crash-metrics-$(date +%Y%m%d-%H%M%S).csv"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
EXPERIMENT_DURATION=300  # 5 minutes
REDIS_CONTAINER="redis"
API_URL="${API_URL:-http://localhost/api}"

echo -e "${GREEN}╔════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   CHAOS EXPERIMENT: Redis Crash        ║${NC}"
echo -e "${GREEN}╔════════════════════════════════════════╗${NC}"
echo ""

# Create logs directory
mkdir -p "$SCRIPT_DIR/logs"

# Function to log
log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Function to check health
check_health() {
    curl -s "$API_URL/health" | jq -r '.services.redis.status' 2>/dev/null || echo "unknown"
}

# Function to check circuit breaker
check_circuit() {
    curl -s "$API_URL/health/circuit-breaker" | jq -r '.redis_circuit_breaker.state' 2>/dev/null || echo "unknown"
}

# Function to record metrics
record_metrics() {
    local timestamp=$(date +%s)
    local redis_status=$(check_health)
    local circuit_state=$(check_circuit)
    local response_time=$(curl -s -o /dev/null -w "%{time_total}" "$API_URL/health")
    
    echo "$timestamp,$redis_status,$circuit_state,$response_time" >> "$METRICS_FILE"
}

# Initialize metrics file
echo "timestamp,redis_status,circuit_state,response_time" > "$METRICS_FILE"

log "Starting Redis Crash experiment..."
log "Duration: ${EXPERIMENT_DURATION}s"
log "Redis container: $REDIS_CONTAINER"

# Phase 1: Baseline
echo -e "\n${YELLOW}Phase 1: Recording baseline (30s)${NC}"
log "Phase 1: Recording baseline"

for i in {1..30}; do
    record_metrics
    sleep 1
done

# Phase 2: Inject failure
echo -e "\n${RED}Phase 2: Stopping Redis${NC}"
log "Phase 2: Injecting failure - stopping Redis"

docker stop "$REDIS_CONTAINER" 2>&1 | tee -a "$LOG_FILE"

if [ $? -eq 0 ]; then
    log "✓ Redis stopped successfully"
else
    log "✗ Failed to stop Redis"
    exit 1
fi

# Phase 3: Observe failure
echo -e "\n${YELLOW}Phase 3: Observing system behavior (120s)${NC}"
log "Phase 3: Observing system under failure"

for i in {1..120}; do
    record_metrics
    
    # Check circuit breaker state every 10 seconds
    if [ $((i % 10)) -eq 0 ]; then
        circuit_state=$(check_circuit)
        log "Circuit breaker state: $circuit_state"
    fi
    
    sleep 1
done

# Phase 4: Recovery
echo -e "\n${GREEN}Phase 4: Starting Redis${NC}"
log "Phase 4: Recovering - starting Redis"

docker start "$REDIS_CONTAINER" 2>&1 | tee -a "$LOG_FILE"

if [ $? -eq 0 ]; then
    log "✓ Redis started successfully"
else
    log "✗ Failed to start Redis"
    exit 1
fi

# Phase 5: Observe recovery
echo -e "\n${YELLOW}Phase 5: Observing recovery (120s)${NC}"
log "Phase 5: Observing system recovery"

for i in {1..120}; do
    record_metrics
    
    # Check circuit breaker state every 10 seconds
    if [ $((i % 10)) -eq 0 ]; then
        circuit_state=$(check_circuit)
        log "Circuit breaker state: $circuit_state"
    fi
    
    sleep 1
done

# Phase 6: Validation
echo -e "\n${GREEN}Phase 6: Validating results${NC}"
log "Phase 6: Validating experiment results"

# Check for duplicates
echo "Checking for duplicate attendance records..."
php artisan tinker --execute="
    \$duplicates = \App\Models\Attendance::select('student_id', 'attendance_date')
        ->whereDate('attendance_date', today())
        ->groupBy('student_id', 'attendance_date')
        ->havingRaw('COUNT(*) > 1')
        ->count();
    echo \"Duplicates found: \$duplicates\n\";
" | tee -a "$LOG_FILE"

# Check data integrity
echo "Checking data integrity..."
php artisan tinker --execute="
    \$writeCount = \App\Models\Attendance::whereDate('attendance_date', today())->count();
    \$readCount = \App\ReadModels\AttendanceDailySummary::getTodaySummary(1)->total_students ?? 0;
    echo \"Write model: \$writeCount\n\";
    echo \"Read model: \$readCount\n\";
    echo \"Difference: \" . abs(\$writeCount - \$readCount) . \"\n\";
" | tee -a "$LOG_FILE"

# Generate report
echo -e "\n${GREEN}╔════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║         EXPERIMENT COMPLETE            ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════╝${NC}"
echo ""
echo "Log file: $LOG_FILE"
echo "Metrics file: $METRICS_FILE"
echo ""
echo "To analyze results:"
echo "  cat $LOG_FILE"
echo "  python3 $SCRIPT_DIR/analyze-metrics.py $METRICS_FILE"
echo ""

log "Experiment completed successfully"
