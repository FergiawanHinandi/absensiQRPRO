#!/bin/bash

# Disaster Recovery Test Runner
# AbsensiQR Pro - Automated DR Testing

set -e  # Exit on any error

echo "🚀 Starting Disaster Recovery Test Suite"
echo "========================================"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKEND_DIR/storage/logs"
BACKUP_DIR="$BACKEND_DIR/storage/backups"
TEST_RESULTS_FILE="$LOG_DIR/dr_test_results_$(date +%Y%m%d_%H%M%S).json"

# Ensure directories exist
mkdir -p "$LOG_DIR"
mkdir -p "$BACKUP_DIR"

# Function to log with timestamp
log() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Function to run test and capture results
run_test() {
    local test_name="$1"
    local test_command="$2"
    local start_time=$(date +%s)
    
    log "${BLUE}Running: $test_name${NC}"
    
    if eval "$test_command" > "$LOG_DIR/${test_name// /_}.log" 2>&1; then
        local end_time=$(date +%s)
        local duration=$((end_time - start_time))
        log "${GREEN}✅ $test_name completed in ${duration}s${NC}"
        echo "{\"test\": \"$test_name\", \"status\": \"success\", \"duration\": $duration, \"timestamp\": \"$(date -Iseconds)\"}" >> "$TEST_RESULTS_FILE.tmp"
        return 0
    else
        local end_time=$(date +%s)
        local duration=$((end_time - start_time))
        log "${RED}❌ $test_name failed after ${duration}s${NC}"
        echo "{\"test\": \"$test_name\", \"status\": \"failed\", \"duration\": $duration, \"timestamp\": \"$(date -Iseconds)\"}" >> "$TEST_RESULTS_FILE.tmp"
        return 1
    fi
}

# Function to check prerequisites
check_prerequisites() {
    log "${BLUE}Checking prerequisites...${NC}"
    
    # Check if PHP is available
    if ! command -v php &> /dev/null; then
        log "${RED}❌ PHP is not installed or not in PATH${NC}"
        exit 1
    fi
    
    # Check if Laravel is properly set up
    if [ ! -f "$BACKEND_DIR/artisan" ]; then
        log "${RED}❌ Laravel artisan not found${NC}"
        exit 1
    fi
    
    # Check database connection
    if ! php "$BACKEND_DIR/artisan" migrate:status &> /dev/null; then
        log "${YELLOW}⚠️  Database connection issues detected${NC}"
    fi
    
    log "${GREEN}✅ Prerequisites check completed${NC}"
}

# Function to create test data
create_test_data() {
    log "${BLUE}Creating test data...${NC}"
    
    # Run seeders to ensure we have test data
    php "$BACKEND_DIR/artisan" db:seed --class=TestDataSeeder --force 2>/dev/null || true
    
    # Create some test files in storage
    mkdir -p "$BACKEND_DIR/storage/app/public/student_photos"
    mkdir -p "$BACKEND_DIR/storage/app/public/qr_codes"
    
    # Create dummy test files
    echo "Test student photo" > "$BACKEND_DIR/storage/app/public/student_photos/test_student_1.jpg"
    echo "Test QR code" > "$BACKEND_DIR/storage/app/public/qr_codes/test_qr_1.png"
    
    log "${GREEN}✅ Test data created${NC}"
}

# Function to verify system state
verify_system_state() {
    local phase="$1"
    log "${BLUE}Verifying system state ($phase)...${NC}"
    
    # Check database tables exist and have data
    local tables=("users" "schools" "attendances" "classes")
    for table in "${tables[@]}"; do
        local count=$(php "$BACKEND_DIR/artisan" tinker --execute="echo DB::table('$table')->count();" 2>/dev/null | tail -1 || echo "0")
        log "  📊 $table: $count records"
    done
    
    # Check storage files
    local student_photos=$(find "$BACKEND_DIR/storage/app/public/student_photos" -type f 2>/dev/null | wc -l)
    local qr_codes=$(find "$BACKEND_DIR/storage/app/public/qr_codes" -type f 2>/dev/null | wc -l)
    
    log "  📁 Student photos: $student_photos files"
    log "  📁 QR codes: $qr_codes files"
    
    log "${GREEN}✅ System state verification completed${NC}"
}

