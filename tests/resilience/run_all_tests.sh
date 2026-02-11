#!/bin/bash
# tests/resilience/run_all_tests.sh
# National Scale Resilience Simulation - Master Test Runner

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "=========================================="
echo "National Scale Resilience Simulation"
echo "=========================================="
echo ""
echo "Target: 1,000 schools, 100,000 concurrent students"
echo "Regions: 5 (Jakarta, Surabaya, Bandung, Medan, Makassar)"
echo ""

# Configuration
export API_URL="${API_URL:-https://api.attendance.com}"
export PROMETHEUS_URL="${PROMETHEUS_URL:-http://prometheus:9090}"
export GRAFANA_URL="${GRAFANA_URL:-http://grafana:3000}"
export K6_CLOUD_TOKEN="${K6_CLOUD_TOKEN:-}"

# Test results directory
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
RESULTS_DIR="./results/$TIMESTAMP"
mkdir -p "$RESULTS_DIR"

echo "Results will be saved to: $RESULTS_DIR"
echo ""

# Function to print section header
print_header() {
    echo ""
    echo "=========================================="
    echo "$1"
    echo "=========================================="
    echo ""
}

# Function to run test and capture results
run_test() {
    local test_name=$1
    local test_command=$2
    local timeout=${3:-600}  # Default 10 min timeout
    
    print_header "Running: $test_name"
    
    local start_time=$(date +%s)
    
    # Start monitoring
    echo "Starting monitoring..."
    python3 monitoring/start_monitoring.py \
        --test="$test_name" \
        --output="$RESULTS_DIR/$test_name-metrics.json" &
    MONITOR_PID=$!
    
    # Run test with timeout
    echo "Executing test..."
    timeout $timeout bash -c "$test_command" 2>&1 | tee "$RESULTS_DIR/$test_name.log"
    TEST_EXIT_CODE=${PIPESTATUS[0]}
    
    # Stop monitoring
    kill $MONITOR_PID 2>/dev/null || true
    wait $MONITOR_PID 2>/dev/null || true
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    # Generate report
    echo "Generating report..."
    python3 monitoring/generate_report.py \
        --test="$test_name" \
        --log="$RESULTS_DIR/$test_name.log" \
        --metrics="$RESULTS_DIR/$test_name-metrics.json" \
        --output="$RESULTS_DIR/$test_name-report.html"
    
    # Check pass/fail
    if [ $TEST_EXIT_CODE -eq 0 ]; then
        echo -e "${GREEN}✅ PASS${NC}: $test_name (${duration}s)"
        echo "$test_name,PASS,$duration" >> "$RESULTS_DIR/summary.csv"
        return 0
    else
        echo -e "${RED}❌ FAIL${NC}: $test_name (${duration}s)"
        echo "$test_name,FAIL,$duration" >> "$RESULTS_DIR/summary.csv"
        return 1
    fi
}

# Initialize summary CSV
echo "test_name,status,duration_seconds" > "$RESULTS_DIR/summary.csv"

# Pre-flight checks
print_header "Pre-Flight Checks"

echo "Checking API availability..."
if ! curl -s -f "$API_URL/api/v1/health" > /dev/null; then
    echo -e "${RED}❌ API not available${NC}"
    exit 1
fi
echo -e "${GREEN}✅ API available${NC}"

echo "Checking Prometheus..."
if ! curl -s -f "$PROMETHEUS_URL/-/healthy" > /dev/null; then
    echo -e "${YELLOW}⚠️  Prometheus not available (metrics collection disabled)${NC}"
else
    echo -e "${GREEN}✅ Prometheus available${NC}"
fi

echo "Checking k6..."
if ! command -v k6 &> /dev/null; then
    echo -e "${RED}❌ k6 not installed${NC}"
    exit 1
fi
echo -e "${GREEN}✅ k6 installed${NC}"

echo ""
echo "All pre-flight checks passed!"
echo ""

# Test 1: Monday Peak Load
run_test \
    "monday_peak" \
    "k6 run --out json=$RESULTS_DIR/monday_peak-k6.json k6/scenarios/monday_peak.js" \
    900
MONDAY_PEAK_RESULT=$?

# Test 2: Redis Failure
run_test \
    "redis_failure" \
    "python3 monitoring/redis_failure_test.py --output=$RESULTS_DIR/redis_failure-result.json" \
    600
REDIS_FAILURE_RESULT=$?

# Test 3: DB Replication Lag
run_test \
    "db_replication_lag" \
    "python3 monitoring/db_replication_lag_test.py --output=$RESULTS_DIR/db_lag-result.json" \
    600
DB_LAG_RESULT=$?

