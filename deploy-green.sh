#!/bin/bash

# 🔵🟢 Blue-Green Deployment Simulation Script
# This script simulates the "Green" deployment steps.

set -e

GREEN_DIR="/var/www/absensiQRPro-GREEN"
REPO_URL="git@github.com:FergiawanHinandi/absensiQRPRO.git"
BRANCH="main"

echo "🚀 Starting Deployment to GREEN environment..."

# 1. Update Codebase
if [ -d "$GREEN_DIR" ]; then
    echo "📂 Directory exists. Pulling latest code..."
    # cd "$GREEN_DIR"
    # git fetch origin
    # git reset --hard origin/$BRANCH
else
    echo "📂 Cloning repository..."
    # git clone -b $BRANCH $REPO_URL "$GREEN_DIR"
fi

echo "✅ Code updated."

# 2. Install Dependencies
echo "📦 Installing Dependencies..."
# composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$GREEN_DIR"
echo "✅ Dependencies installed."

# 3. Optimize Config
echo "⚙️  Caching Configuration..."
# php "$GREEN_DIR/artisan" config:cache
# php "$GREEN_DIR/artisan" route:cache
# php "$GREEN_DIR/artisan" view:cache
echo "✅ Config cached."

# 4. Safe Migrations
echo "🗄️  Running Database Migrations (Force)..."
# php "$GREEN_DIR/artisan" migrate --force
echo "✅ Database migrated."

# 5. Restart Queues
echo "🔄 Restarting Queue Workers..."
# php "$GREEN_DIR/artisan" queue:restart
echo "✅ Queues signaled to restart."

# 6. Health Check
echo "🏥 Performing Health Check..."
# HEALTH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8081/api/v1/health)

# Simulate Check
HEALTH_STATUS=200

if [ "$HEALTH_STATUS" -eq 200 ]; then
    echo "✅ Health Check PASSED (200 OK)."
    echo "🚀 GREEN Environment is ready for Traffic Switch!"
else
    echo "❌ Health Check FAILED ($HEALTH_STATUS). Aborting switch."
    exit 1
fi
