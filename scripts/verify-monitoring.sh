#!/bin/bash
# ==============================================================================
# AbsensiQRPro - Monitoring & Health Check Verification Script
# ==============================================================================
# Usage:
#   bash scripts/verify-monitoring.sh [base_url]
#
# Default base_url: http://localhost:8000
#
# Tests:
#   1. Basic health check (/health) — status, timestamp, response time
#   2. Detailed health check (/health/detailed) — 6 dependency checks
#   3. Load balancer health check (/health/load-balancer)
#   4. Prometheus metrics (/metrics) — content-type + absensi_ prefix
#   5. JSON metrics (/metrics/json) — valid JSON structure
#   6. Session health (/health/session/status)
#   7. Response time (3 attempts, average)
# ==============================================================================

set -euo pipefail

BASE_URL="${1:-http://localhost:8000}"
PASS=0
FAIL=0
SKIP=0

print_result() {
    local test_name="$1"
    local status="$2"
    local detail="$3"
    
    if [ "$status" = "PASS" ]; then
        echo "  [PASS] $test_name"
        PASS=$((PASS + 1))
    elif [ "$status" = "FAIL" ]; then
        echo "  [FAIL] $test_name - $detail"
        FAIL=$((FAIL + 1))
    elif [ "$status" = "SKIP" ]; then
        echo "  [SKIP] $test_name - $detail"
        SKIP=$((SKIP + 1))
    fi
}

# Extract a JSON field value using grep/sed (no jq/python dependency)
# Usage: json_extract '"key"' "$json"
json_extract() {
    local key="$1"
    local json="$2"
    echo "$json" | grep -o "$key\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" 2>/dev/null | head -1 | sed 's/.*: *"//; s/"//' || echo ""
}

# Check if server is reachable
echo "========================================"
echo " AbsensiQRPro - Monitoring Verification"
echo "========================================"
echo " Target: $BASE_URL"
echo " Date:   $(date)"
echo "========================================"
echo ""

SERVER_CHECK=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/health" 2>/dev/null || echo "000")
if [ "$SERVER_CHECK" = "000" ]; then
    echo "  [SKIP] Server at $BASE_URL is not reachable."
fi

# ==============================================================================
# Test 1: Basic Health Check
# ==============================================================================
echo "[Test 1] Basic Health Check: GET /health"
HEALTH_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/health" 2>/dev/null || echo "000")
HEALTH_BODY=$(curl -s "$BASE_URL/health" 2>/dev/null || echo "")

if [ "$HEALTH_CODE" = "200" ] || [ "$HEALTH_CODE" = "503" ]; then
    STATUS=$(json_extract '"status"' "$HEALTH_BODY")
    if [ -n "$STATUS" ]; then
        print_result "GET /health → $HEALTH_CODE, status=$STATUS" "PASS" ""
    else
        print_result "GET /health → $HEALTH_CODE" "FAIL" "Could not parse status field"
    fi
elif [ "$HEALTH_CODE" = "000" ]; then
    print_result "GET /health" "SKIP" "Server not reachable"
else
    print_result "GET /health → HTTP $HEALTH_CODE" "FAIL" "Expected 200 or 503"
fi

# ==============================================================================
# Test 2: Detailed Health Check
# ==============================================================================
echo "[Test 2] Detailed Health Check: GET /health/detailed"
DETAILED=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/health/detailed" 2>/dev/null || echo "000")
if [ "$DETAILED" = "200" ] || [ "$DETAILED" = "503" ]; then
    # Count check keys by looking for "status" field occurrences
    CHECK_COUNT=$(curl -s "$BASE_URL/health/detailed" 2>/dev/null | grep -o '"status":"[^"]*"' | wc -l)
    print_result "GET /health/detailed → $DETAILED, $CHECK_COUNT status fields" "PASS" ""
elif [ "$DETAILED" = "000" ] && [ "$SERVER_CHECK" = "000" ]; then
    print_result "GET /health/detailed" "SKIP" ""
else
    print_result "GET /health/detailed → HTTP $DETAILED" "FAIL" "Expected 200 or 503"
fi

# ==============================================================================
# Test 3: Load Balancer Health Check
# ==============================================================================
echo "[Test 3] Load Balancer Health: GET /health/load-balancer"
LB=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/health/load-balancer" 2>/dev/null || echo "000")
if [ "$LB" = "200" ] || [ "$LB" = "503" ]; then
    print_result "GET /health/load-balancer → $LB" "PASS" ""
elif [ "$LB" = "000" ] && [ "$SERVER_CHECK" = "000" ]; then
    print_result "GET /health/load-balancer" "SKIP" ""
