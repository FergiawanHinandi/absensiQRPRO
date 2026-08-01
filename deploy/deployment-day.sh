#!/bin/bash
# =============================================================================
# Deployment Day Script - AbsensiQRPro
# Usage: ./deploy/deployment-day.sh
# =============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Configuration
APP_DIR="/var/www/absensiqrpro"
DOMAIN="[DOMAIN_ANDA]"  # GANTI dengan domain production
DEPLOY_USER="[DEPLOY_USER]"  # GANTI dengan username deployment

echo -e "${GREEN}[INFO]${NC}=========================================="
echo -e "${GREEN}[INFO]${NC}  AbsensiQRPro Deployment Day"
echo -e "${GREEN}[INFO]${NC}=========================================="
echo -e "${GREEN}[INFO]${NC}  Domain: $DOMAIN"
echo -e "${GREEN}[INFO]${NC}  App Dir: $APP_DIR"
echo -e "${GREEN}[INFO]${NC}  Time: $(date)"
echo

# Function to check health
check_health() {
    local url=$1
    local retries=${2:-5}
    local delay=${3:-3}
    
    for i in $(seq 1 $retries); do
        HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$url" 2>/dev/null || echo "000")
        if [ "$HTTP_STATUS" = "200" ]; then
            return 0
        fi
        sleep $delay
    done
    return 1
}

# Step 1: Pre-deployment checks
echo -e "${GREEN}[STEP 1/10]${NC} Pre-deployment checks..."

# Check if we're in the right directory
if [ ! -f "composer.json" ]; then
    echo -e "${RED}[ERROR]${NC} Not in the correct directory!"
    exit 1
fi

# Check if .env exists
if [ ! -f ".env" ]; then
    echo -e "${RED}[ERROR]${NC} .env file not found!"
    exit 1
fi

echo -e "${GREEN}[INFO]${NC} Pre-deployment checks passed!"

# Step 2: Enable maintenance mode
echo -e "${GREEN}[STEP 2/10]${NC} Enabling maintenance mode..."
php artisan down --message="Deployment in progress. Please try again in a few minutes." --retry=60 --allow="[YOUR_IP]"

# Step 3: Backup current state
echo -e "${GREEN}[STEP 3/10]${NC} Creating backup..."
BACKUP_DIR="/tmp/absensiqrpro-backup-$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
git rev-parse HEAD > "$BACKUP_DIR/last_commit.txt"
cp .env "$BACKUP_DIR/.env.backup"

# Step 4: Pull latest code
echo -e "${GREEN}[STEP 4/10]${NC} Pulling latest code..."
git fetch origin main
git reset --hard origin/main

# Step 5: Install dependencies
echo -e "${GREEN}[STEP 5/10]${NC} Installing dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Step 6: Run migrations
echo -e "${GREEN}[STEP 6/10]${NC} Running migrations..."
php artisan migrate --force --no-interaction

# Step 7: Cache configuration
echo -e "${GREEN}[STEP 7/10]${NC} Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan optimize

# Step 8: Deploy frontend
echo -e "${GREEN}[STEP 8/10]${NC} Deploying frontend..."
if [ -d "frontend-web/dist" ]; then
    rm -rf public/build
    cp -r frontend-web/dist/* public/build/ 2>/dev/null || true
fi

# Step 9: Restart services
echo -e "${GREEN}[STEP 9/10]${NC} Restarting services..."
php artisan queue:restart
php artisan horizon:terminate 2>/dev/null || true
sudo supervisorctl reread 2>/dev/null || true
sudo supervisorctl update 2>/dev/null || true
sudo supervisorctl restart absensiqrpro-worker:* 2>/dev/null || true

# Step 10: Health check and disable maintenance
echo -e "${GREEN}[STEP 10/10]${NC} Running health check..."
sleep 5

if check_health "https://$DOMAIN/health" 5 3; then
    echo -e "${GREEN}[INFO]${NC} Health check passed!"
    
    # Disable maintenance mode
    php artisan up
    
    echo -e "${GREEN}[INFO]${NC}=========================================="
    echo -e "${GREEN}[INFO]${NC}  ✅ Deployment completed successfully!"
    echo -e "${GREEN}[INFO]${NC}=========================================="
    
    # Log deployment
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Deployment successful - Commit: $(git rev-parse HEAD)" >> /var/log/absensiqrpro-deployments.log
    
else
    echo -e "${RED}[ERROR]${NC} Health check failed! Rolling back..."
    
    # Rollback
    cd "$APP_DIR"
    LAST_COMMIT=$(cat "$BACKUP_DIR/last_commit.txt")
    git reset --hard "$LAST_COMMIT"
    composer install --no-dev --optimize-autoloader --no-interaction
    php artisan migrate:rollback --force 2>/dev/null || true
    php artisan config:cache
    php artisan route:cache
    sudo supervisorctl restart absensiqrpro-worker:* 2>/dev/null || true
    
    # Disable maintenance mode
    php artisan up
    
    echo -e "${RED}[ERROR]${NC}=========================================="
    echo -e "${RED}[ERROR]${NC}  ❌ Deployment failed and rolled back!"
    echo -e "${RED}[ERROR]${NC}=========================================="
    
    exit 1
fi

# Cleanup
rm -rf "$BACKUP_DIR"
