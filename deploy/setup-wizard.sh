#!/bin/bash
# =============================================================================
# AbsensiQRPro - Guided Setup Wizard
# Script interaktif untuk setup production environment
# Usage: ./deploy/setup-wizard.sh
# =============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Config file
CONFIG_FILE="deploy/.setup-config"
ENV_TEMPLATE=".env.production.template"
ENV_OUTPUT=".env.production"

# Clear screen
clear

echo -e "${CYAN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║                                                              ║${NC}"
echo -e "${CYAN}║        🚀 AbsensiQRPro - Production Setup Wizard 🚀         ║${NC}"
echo -e "${CYAN}║                                                              ║${NC}"
echo -e "${CYAN}║  Script ini akan memandu Anda step-by-step untuk:           ║${NC}"
echo -e "${CYAN}║  1. Generate .env.production                                 ║${NC}"
echo -e "${CYAN}║  2. Generate Android keystore                                ║${NC}"
echo -e "${CYAN}║  3. Setup server production                                  ║${NC}"
echo -e "${CYAN}║  4. Verifikasi deployment                                    ║${NC}"
echo -e "${CYAN}║                                                              ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════════════════════╝${NC}"
echo

# Function to ask question
ask() {
    local question=$1
    local default=$2
    local result
    
    if [ -n "$default" ]; then
        read -p "$(echo -e "${BLUE}$question${NC} [$default]: ")" result
        echo "${result:-$default}"
    else
        read -p "$(echo -e "${BLUE}$question${NC}: ")" result
        echo "$result"
    fi
}

# Function to ask yes/no
ask_yes_no() {
    local question=$1
    local default=${2:-y}
    local result
    
    if [ "$default" = "y" ]; then
        read -p "$(echo -e "${BLUE}$question${NC} [Y/n]: ")" result
        result=${result:-y}
    else
        read -p "$(echo -e "${BLUE}$question${NC} [y/N]: ")" result
        result=${result:-n}
    fi
    
    [[ "$result" =~ ^[Yy]$ ]]
}

# Function to generate random string
generate_random() {
    local length=${1:-32}
    openssl rand -hex $length | head -c $length
}

# Function to generate APP_KEY
generate_app_key() {
    echo "base64:$(openssl rand -base64 32)"
}

