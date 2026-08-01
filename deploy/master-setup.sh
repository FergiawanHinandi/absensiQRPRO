#!/bin/bash
# =============================================================================
# AbsensiQRPro - Master Setup Script
# Script terpadu untuk semua kebutuhan deployment
# Usage: ./deploy/master-setup.sh [command]
# =============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Change to project root
cd "$PROJECT_ROOT"

# Function to display banner
show_banner() {
    clear
    echo -e "${CYAN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║                                                              ║${NC}"
    echo -e "${CYAN}║        🚀 AbsensiQRPro - Master Setup Script 🚀             ║${NC}"
    echo -e "${CYAN}║                                                              ║${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo
}

# Function to display menu
show_menu() {
    echo -e "${BLUE}Available Commands:${NC}"
    echo
    echo -e "  ${GREEN}1)${NC} Setup Wizard        - Interactive setup for .env.production"
    echo -e "  ${GREEN}2)${NC} Generate Keystore   - Create Android signing keystore"
    echo -e "  ${GREEN}3)${NC} Setup Server        - Install & configure production server"
    echo -e "  ${GREEN}4)${NC} Deploy              - Deploy application to server"
    echo -e "  ${GREEN}5)${NC} Run Load Test       - Execute load testing"
    echo -e "  ${GREEN}6)${NC} Run Security Scan   - Execute security scanning"
    echo -e "  ${GREEN}7)${NC} Verify Deployment   - Post-deployment verification"
    echo -e "  ${GREEN}8)${NC} Health Check        - Run system health check"
    echo -e "  ${GREEN}9)${NC} View Checklist      - View deployment checklist"
    echo -e "  ${GREEN}10)${NC} View DR Runbook     - View disaster recovery guide"
    echo
    echo -e "  ${GREEN}h)${NC} Help                - Show this menu"
    echo -e "  ${GREEN}q)${NC} Quit                - Exit script"
    echo
}

# Function to run setup wizard
run_setup_wizard() {
    echo -e "${GREEN}[STEP 1]${NC} Running Setup Wizard..."
    bash "$SCRIPT_DIR/setup-wizard.sh"
}

# Function to generate keystore
generate_keystore() {
    echo -e "${GREEN}[STEP 2]${NC} Generating Android Keystore..."
    bash "$SCRIPT_DIR/generate-keystore.sh"
}

# Function to setup server
setup_server() {
    echo -e "${GREEN}[STEP 3]${NC} Setting up Production Server..."
    
    if [ ! -f ".env.production" ]; then
        echo -e "${RED}[ERROR]${NC} .env.production not found!"
        echo -e "${YELLOW}[INFO]${NC}  Run setup wizard first: ./deploy/master-setup.sh 1"
        return 1
    fi
    
    # Get domain from .env.production
    DOMAIN=$(grep "^APP_URL=" .env.production | cut -d'=' -f2 | sed 's|https://||')
    DEPLOY_USER=$(whoami)
    
    read -p "$(echo -e "${BLUE}Deploy user on server: ${NC}")" DEPLOY_USER
    DEPLOY_USER=${DEPLOY_USER:-$(whoami)}
    
    echo -e "${YELLOW}[INFO]${NC} Setting up server for domain: $DOMAIN"
    echo -e "${YELLOW}[INFO]${NC} Deploy user: $DEPLOY_USER"
    echo
    read -p "Continue? (y/N): " -n 1 -r
    echo
    
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        bash "$SCRIPT_DIR/setup-server.sh" "$DOMAIN" "$DEPLOY_USER"
    else
        echo "Aborted."
    fi
}

# Function to deploy
deploy_application() {
    echo -e "${GREEN}[STEP 4]${NC} Deploying Application..."
    
    read -p "$(echo -e "${BLUE}Deploy to: (1) Local (2) Remote server? ${NC}")" DEPLOY_MODE
    
    case $DEPLOY_MODE in
        1)
            bash "$SCRIPT_DIR/deploy-production.sh"
            ;;
        2)
            bash "$SCRIPT_DIR/deployment-day.sh"
            ;;
        *)
            echo "Invalid option."
            ;;
    esac
}

