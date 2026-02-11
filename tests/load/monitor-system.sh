#!/bin/bash

###############################################################################
# System Monitoring Script for Load Test
# 
# Monitors:
# - CPU usage
# - Memory usage
# - Redis memory
# - MySQL connections
# - DB lock waits
# - Response times
# 
# Usage:
# ./monitor-system.sh > monitor-output.log &
# MONITOR_PID=$!
# # Run load test
# kill $MONITOR_PID
###############################################################################

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
INTERVAL=5  # Monitor every 5 seconds
LOG_FILE="system-metrics-$(date +%Y%m%d_%H%M%S).log"

echo "🔍 Starting system monitoring..."
echo "📝 Logging to: $LOG_FILE"
echo "⏱️  Interval: ${INTERVAL}s"
echo ""

# Header
echo "timestamp,cpu_percent,mem_percent,redis_mem_mb,mysql_connections,db_lock_waits,redis_ops_per_sec" | tee -a "$LOG_FILE"

# Monitor loop
while true; do
    TIMESTAMP=$(date +"%Y-%m-%d %H:%M:%S")
    
    # =========================================================================
    # CPU Usage
    # =========================================================================
    CPU_PERCENT=$(top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\1/" | awk '{print 100 - $1}')
    
    # =========================================================================
    # Memory Usage
    # =========================================================================
    MEM_PERCENT=$(free | grep Mem | awk '{print ($3/$2) * 100.0}')
    
    # =========================================================================
    # Redis Memory
    # =========================================================================
    REDIS_MEM_MB=$(redis-cli INFO memory 2>/dev/null | grep "used_memory_human" | cut -d: -f2 | sed 's/M//' | tr -d '\r' || echo "0")
    
    # Redis Operations Per Second
    REDIS_OPS=$(redis-cli INFO stats 2>/dev/null | grep "instantaneous_ops_per_sec" | cut -d: -f2 | tr -d '\r' || echo "0")
    
    # =========================================================================
    # MySQL Connections
    # =========================================================================
    MYSQL_CONNECTIONS=$(mysql -u root -ppassword -e "SHOW STATUS LIKE 'Threads_connected';" 2>/dev/null | tail -1 | awk '{print $2}' || echo "0")
    
    # =========================================================================
    # MySQL Lock Waits
    # =========================================================================
    DB_LOCK_WAITS=$(mysql -u root -ppassword -e "SHOW STATUS LIKE 'Innodb_row_lock_waits';" 2>/dev/null | tail -1 | awk '{print $2}' || echo "0")
    
    # =========================================================================
    # Log Metrics
    # =========================================================================
    METRICS="$TIMESTAMP,$CPU_PERCENT,$MEM_PERCENT,$REDIS_MEM_MB,$MYSQL_CONNECTIONS,$DB_LOCK_WAITS,$REDIS_OPS"
    echo "$METRICS" | tee -a "$LOG_FILE"
    
    # =========================================================================
    # Alert Thresholds
    # =========================================================================
    if (( $(echo "$CPU_PERCENT > 80" | bc -l) )); then
        echo -e "${RED}⚠️  HIGH CPU: ${CPU_PERCENT}%${NC}"
    fi
    
    if (( $(echo "$MEM_PERCENT > 80" | bc -l) )); then
        echo -e "${RED}⚠️  HIGH MEMORY: ${MEM_PERCENT}%${NC}"
    fi
    
    if (( $(echo "$REDIS_MEM_MB > 1000" | bc -l) )); then
        echo -e "${YELLOW}⚠️  HIGH REDIS MEMORY: ${REDIS_MEM_MB}MB${NC}"
    fi
    
    if [ "$MYSQL_CONNECTIONS" -gt 100 ]; then
        echo -e "${YELLOW}⚠️  HIGH MYSQL CONNECTIONS: ${MYSQL_CONNECTIONS}${NC}"
    fi
    
    if [ "$DB_LOCK_WAITS" -gt 10 ]; then
        echo -e "${RED}⚠️  DB LOCK WAITS: ${DB_LOCK_WAITS}${NC}"
    fi
    
    sleep "$INTERVAL"
done