# =============================================================================
# STEP 1: Domain & Basic Configuration
# =============================================================================
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  STEP 1/7: Domain & Basic Configuration${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo

DOMAIN=$(ask "Masukkan domain production (contoh: absensiqr.example.com)")
APP_NAME=$(ask "Nama aplikasi" "AbsensiQRPro")
APP_TIMEZONE=$(ask "Timezone" "Asia/Jakarta")

echo
echo -e "${GREEN}✅ Domain: $DOMAIN${NC}"
echo

# =============================================================================
# STEP 2: Database Configuration
# =============================================================================
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  STEP 2/7: Database Configuration${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo

DB_HOST=$(ask "Database host" "127.0.0.1")
DB_PORT=$(ask "Database port" "5432")
DB_NAME=$(ask "Database name" "absensiqrpro")
DB_USER=$(ask "Database user" "absensiqrpro_user")
DB_PASSWORD=$(ask "Database password (akan di-generate jika kosong)" "")
if [ -z "$DB_PASSWORD" ]; then
    DB_PASSWORD=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 32)
    echo -e "${YELLOW}Database password generated: $DB_PASSWORD${NC}"
    echo -e "${YELLOW}⚠️  SIMPAN PASSWORD INI DI TEMPAT YANG AMAN!${NC}"
fi

echo
echo -e "${GREEN}✅ Database: $DB_USER@$DB_HOST:$DB_PORT/$DB_NAME${NC}"
echo

# =============================================================================
# STEP 3: Redis Configuration
# =============================================================================
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  STEP 3/7: Redis Configuration${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo

REDIS_HOST=$(ask "Redis host" "127.0.0.1")
REDIS_PASSWORD=$(ask "Redis password (kosongkan jika tidak ada)" "")
REDIS_PORT=$(ask "Redis port" "6379")

USE_SENTINEL=$(ask_yes_no "Gunakan Redis Sentinel untuk High Availability?" "n")
if [ "$USE_SENTINEL" = true ]; then
    REDIS_SENTINEL="true"
    REDIS_SENTINEL_HOSTS=$(ask "Redis Sentinel hosts (format: host1:port1,host2:port2)" "127.0.0.1:26379,127.0.0.1:26380,127.0.0.1:26381")
else
    REDIS_SENTINEL="false"
    REDIS_SENTINEL_HOSTS=""
fi

echo
echo -e "${GREEN}✅ Redis: $REDIS_HOST:$REDIS_PORT (Sentinel: $REDIS_SENTINEL)${NC}"
echo

# =============================================================================
# STEP 4: Payment Gateway (Midtrans)
# =============================================================================
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  STEP 4/7: Payment Gateway (Midtrans)${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo

USE_MIDTRANS=$(ask_yes_no "Gunakan Midtrans untuk payment?" "y")
if [ "$USE_MIDTRANS" = true ]; then
    MIDTRANS_SERVER_KEY=$(ask "Midtrans Server Key (production)")
    MIDTRANS_CLIENT_KEY=$(ask "Midtrans Client Key (production)")
    MIDTRANS_MERCHANT_ID=$(ask "Midtrans Merchant ID")
    MIDTRANS_IS_PRODUCTION="true"
else
    MIDTRANS_SERVER_KEY=""
    MIDTRANS_CLIENT_KEY=""
    MIDTRANS_MERCHANT_ID=""
    MIDTRANS_IS_PRODUCTION="false"
fi

echo
echo -e "${GREEN}✅ Midtrans: $([ "$USE_MIDTRANS" = true ] && echo "Configured" || echo "Disabled")${NC}"
echo

# =============================================================================
# STEP 5: Push Notifications (Firebase)
# =============================================================================
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  STEP 5/7: Push Notifications (Firebase)${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo

FCM_SERVER_KEY=$(ask "FCM Server Key (Firebase)")
FCM_SENDER_ID=$(ask "FCM Sender ID")

echo
echo -e "${GREEN}✅ FCM: Configured${NC}"
echo

# =============================================================================
# STEP 6: Monitoring & Alerting (Optional)
# =============================================================================
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  STEP 6/7: Monitoring & Alerting (Optional)${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo

# Sentry
USE_SENTRY=$(ask_yes_no "Gunakan Sentry untuk error tracking?" "n")
if [ "$USE_SENTRY" = true ]; then
    SENTRY_DSN=$(ask "Sentry DSN")
else
    SENTRY_DSN=""
fi

# Telegram Alerts
USE_TELEGRAM=$(ask_yes_no "Gunakan Telegram untuk alerts?" "n")
if [ "$USE_TELEGRAM" = true ]; then
    TELEGRAM_BOT_TOKEN=$(ask "Telegram Bot Token")
    TELEGRAM_CHAT_ID=$(ask "Telegram Chat ID")
else
    TELEGRAM_BOT_TOKEN=""
    TELEGRAM_CHAT_ID=""
fi

# Slack Alerts
USE_SLACK=$(ask_yes_no "Gunakan Slack untuk alerts?" "n")
if [ "$USE_SLACK" = true ]; then
    SLACK_WEBHOOK_URL=$(ask "Slack Webhook URL")
else
    SLACK_WEBHOOK_URL=""
fi

echo
echo -e "${GREEN}✅ Monitoring: Sentry=$([ "$USE_SENTRY" = true ] && echo "Yes" || echo "No"), Telegram=$([ "$USE_TELEGRAM" = true ] && echo "Yes" || echo "No"), Slack=$([ "$USE_SLACK" = true ] && echo "Yes" || echo "No")${NC}"
echo

# =============================================================================
# STEP 7: Generate Configuration Files
# =============================================================================
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  STEP 7/7: Generating Configuration Files${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo

# Generate secrets
APP_KEY=$(generate_app_key)
JWT_SECRET=$(generate_random 32)
BACKUP_ENCRYPTION_KEY=$(generate_random 32)

# Create .env.production
echo -e "${BLUE}Creating .env.production...${NC}"

cat > "$ENV_OUTPUT" << EOF
# =============================================================================
# AbsensiQRPro - Production Environment Configuration
# Generated by Setup Wizard on $(date)
# =============================================================================

# Application
APP_NAME=$APP_NAME
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_VERSION=1.0.0
APP_TIMEZONE=$APP_TIMEZONE

# Logging
LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

# Database (PostgreSQL)
DB_CONNECTION=pgsql
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASSWORD
DB_SSLMODE=require

# Redis
REDIS_HOST=$REDIS_HOST
REDIS_PASSWORD=$REDIS_PASSWORD
REDIS_PORT=$REDIS_PORT
REDIS_CLIENT=predis

# Redis Sentinel
REDIS_SENTINEL=$REDIS_SENTINEL
REDIS_SENTINEL_HOSTS=$REDIS_SENTINEL_HOSTS

# Cache
BROADCAST_DRIVER=log
CACHE_DRIVER=redis
CACHE_PREFIX=absensiqrpro_cache

# Session
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict

# Filesystem
FILESYSTEM_DISK=local

# Mail
MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="noreply@$DOMAIN"
MAIL_FROM_NAME="$APP_NAME"

# Frontend URL (for CORS)
FRONTEND_URL=https://$DOMAIN

# Sanctum
SANCTUM_STATEFUL_DOMAINS=$DOMAIN

# Rate Limiting
RATE_LIMIT_LOGIN=5
RATE_LIMIT_QR_SCAN=30
RATE_LIMIT_EXPORT=10
RATE_LIMIT_REGISTER=3
RATE_LIMIT_PASSWORD_RESET=3

# QR Code Settings
QR_EXPIRY_MINUTES=10
QR_SIGNATURE_EXPIRATION_SECONDS=10

# Midtrans Payment Gateway
MIDTRANS_SERVER_KEY=$MIDTRANS_SERVER_KEY
MIDTRANS_CLIENT_KEY=$MIDTRANS_CLIENT_KEY
MIDTRANS_MERCHANT_ID=$MIDTRANS_MERCHANT_ID
MIDTRANS_IS_PRODUCTION=$MIDTRANS_IS_PRODUCTION
MIDTRANS_WEBHOOK_URL=https://$DOMAIN/api/v1/webhooks/payment

# Firebase Cloud Messaging
FCM_SERVER_KEY=$FCM_SERVER_KEY
FCM_SENDER_ID=$FCM_SENDER_ID

# Sentry Error Tracking
SENTRY_LARAVEL_DSN=$SENTRY_DSN
SENTRY_TRACES_SAMPLE_RATE=0.1

# Backup Configuration
BACKUP_ENCRYPTION_KEY=$BACKUP_ENCRYPTION_KEY
BACKUP_LOCAL_PATH=/backups/absensiqrpro

# Telegram Alerts
TELEGRAM_BOT_TOKEN=$TELEGRAM_BOT_TOKEN
TELEGRAM_CHAT_ID=$TELEGRAM_CHAT_ID

# Slack Alerts
SLACK_SECURITY_WEBHOOK_URL=$SLACK_WEBHOOK_URL
SLACK_CHANNEL=#security-alerts

# Monitoring
MONITORING_ENABLED=true
SLOW_QUERY_THRESHOLD=1000
QUEUE_MONITORING_ENABLED=true

# Laravel Reverb (WebSocket)
REVERB_APP_KEY=
REVERB_APP_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=https

# Vite (Frontend Build)
VITE_API_URL=https://$DOMAIN/api/v1
VITE_REVERB_APP_KEY=
VITE_REVERB_HOST=$DOMAIN
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_MIDTRANS_CLIENT_KEY=$MIDTRANS_CLIENT_KEY
VITE_APP_NAME=$APP_NAME
EOF

chmod 600 "$ENV_OUTPUT"

echo -e "${GREEN}✅ .env.production created successfully!${NC}"
echo

# Save config for reference
mkdir -p deploy
cat > "$CONFIG_FILE" << EOF
# Setup Configuration - $(date)
DOMAIN=$DOMAIN
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
REDIS_HOST=$REDIS_HOST
REDIS_PORT=$REDIS_PORT
REDIS_SENTINEL=$REDIS_SENTINEL
EOF

echo -e "${GREEN}✅ Configuration saved to $CONFIG_FILE${NC}"
echo

# =============================================================================
# SUMMARY
# =============================================================================
echo -e "${CYAN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║                    SETUP WIZARD COMPLETE!                    ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════════════════════╝${NC}"
echo
echo -e "${GREEN}📋 Ringkasan Konfigurasi:${NC}"
echo -e "${GREEN}  Domain: https://$DOMAIN${NC}"
echo -e "${GREEN}  Database: $DB_USER@$DB_HOST:$DB_PORT/$DB_NAME${NC}"
echo -e "${GREEN}  Redis: $REDIS_HOST:$REDIS_PORT${NC}"
echo -e "${GREEN}  Midtrans: $([ "$USE_MIDTRANS" = true ] && echo "Configured" || echo "Disabled")${NC}"
echo -e "${GREEN}  Sentry: $([ "$USE_SENTRY" = true ] && echo "Configured" || echo "Disabled")${NC}"
echo -e "${GREEN}  Telegram: $([ "$USE_TELEGRAM" = true ] && echo "Configured" || echo "Disabled")${NC}"
echo
echo -e "${YELLOW}⚠️  PENTING:${NC}"
echo -e "${YELLOW}  1. File .env.production sudah dibuat${NC}"
echo -e "${YELLOW}  2. Database password: $DB_PASSWORD${NC}"
echo -e "${YELLOW}  3. SIMPAN PASSWORD INI DI TEMPAT YANG AMAN!${NC}"
echo -e "${YELLOW}  4. JANGAN commit .env.production ke git!${NC}"
echo
echo -e "${BLUE}📋 Langkah Selanjutnya:${NC}"
echo -e "${BLUE}  1. Jalankan: ./deploy/setup-server.sh $DOMAIN <deploy_user>${NC}"
echo -e "${BLUE}  2. Generate Android keystore: ./deploy/generate-keystore.sh${NC}"
echo -e "${BLUE}  3. Ikuti FINAL_CHECKLIST.md sebelum deploy${NC}"
echo