# Function to run load test
run_load_test() {
    echo -e "${GREEN}[STEP 5]${NC} Running Load Test..."
    
    if [ ! -d "tests/load-test" ]; then
        echo -e "${RED}[ERROR]${NC} Load test directory not found!"
        return 1
    fi
    
    # Get target URL
    read -p "$(echo -e "${BLUE}Target URL (default: http://localhost:8000): ${NC}")" TARGET_URL
    TARGET_URL=${TARGET_URL:-http://localhost:8000}
    
    echo -e "${YELLOW}[INFO]${NC} Running load test against: $TARGET_URL"
    echo
    
    cd tests/load-test
    bash run.sh "$TARGET_URL"
    cd "$PROJECT_ROOT"
}

# Function to run security scan
run_security_scan() {
    echo -e "${GREEN}[STEP 6]${NC} Running Security Scan..."
    
    if [ ! -d "tests/security" ]; then
        echo -e "${RED}[ERROR]${NC} Security test directory not found!"
        return 1
    fi
    
    # Get target URL
    read -p "$(echo -e "${BLUE}Target URL (default: http://localhost:8000): ${NC}")" TARGET_URL
    TARGET_URL=${TARGET_URL:-http://localhost:8000}
    
    echo -e "${YELLOW}[INFO]${NC} Running security scan against: $TARGET_URL"
    echo
    
    cd tests/security
    bash security-scan.sh "$TARGET_URL"
    cd "$PROJECT_ROOT"
}

# Function to verify deployment
verify_deployment() {
    echo -e "${GREEN}[STEP 7]${NC} Verifying Deployment..."
    
    if [ ! -f ".env.production" ]; then
        echo -e "${RED}[ERROR]${NC} .env.production not found!"
        return 1
    fi
    
    # Get domain from .env.production
    DOMAIN=$(grep "^APP_URL=" .env.production | cut -d'=' -f2 | sed 's|https://||')
    
    echo -e "${YELLOW}[INFO]${NC} Verifying deployment for: $DOMAIN"
    echo
    
    # Health check
    echo -e "${BLUE}[1/4]${NC} Health Check..."
    HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "https://$DOMAIN/health" 2>/dev/null || echo "000")
    if [ "$HTTP_STATUS" = "200" ]; then
        echo -e "${GREEN}  ✅ Health check passed${NC}"
    else
        echo -e "${RED}  ❌ Health check failed (HTTP $HTTP_STATUS)${NC}"
    fi
    
    # API check
    echo -e "${BLUE}[2/4]${NC} API Check..."
    HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "https://$DOMAIN/api/v1/health" 2>/dev/null || echo "000")
    if [ "$HTTP_STATUS" = "200" ] || [ "$HTTP_STATUS" = "401" ]; then
        echo -e "${GREEN}  ✅ API endpoint accessible${NC}"
    else
        echo -e "${RED}  ❌ API endpoint not accessible (HTTP $HTTP_STATUS)${NC}"
    fi
    
    # SSL check
    echo -e "${BLUE}[3/4]${NC} SSL Certificate Check..."
    SSL_EXPIRY=$(echo | openssl s_client -connect "$DOMAIN:443" -servername "$DOMAIN" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
    if [ -n "$SSL_EXPIRY" ]; then
        echo -e "${GREEN}  ✅ SSL certificate valid until: $SSL_EXPIRY${NC}"
    else
        echo -e "${RED}  ❌ SSL certificate check failed${NC}"
    fi
    
    # DNS check
    echo -e "${BLUE}[4/4]${NC} DNS Resolution Check..."
    DNS_IP=$(dig +short "$DOMAIN" 2>/dev/null | head -1)
    if [ -n "$DNS_IP" ]; then
        echo -e "${GREEN}  ✅ DNS resolves to: $DNS_IP${NC}"
    else
        echo -e "${RED}  ❌ DNS resolution failed${NC}"
    fi
    
    echo
    echo -e "${BLUE}Verification completed!${NC}"
}

# Function to run health check
run_health_check() {
    echo -e "${GREEN}[STEP 8]${NC} Running Health Check..."
    
    if [ -f "backend/artisan" ]; then
        cd backend
        php artisan health:check
        cd "$PROJECT_ROOT"
    else
        echo -e "${RED}[ERROR]${NC} Laravel artisan not found!"
        return 1
    fi
}

# Function to view checklist
view_checklist() {
    echo -e "${GREEN}[STEP 9]${NC} Viewing Deployment Checklist..."
    
    if [ -f "deploy/FINAL_CHECKLIST.md" ]; then
        less "deploy/FINAL_CHECKLIST.md"
    else
        echo -e "${RED}[ERROR]${NC} FINAL_CHECKLIST.md not found!"
    fi
}

# Function to view DR runbook
view_dr_runbook() {
    echo -e "${GREEN}[STEP 10]${NC} Viewing DR Runbook..."
    
    if [ -f "docs/DR_RUNBOOK.md" ]; then
        less "docs/DR_RUNBOOK.md"
    else
        echo -e "${RED}[ERROR]${NC} DR_RUNBOOK.md not found!"
    fi
}

# Main script
show_banner

# Check for command line argument
if [ -n "$1" ]; then
    case $1 in
        1|setup) run_setup_wizard ;;
        2|keystore) generate_keystore ;;
        3|server) setup_server ;;
        4|deploy) deploy_application ;;
        5|loadtest) run_load_test ;;
        6|security) run_security_scan ;;
        7|verify) verify_deployment ;;
        8|health) run_health_check ;;
        9|checklist) view_checklist ;;
        10|dr) view_dr_runbook ;;
        *)
            echo -e "${RED}[ERROR]${NC} Unknown command: $1"
            echo
            show_menu
            ;;
    esac
else
    # Interactive mode
    while true; do
        show_banner
        show_menu
        
        read -p "$(echo -e "${BLUE}Select command (1-10, h, q): ${NC}")" choice
        
        case $choice in
            1) run_setup_wizard ;;
            2) generate_keystore ;;
            3) setup_server ;;
            4) deploy_application ;;
            5) run_load_test ;;
            6) run_security_scan ;;
            7) verify_deployment ;;
            8) run_health_check ;;
            9) view_checklist ;;
            10) view_dr_runbook ;;
            h|H) show_menu ;;
            q|Q) 
                echo -e "${GREEN}Goodbye! 👋${NC}"
                exit 0
                ;;
            *)
                echo -e "${RED}Invalid option. Please try again.${NC}"
                ;;
        esac
        
        echo
        read -p "Press Enter to continue..."
    done
fi
