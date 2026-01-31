#!/bin/bash

# ============================================================================
# Security Setup Script - AbsensiQRPro
# ============================================================================
# 
# This script configures Git hooks for secret detection
# 
# Usage:
#   ./setup-security.sh
# ============================================================================

# ANSI color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;36m'
NC='\033[0m' # No Color

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  AbsensiQRPro - Security Setup                               ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

echo -e "${GREEN}🔒 Configuring Git hooks for secret detection...${NC}"
echo ""

# Configure Git to use .githooks directory
git config core.hooksPath .githooks

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Git hooks configured successfully!${NC}"
    echo ""
    
    # Make hook executable (Linux/Mac only)
    chmod +x .githooks/pre-commit
    echo -e "${GREEN}✅ Hook made executable${NC}"
    echo ""
    
    # Verify configuration
    echo -e "${BLUE}Verifying configuration...${NC}"
    git config core.hooksPath
    echo ""
    
    echo -e "${GREEN}✅ Security setup complete!${NC}"
    echo ""
    echo -e "${YELLOW}What's protected:${NC}"
    echo "  - APP_KEY with actual values"
    echo "  - DB_PASSWORD with real passwords"
    echo "  - QR_SECRET_KEY with actual keys"
    echo "  - API tokens and Bearer tokens"
    echo "  - Private keys and certificates"
    echo "  - AWS credentials"
    echo "  - .env files"
    echo ""
    echo -e "${YELLOW}Next steps:${NC}"
    echo "  1. Start developing with confidence"
    echo "  2. The pre-commit hook will automatically scan your commits"
    echo "  3. Read docs/SECURITY_GUIDELINES.md for best practices"
    echo ""
    echo -e "${BLUE}Test the hook:${NC}"
    echo "  echo 'APP_KEY=base64:testkey123' > test.txt"
    echo "  git add test.txt"
    echo "  git commit -m 'test'"
    echo "  # Should be BLOCKED"
    echo ""
else
    echo -e "${RED}❌ Failed to configure Git hooks${NC}"
    echo ""
    echo -e "${YELLOW}Troubleshooting:${NC}"
    echo "  1. Make sure you're in the project root directory"
    echo "  2. Ensure Git is installed and in PATH"
    echo "  3. Check that .githooks directory exists"
    echo ""
    exit 1
fi
