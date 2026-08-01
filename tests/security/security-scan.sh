#!/bin/bash
# =============================================================================
# Security Scan Script untuk AbsensiQRPro
# Usage: ./tests/security/security-scan.sh [target_url]
# =============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Configuration
TARGET_URL=${1:-http://localhost:8000}
REPORT_DIR="reports"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
REPORT_FILE="$REPORT_DIR/security-report-$TIMESTAMP.html"

# Create report directory
mkdir -p "$REPORT_DIR"

echo -e "${GREEN}[INFO]${NC}=========================================="
echo -e "${GREEN}[INFO]${NC}  AbsensiQRPro Security Scan"
echo -e "${GREEN}[INFO]${NC}=========================================="
echo -e "${GREEN}[INFO]${NC}  Target: $TARGET_URL"
echo -e "${GREEN}[INFO]${NC}  Report: $REPORT_FILE"
echo

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo -e "${RED}[ERROR]${NC} Docker is required for security scanning"
    echo -e "${YELLOW}[INFO]${NC}  Install Docker: https://docs.docker.com/get-docker/"
    exit 1
fi

# Pull OWASP ZAP image
echo -e "${GREEN}[INFO]${NC} Pulling OWASP ZAP Docker image..."
docker pull zaproxy/zap-stable:latest

# Run OWASP ZAP scan
echo -e "${GREEN}[INFO]${NC} Starting OWASP ZAP scan..."
echo -e "${YELLOW}[INFO]${NC}  This may take 10-30 minutes depending on target size."
echo

docker run --rm \
    -v "$(pwd)/$REPORT_DIR:/zap/reports:rw" \
    -t zaproxy/zap-stable \
    zap-full-scan.py \
    -t "$TARGET_URL" \
    -r "security-report-$TIMESTAMP.html" \
    -J "security-report-$TIMESTAMP.json" \
    -x "security-report-$TIMESTAMP.xml" \
    -config api.disablekey=true \
    -config scanner.maxScanDurationInMins=30 \
    -config spider.maxDurationInMins=10

# Check if report was generated
if [ -f "$REPORT_DIR/security-report-$TIMESTAMP.html" ]; then
    echo
    echo -e "${GREEN}[INFO]${NC}=========================================="
    echo -e "${GREEN}[INFO]${NC}  Security Scan Completed!"
    echo -e "${GREEN}[INFO]${NC}=========================================="
    echo -e "${GREEN}[INFO]${NC}  HTML Report: $REPORT_DIR/security-report-$TIMESTAMP.html"
    echo -e "${GREEN}[INFO]${NC}  JSON Report: $REPORT_DIR/security-report-$TIMESTAMP.json"
    echo -e "${GREEN}[INFO]${NC}  XML Report: $REPORT_DIR/security-report-$TIMESTAMP.xml"
    echo
    echo -e "${YELLOW}[INFO]${NC}  Open the HTML report in a browser to view results."
    echo
else
    echo -e "${RED}[ERROR]${NC} Security report was not generated"
    exit 1
fi