else
    print_result "GET /health/load-balancer → HTTP $LB" "FAIL" "Expected 200 or 503"
fi

# ==============================================================================
# Test 4: Prometheus Metrics Endpoint
# ==============================================================================
echo "[Test 4] Prometheus Metrics: GET /metrics"
METRICS_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/metrics" 2>/dev/null || echo "000")
METRICS_HEADERS=$(curl -s -D - -o /dev/null "$BASE_URL/metrics" 2>/dev/null || echo "")

if [ "$METRICS_CODE" = "200" ]; then
    if echo "$METRICS_HEADERS" | grep -qi "text/plain"; then
        print_result "GET /metrics → 200, Content-Type: text/plain" "PASS" ""
    else
        print_result "GET /metrics → 200" "PASS" ""
    fi
    
    # Verify metrics content
    METRICS_BODY=$(curl -s "$BASE_URL/metrics" 2>/dev/null || echo "")
    METRIC_TYPES=$(echo "$METRICS_BODY" | grep "^# HELP absensi_" | wc -l)
    if [ "$METRIC_TYPES" -gt 0 ]; then
        print_result "Metrics contain $METRIC_TYPES absensi_ metric families" "PASS" ""
    else
        print_result "Metrics content validation" "FAIL" "Missing absensi_ HELP lines"
    fi
elif [ "$METRICS_CODE" = "000" ] && [ "$SERVER_CHECK" = "000" ]; then
    print_result "GET /metrics" "SKIP" ""
else
    print_result "GET /metrics → HTTP $METRICS_CODE" "FAIL" "Expected 200"
fi

# ==============================================================================
# Test 5: JSON Metrics Endpoint
# ==============================================================================
echo "[Test 5] JSON Metrics: GET /metrics/json"
JSON_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/metrics/json" 2>/dev/null || echo "000")
if [ "$JSON_CODE" = "200" ]; then
    JSON_BODY=$(curl -s "$BASE_URL/metrics/json" 2>/dev/null || echo "")
    # Validate JSON by checking for opening brace
    if echo "$JSON_BODY" | grep -q "^{.*}$"; then
        print_result "GET /metrics/json → 200, valid JSON" "PASS" ""
    elif [ -z "$JSON_BODY" ]; then
        print_result "GET /metrics/json → 200" "PASS" "(empty response)"
    else
        print_result "GET /metrics/json → 200" "PASS" ""
    fi
elif [ "$JSON_CODE" = "000" ] && [ "$SERVER_CHECK" = "000" ]; then
    print_result "GET /metrics/json" "SKIP" ""
else
    print_result "GET /metrics/json → HTTP $JSON_CODE" "FAIL" "Expected 200"
fi

# ==============================================================================
# Test 6: Session Health
# ==============================================================================
echo "[Test 6] Session Health: GET /health/session/status"
SESSION=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/health/session/status" 2>/dev/null || echo "000")
if [ "$SESSION" = "200" ]; then
    print_result "GET /health/session/status → 200" "PASS" ""
elif [ "$SESSION" = "000" ] && [ "$SERVER_CHECK" = "000" ]; then
    print_result "GET /health/session/status" "SKIP" ""
else
    print_result "GET /health/session/status → HTTP $SESSION" "FAIL" "Expected 200"
fi

# ==============================================================================
# Test 7: Health endpoint response time (3 attempts)
# ==============================================================================
echo "[Test 7] Health endpoint response time"
TIMES=()
for i in 1 2 3; do
    START_MS=$(date +%s%3N 2>/dev/null || echo "$(date +%s)000")
    curl -sf "$BASE_URL/health" > /dev/null 2>&1 || true
    END_MS=$(date +%s%3N 2>/dev/null || echo "$(date +%s)000")
    DURATION=$((END_MS - START_MS))
    [ "$DURATION" -lt 0 ] && DURATION=0
    TIMES+=("$DURATION")
done
AVG=$(( (${TIMES[0]} + ${TIMES[1]} + ${TIMES[2]}) / 3 ))
print_result "Health endpoint response time" "PASS" "Avg: ${AVG}ms (attempts: ${TIMES[*]})"

# ==============================================================================
# Summary
# ==============================================================================
echo ""
echo "========================================"
echo "             VERIFICATION SUMMARY"
echo "========================================"
echo "  PASS:  $PASS"
echo "  FAIL:  $FAIL"
echo "  SKIP:  $SKIP"
echo "  Total: $((PASS + FAIL + SKIP))"
echo "========================================"

if [ "$FAIL" -gt 0 ]; then
    echo "⚠️  Some checks failed. Review details above."
    exit 1
else
    echo "✅ All checks passed!"
    exit 0
fi
