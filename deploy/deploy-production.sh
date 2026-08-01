#!/bin/bash
# =============================================================================
# Deployment Script untuk AbsensiQRPro Production
# Usage: ./deploy/deploy-production.sh [--rollback]
# =============================================================================

set -e
set -o pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Configuration
APP_DIR="/var/www/absensiqrpro"
BACKUP_DIR="/tmp/absensiqrpro-backup"
HEALTH_CHECK_URL="http://localhost/health"
HEALTH_CHECK_RETRIES=5
HEALTH_CHECK_DELAY=3

# Functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

cleanup() {
    if [ -d "$BACKUP_DIR" ]; then
        rm -rf "$BACKUP_DIR"
    fi
}

trap cleanup EXIT

# Parse arguments
ROLLBACK=false
for arg in "$@"; do
    case $arg in
        --rollback)
            ROLLBACK=true
            ;;
    esac
done

# Rollback function
rollback() {
    log_error "Rolling back deployment..."
    
    cd "$APP_DIR"
    
    if [ -f /tmp/last_deployed_commit ]; then
        LAST_COMMIT=$(cat /tmp/last_deployed_commit)
        log_info "Rolling back to commit: $LAST_COMMIT"
        
        git reset --hard "$LAST_COMMIT"
        composer install --no-dev --optimize-autoloader --no-interaction
        php artisan migrate:rollback --force 2>/dev/null || true
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        
        sudo supervisorctl restart absensiqrpro-worker:* 2>/dev/null || true
        
        log_warn "Rollback completed. Please investigate the issue."
    else
        log_error "No backup commit found. Manual intervention required."
    fi
    
    exit 1
}

# Main deployment
deploy() {
    log_info "=========================================="
    log_info "  AbsensiQRPro Production Deployment"
    log_info "=========================================="
    
    # Pre-deployment checks
    log_info "Running pre-deployment checks..."
    
    if [ ! -d "$APP_DIR" ]; then
        log_error "Application directory not found: $APP_DIR"
        exit 1
    fi
    
    cd "$APP_DIR"
    
    # Check if .env exists
    if [ ! -f .env ]; then
        log_error ".env file not found in $APP_DIR"
        exit 1
    fi
    
    # Check if database is accessible
    if ! php artisan db:monitor --once 2>/dev/null; then
        log_warn "Database connection check failed. Proceeding anyway..."
    fi
    
    # Save current commit for rollback
    git rev-parse HEAD > /tmp/last_deployed_commit
    log_info "Current commit saved for rollback: $(cat /tmp/last_deployed_commit)"
    
    # Step 1: Pull latest code
    log_info "Step 1/9: Pulling latest code..."
    git fetch origin main
    git reset --hard origin/main
    
    # Step 2: Install dependencies
    log_info "Step 2/9: Installing dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
    
    # Step 3: Run migrations
    log_info "Step 3/9: Running migrations..."
    php artisan migrate --force --no-interaction
    
    # Step 4: Clear and cache configuration
    log_info "Step 4/9: Caching configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    php artisan optimize
    
    # Step 5: Run maintenance commands
    log_info "Step 5/9: Running maintenance commands..."
    php artisan icons:cache 2>/dev/null || true
    php artisan storage:link 2>/dev/null || true
    
    # Step 6: Restart queue workers
    log_info "Step 6/9: Restarting queue workers..."
    php artisan queue:restart
    
    # Step 7: Restart Horizon (if installed)
    log_info "Step 7/9: Restarting Horizon..."
    php artisan horizon:terminate 2>/dev/null || true
    
    # Step 8: Restart Supervisor
    log_info "Step 8/9: Restarting Supervisor..."
    sudo supervisorctl reread 2>/dev/null || true
    sudo supervisorctl update 2>/dev/null || true
    sudo supervisorctl restart absensiqrpro-worker:* 2>/dev/null || true
    
    # Step 9: Health check
    log_info "Step 9/9: Running health check..."
    
    HEALTH_PASSED=false
    for i in $(seq 1 $HEALTH_CHECK_RETRIES); do
        sleep $HEALTH_CHECK_DELAY
        
        HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$HEALTH_CHECK_URL" 2>/dev/null || echo "000")
        
        if [ "$HTTP_STATUS" = "200" ]; then
            HEALTH_PASSED=true
            log_info "Health check passed on attempt $i"
            break
        else
            log_warn "Health check attempt $i failed (HTTP $HTTP_STATUS)"
        fi
    done
    
    if [ "$HEALTH_PASSED" = true ]; then
        log_info "=========================================="
        log_info "  ✅ Deployment completed successfully!"
        log_info "=========================================="
        
        # Log deployment
        echo "$(date '+%Y-%m-%d %H:%M:%S') - Deployment successful - Commit: $(git rev-parse HEAD)" >> /var/log/absensiqrpro-deployments.log
        
        return 0
    else
        log_error "Health check failed after $HEALTH_CHECK_RETRIES attempts"
        rollback
    fi
}

# Execute
if [ "$ROLLBACK" = true ]; then
    rollback
else
    deploy
fi