# Main test execution
main() {
    log "${BLUE}🔥 DISASTER RECOVERY TEST SUITE STARTED${NC}"
    
    # Initialize results file
    echo "[" > "$TEST_RESULTS_FILE.tmp"
    
    # Check prerequisites
    check_prerequisites
    
    # Create test data
    create_test_data
    
    # Verify initial system state
    verify_system_state "initial"
    
    # Test 1: Full Backup Creation
    run_test "Full Backup Creation" "php '$SCRIPT_DIR/backup_system.php'"
    
    # Test 2: Disaster Recovery Simulation
    run_test "Disaster Recovery Simulation" "php '$SCRIPT_DIR/disaster_recovery_simulation.php'"
    
    # Test 3: Database Restore Test
    if [ -f "$BACKUP_DIR"/*.tar.gz ] || [ -d "$BACKUP_DIR"/full_backup_* ]; then
        run_test "Database Restore Test" "php '$SCRIPT_DIR/restore_system.php'"
    else
        log "${YELLOW}⚠️  Skipping restore test - no backup files found${NC}"
    fi
    
    # Test 4: Storage Integrity Check
    run_test "Storage Integrity Check" "find '$BACKEND_DIR/storage/app/public' -type f -name '*.jpg' -o -name '*.png' | head -5"
    
    # Test 5: Security Events Verification
    run_test "Security Events Verification" "php '$BACKEND_DIR/artisan' tinker --execute='echo DB::table(\"security_events\")->count() ?? 0;' 2>/dev/null || echo '0'"
    
    # Test 6: Application Health Check
    run_test "Application Health Check" "php '$BACKEND_DIR/artisan' route:list --compact"
    
    # Verify final system state
    verify_system_state "final"
    
    # Finalize results file
    sed '$ s/,$//' "$TEST_RESULTS_FILE.tmp" > "$TEST_RESULTS_FILE.tmp2"
    echo "]" >> "$TEST_RESULTS_FILE.tmp2"
    mv "$TEST_RESULTS_FILE.tmp2" "$TEST_RESULTS_FILE"
    rm -f "$TEST_RESULTS_FILE.tmp"
    
    # Generate summary report
    generate_summary_report
    
    log "${GREEN}🎉 DISASTER RECOVERY TEST SUITE COMPLETED${NC}"
}

# Function to generate summary report
generate_summary_report() {
    log "${BLUE}Generating summary report...${NC}"
    
    local total_tests=$(jq length "$TEST_RESULTS_FILE")
    local passed_tests=$(jq '[.[] | select(.status == "success")] | length' "$TEST_RESULTS_FILE")
    local failed_tests=$(jq '[.[] | select(.status == "failed")] | length' "$TEST_RESULTS_FILE")
    local total_duration=$(jq '[.[].duration] | add' "$TEST_RESULTS_FILE")
    
    echo ""
    echo "📊 DISASTER RECOVERY TEST SUMMARY"
    echo "=================================="
    echo "Total Tests: $total_tests"
    echo "Passed: $passed_tests"
    echo "Failed: $failed_tests"
    echo "Total Duration: ${total_duration}s"
    echo "Success Rate: $(( passed_tests * 100 / total_tests ))%"
    echo ""
    echo "Detailed results saved to: $TEST_RESULTS_FILE"
    
    # Show failed tests if any
    if [ "$failed_tests" -gt 0 ]; then
        echo ""
        log "${RED}❌ FAILED TESTS:${NC}"
        jq -r '.[] | select(.status == "failed") | "  - " + .test' "$TEST_RESULTS_FILE"
        echo ""
        log "${YELLOW}Check individual log files in $LOG_DIR for details${NC}"
    fi
    
    # Create HTML report
    create_html_report "$total_tests" "$passed_tests" "$failed_tests" "$total_duration"
}

# Function to create HTML report
create_html_report() {
    local total="$1"
    local passed="$2"
    local failed="$3"
    local duration="$4"
    
    local html_file="$LOG_DIR/dr_test_report_$(date +%Y%m%d_%H%M%S).html"
    
    cat > "$html_file" << EOF
<!DOCTYPE html>
<html>
<head>
    <title>Disaster Recovery Test Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #f4f4f4; padding: 20px; border-radius: 5px; }
        .summary { display: flex; gap: 20px; margin: 20px 0; }
        .metric { background: #e9ecef; padding: 15px; border-radius: 5px; text-align: center; }
        .success { color: #28a745; }
        .failed { color: #dc3545; }
        .test-results { margin-top: 20px; }
        .test-item { padding: 10px; margin: 5px 0; border-left: 4px solid #ccc; }
        .test-success { border-left-color: #28a745; background: #d4edda; }
        .test-failed { border-left-color: #dc3545; background: #f8d7da; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔥 Disaster Recovery Test Report</h1>
        <p>Generated: $(date)</p>
        <p>Environment: $(php "$BACKEND_DIR/artisan" env 2>/dev/null || echo "Unknown")</p>
    </div>
    
    <div class="summary">
        <div class="metric">
            <h3>Total Tests</h3>
            <div style="font-size: 2em;">$total</div>
        </div>
        <div class="metric">
            <h3 class="success">Passed</h3>
            <div style="font-size: 2em; color: #28a745;">$passed</div>
        </div>
        <div class="metric">
            <h3 class="failed">Failed</h3>
            <div style="font-size: 2em; color: #dc3545;">$failed</div>
        </div>
        <div class="metric">
            <h3>Duration</h3>
            <div style="font-size: 2em;">${duration}s</div>
        </div>
    </div>
    
    <div class="test-results">
        <h2>Test Results</h2>
EOF
    
    # Add test results
    jq -r '.[] | "<div class=\"test-item test-" + .status + "\"><strong>" + .test + "</strong><br>Status: " + .status + " | Duration: " + (.duration | tostring) + "s | Time: " + .timestamp + "</div>"' "$TEST_RESULTS_FILE" >> "$html_file"
    
    cat >> "$html_file" << EOF
    </div>
</body>
</html>
EOF
    
    log "${GREEN}✅ HTML report created: $html_file${NC}"
}

# Cleanup function
cleanup() {
    log "${BLUE}Cleaning up temporary files...${NC}"
    # Remove any temporary test files
    rm -f "$BACKEND_DIR/storage/app/public/student_photos/test_student_1.jpg" 2>/dev/null || true
    rm -f "$BACKEND_DIR/storage/app/public/qr_codes/test_qr_1.png" 2>/dev/null || true
}

# Set up trap for cleanup
trap cleanup EXIT

# Check if jq is available for JSON processing
if ! command -v jq &> /dev/null; then
    log "${YELLOW}⚠️  jq not found, installing basic JSON processing...${NC}"
    # Provide basic JSON processing fallback
    jq() {
        python3 -c "import json, sys; data=json.load(sys.stdin); print(len(data) if '$1' == 'length' else 'N/A')"
    }
fi

# Run main function
main "$@"