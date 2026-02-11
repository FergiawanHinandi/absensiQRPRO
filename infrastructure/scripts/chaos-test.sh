#!/bin/bash

# Chaos Testing Script for Self-Healing Infrastructure
# This script simulates various failure scenarios to test auto-remediation

set -e

NAMESPACE="${NAMESPACE:-production}"
LOG_FILE="chaos-test-$(date +%Y%m%d-%H%M%S).log"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

check_health() {
    log "Checking system health..."
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        curl -s http://localhost/health/deep | jq .
}

test_redis_failure() {
    log "========================================="
    log "TEST 1: Redis Node Failure"
    log "========================================="
    
    log "Baseline health check..."
    check_health
    
    log "Killing Redis pod..."
    kubectl delete pod -n "$NAMESPACE" -l app=redis --force --grace-period=0
    
    log "Waiting 5 seconds..."
    sleep 5
    
    log "Triggering attendance scan (should use fallback)..."
    # Simulate attendance scan via API
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        curl -X POST http://localhost/api/attendance/scan \
        -H "Content-Type: application/json" \
        -d '{"qr_token":"test_token"}' || true
    
    log "Checking circuit breaker status..."
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        php artisan circuit-breaker:status redis
    
    log "Waiting for Redis to recover..."
    kubectl wait --for=condition=ready pod -n "$NAMESPACE" -l app=redis --timeout=120s
    
    log "Waiting 15 seconds for circuit breaker to attempt reset..."
    sleep 15
    
    log "Checking circuit breaker status (should be closed)..."
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        php artisan circuit-breaker:status redis
    
    log "Final health check..."
    check_health
    
    log "✓ Test 1 complete"
}

test_db_primary_failure() {
    log "========================================="
    log "TEST 2: Database Primary Failure"
    log "========================================="
    
    log "Baseline health check..."
    check_health
    
    log "Killing DB primary pod..."
    kubectl delete pod -n "$NAMESPACE" -l app=mysql,role=primary --force --grace-period=0
    
    log "Waiting 10 seconds for failover..."
    sleep 10
    
    log "Triggering attendance scan (should use replica)..."
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        curl -X POST http://localhost/api/attendance/scan \
        -H "Content-Type: application/json" \
        -d '{"qr_token":"test_token2"}' || true
    
    log "Waiting for DB primary to recover..."
    kubectl wait --for=condition=ready pod -n "$NAMESPACE" -l app=mysql,role=primary --timeout=120s
    
    log "Final health check..."
    check_health
    
    log "✓ Test 2 complete"
}

test_worker_crash() {
    log "========================================="
    log "TEST 3: Queue Worker Crash"
    log "========================================="
    
    log "Baseline worker count..."
    INITIAL_WORKERS=$(kubectl get pods -n "$NAMESPACE" -l app=queue-worker --no-headers | wc -l)
    log "Initial workers: $INITIAL_WORKERS"
    
    log "Killing 2 worker pods..."
    kubectl delete pod -n "$NAMESPACE" -l app=queue-worker --force --grace-period=0 | head -2
    
    log "Waiting 30 seconds..."
    sleep 30
    
    log "Checking if autoscaler added new workers..."
    CURRENT_WORKERS=$(kubectl get pods -n "$NAMESPACE" -l app=queue-worker --no-headers | wc -l)
    log "Current workers: $CURRENT_WORKERS"
    
    if [ "$CURRENT_WORKERS" -ge "$INITIAL_WORKERS" ]; then
        log "✓ Workers recovered: $CURRENT_WORKERS >= $INITIAL_WORKERS"
    else
        log "✗ Workers not recovered: $CURRENT_WORKERS < $INITIAL_WORKERS"
    fi
    
    log "Checking queue lag..."
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        php artisan queue:status
    
    log "✓ Test 3 complete"
}

test_network_partition() {
    log "========================================="
    log "TEST 4: Network Partition (10 seconds)"
    log "========================================="
    
    log "Baseline health check..."
    check_health
    
    log "Getting Redis service IP..."
    REDIS_IP=$(kubectl get svc -n "$NAMESPACE" redis -o jsonpath='{.spec.clusterIP}')
    log "Redis IP: $REDIS_IP"
    
    log "Creating network partition (blocking Redis)..."
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        iptables -A OUTPUT -d "$REDIS_IP" -j DROP || true
    
    log "Waiting 10 seconds..."
    sleep 10
    
    log "Triggering attendance scan (should open circuit breaker)..."
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        curl -X POST http://localhost/api/attendance/scan \
        -H "Content-Type: application/json" \
        -d '{"qr_token":"test_token3"}' || true
    
    log "Restoring network..."
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        iptables -D OUTPUT -d "$REDIS_IP" -j DROP || true
    
    log "Waiting 30 seconds for circuit breaker to recover..."
    sleep 30
    
    log "Checking circuit breaker status (should be closed)..."
    kubectl exec -n "$NAMESPACE" deployment/attendance-web-app -- \
        php artisan circuit-breaker:status redis
    
    log "Final health check..."
    check_health
    
    log "✓ Test 4 complete"
}

test_memory_pressure() {
    log "========================================="
    log "TEST 5: Redis Memory Pressure"
    log "========================================="
    
    log "Baseline Redis memory..."
    kubectl exec -n "$NAMESPACE" deployment/redis -- \
        redis-cli INFO memory | grep used_memory_human
    
    log "Filling Redis with test data..."
    for i in {1..10000}; do
        kubectl exec -n "$NAMESPACE" deployment/redis -- \
            redis-cli SET "chaos_test_key_$i" "$(head -c 1024 /dev/urandom | base64)" > /dev/null
    done
    
    log "Checking Redis memory after fill..."
    kubectl exec -n "$NAMESPACE" deployment/redis -- \
        redis-cli INFO memory | grep used_memory_human
    
    log "Checking if auto-remediation triggered..."
    kubectl logs -n "$NAMESPACE" deployment/attendance-web-app --tail=50 | grep -i "redis memory"
    
    log "Cleaning up test data..."
    kubectl exec -n "$NAMESPACE" deployment/redis -- \
        redis-cli --scan --pattern "chaos_test_key_*" | xargs kubectl exec -n "$NAMESPACE" deployment/redis -- redis-cli DEL
    
    log "✓ Test 5 complete"
}

# Main execution
main() {
    log "Starting Chaos Testing Suite"
    log "Namespace: $NAMESPACE"
    log "Log file: $LOG_FILE"
    
    # Run tests
    test_redis_failure
    sleep 10
    
    test_worker_crash
    sleep 10
    
    test_network_partition
    sleep 10
    
    # Optionally run destructive tests
    if [ "${RUN_DESTRUCTIVE_TESTS:-false}" = "true" ]; then
        log "Running destructive tests..."
        test_db_primary_failure
        sleep 10
        
        test_memory_pressure
    else
        log "Skipping destructive tests (set RUN_DESTRUCTIVE_TESTS=true to enable)"
    fi
    
    log "========================================="
    log "All tests complete!"
    log "Review log file: $LOG_FILE"
    log "========================================="
}

# Run main function
main
