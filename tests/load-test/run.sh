#!/bin/bash
# =============================================================================
# Load Test Runner untuk AbsensiQRPro
# Usage: ./tests/load-test/run.sh [base_url]
# =============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Configuration
BASE_URL=${1:-http://localhost:8000}
RESULTS_DIR="results"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RESULTS_FILE="$RESULTS_DIR/load-test-$TIMESTAMP.json"

# Create results directory
mkdir -p "$RESULTS_DIR"

echo -e "${GREEN}[INFO]${NC}=========================================="
echo -e "${GREEN}[INFO]${NC}  AbsensiQRPro Load Test"
echo -e "${GREEN}[INFO]${NC}=========================================="
echo -e "${GREEN}[INFO]${NC}  Target: $BASE_URL"
echo -e "${GREEN}[INFO]${NC}  Results: $RESULTS_FILE"
echo

# Check if k6 is installed
if ! command -v k6 &> /dev/null; then
    echo -e "${YELLOW}[WARN]${NC} k6 not found. Installing..."
    
    # Detect OS
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        sudo apt-key adv --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
        echo "deb https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
        sudo apt update
        sudo apt install k6
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        brew install k6
    else
        echo -e "${RED}[ERROR]${NC} Please install k6 manually: https://k6.io/docs/get-started/installation/"
        exit 1
    fi
fi

# Pre-flight checks
echo -e "${GREEN}[INFO]${NC} Running pre-flight checks..."

# Check if target is reachable
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/health" 2>/dev/null || echo "000")
if [ "$HTTP_STATUS" != "200" ]; then
    echo -e "${RED}[ERROR]${NC} Target $BASE_URL/health is not reachable (HTTP $HTTP_STATUS)"
    echo -e "${YELLOW}[WARN]${NC} Make sure the application is running."
    read -p "Continue anyway? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Run load test
echo -e "${GREEN}[INFO]${NC} Starting load test..."
echo

k6 run \
    --out json="$RESULTS_FILE" \
    --env BASE_URL="$BASE_URL" \
    load-test.js

# Check results
if [ -f "$RESULTS_FILE" ]; then
    echo -e "${GREEN}[INFO]${NC}=========================================="
    echo -e "${GREEN}[INFO]${NC}  Load Test Completed!"
    echo -e "${GREEN}[INFO]${NC}=========================================="
    echo -e "${GREEN}[INFO]${NC}  Results saved to: $RESULTS_FILE"
    echo
    echo -e "${YELLOW}[INFO]${NC}  To view results in Grafana:"
    echo -e "${YELLOW}[INFO]${NC}  1. Import the JSON to Grafana"
    echo -e "${YELLOW}[INFO]${NC}  2. Or use: k6 dashboard $RESULTS_FILE"
    echo
else
    echo -e "${RED}[ERROR]${NC} Results file not created"
    exit 1
fi
