#!/bin/bash

###############################################################################
# Automated Chaos Engineering Load Test
# 
# Runs complete load test suite with chaos scenarios:
# 1. 5000 students scan dalam 5 detik
# 2. 10 teachers generate QR bersamaan
# 3. 100 webhooks bersamaan
# 4. Redis restart saat load
# 5. DB latency injection (300ms)
# 
# Usage:
# ./run-chaos-test.sh
###############################################################################

set -e  # Exit on error

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
TEST_DURATION=60
PEAK_VUS=5000
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RESULTS_DIR="results_${TIMESTAMP}"

echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  🚀 CHAOS ENGINEERING LOAD TEST - AbsensiQR Pro SaaS          ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# ============================================================================
# STEP 1: Pre-flight Checks
# ============================================================================

echo -e "${BLUE}📋 Step 1: Pre-flight Checks${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Check if services are running
echo -n "Checking MySQL... "
if mysql -u root -ppassword -e "SELECT 1" &>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗ MySQL not running${NC}"
    exit 1
fi

echo -n "Checking Redis... "
if redis-cli ping &>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗ Redis not running${NC}"
    exit 1
fi

echo -n "Checking Larave