# Test 4: Payment Webhook Storm
run_test \
    "webhook_storm" \
    "k6 run --out json=$RESULTS_DIR/webhook_storm-k6.json k6/scenarios/webhook_storm.js" \
    600
WEBHOOK_STORM_RESULT=$?

# Test 5: DDoS Attack
run_test \
    "ddos_attack" \
    "bash tests/ddos_attack.sh --output=$RESULTS_DIR/ddos-result.json" \
    600
DDOS_RESULT=$?

# Test 6: QR Sharing Attack
run_test \
    "qr_sharing_attack" \
    "k6 run --out json=$RESULTS_DIR/qr_attack-k6.json k6/scenarios/qr_sharing_attack.js" \
    600
QR_ATTACK_RESULT=$?

# Generate final summary
print_header "Test Summary"

echo ""
printf "%-30s %s\n" "Test Name" "Status"
echo "-------------------------------------------"
printf "%-30s %s\n" "1. Monday Peak Load" "$([ $MONDAY_PEAK_RESULT -eq 0 ] && echo -e "${GREEN}✅ PASS${NC}" || echo -e "${RED}❌ FAIL${NC}")"
printf "%-30s %s\n" "2. Redis Failure" "$([ $REDIS_FAILURE_RESULT -eq 0 ] && echo -e "${GREEN}✅ PASS${NC}" || echo -e "${RED}❌ FAIL${NC}")"
printf "%-30s %s\n" "3. DB Replication Lag" "$([ $DB_LAG_RESULT -eq 0 ] && echo -e "${GREEN}✅ PASS${NC}" || echo -e "${RED}❌ FAIL${NC}")"
printf "%-30s %s\n" "4. Webhook Storm" "$([ $WEBHOOK_STORM_RESULT -eq 0 ] && echo -e "${GREEN}✅ PASS${NC}" || echo -e "${RED}❌ FAIL${NC}")"
printf "%-30s %s\n" "5. DDoS Attack" "$([ $DDOS_RESULT -eq 0 ] && echo -e "${GREEN}✅ PASS${NC}" || echo -e "${RED}❌ FAIL${NC}")"
printf "%-30s %s\n" "6. QR Sharing Attack" "$([ $QR_ATTACK_RESULT -eq 0 ] && echo -e "${GREEN}✅ PASS${NC}" || echo -e "${RED}❌ FAIL${NC}")"
echo ""

# Calculate overall pass rate
TOTAL_TESTS=6
PASSED_TESTS=0
[ $MONDAY_PEAK_RESULT -eq 0 ] && ((PASSED_TESTS++))
[ $REDIS_FAILURE_RESULT -eq 0 ] && ((PASSED_TESTS++))
[ $DB_LAG_RESULT -eq 0 ] && ((PASSED_TESTS++))
[ $WEBHOOK_STORM_RESULT -eq 0 ] && ((PASSED_TESTS++))
[ $DDOS_RESULT -eq 0 ] && ((PASSED_TESTS++))
[ $QR_ATTACK_RESULT -eq 0 ] && ((PASSED_TESTS++))

PASS_RATE=$((PASSED_TESTS * 100 / TOTAL_TESTS))

echo "Overall Pass Rate: $PASS_RATE% ($PASSED_TESTS/$TOTAL_TESTS)"
echo ""

# Generate consolidated HTML report
print_header "Generating Consolidated Report"

python3 monitoring/generate_consolidated_report.py \
    --results-dir="$RESULTS_DIR" \
    --output="$RESULTS_DIR/consolidated-report.html"

echo "Consolidated report: $RESULTS_DIR/consolidated-report.html"
echo ""

# Upload to S3 (optional)
if [ -n "${AWS_S3_BUCKET}" ]; then
    echo "Uploading results to S3..."
    aws s3 sync "$RESULTS_DIR" "s3://${AWS_S3_BUCKET}/resilience-tests/$TIMESTAMP/"
    echo "Results uploaded to: s3://${AWS_S3_BUCKET}/resilience-tests/$TIMESTAMP/"
fi

# Send notification (optional)
if [ -n "${SLACK_WEBHOOK_URL}" ]; then
    echo "Sending Slack notification..."
    curl -X POST "$SLACK_WEBHOOK_URL" \
        -H 'Content-Type: application/json' \
        -d "{\"text\":\"Resilience Test Complete: $PASS_RATE% pass rate ($PASSED_TESTS/$TOTAL_TESTS)\"}"
fi

# Exit with failure if any test failed
if [ $PASS_RATE -lt 100 ]; then
    echo -e "${RED}Some tests failed. Please review the reports.${NC}"
    exit 1
fi

echo -e "${GREEN}All tests passed! System is production-ready.${NC}"
exit 0
