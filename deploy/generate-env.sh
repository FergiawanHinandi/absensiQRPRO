#!/bin/bash
# =============================================================================
# Script untuk generate .env production dari template
# Usage: ./deploy/generate-env.sh [domain]
# =============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Configuration
TEMPLATE_FILE=".env.production.template"
OUTPUT_FILE=".env.production"

# Check if domain is provided
if [ -z "$1" ]; then
    echo -e "${RED}[ERROR]${NC} Domain tidak diberikan!"
    echo "Usage: ./deploy/generate-env.sh [domain]"
    echo "Example: ./deploy/generate-env.sh absensiqr.example.com"
    exit 1
fi

DOMAIN=$1

# Check if template exists
if [ ! -f "$TEMPLATE_FILE" ]; then
    echo -e "${RED}[ERROR]${NC} Template file tidak ditemukan: $TEMPLATE_FILE"
    exit 1
fi

# Check if output already exists
if [ -f "$OUTPUT_FILE" ]; then
    echo -e "${YELLOW}[WARNING]${NC} File $OUTPUT_FILE sudah ada!"
    read -p "Apakah mau overwrite? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Dibatalkan."
        exit 1
    fi
    # Backup existing
    cp "$OUTPUT_FILE" "$OUTPUT_FILE.backup.$(date +%Y%m%d_%H%M%S)"
    echo -e "${GREEN}[INFO]${NC} Backup dibuat: $OUTPUT_FILE.backup.*"
fi

echo -e "${GREEN}[INFO]${NC} Generating .env.production untuk domain: $DOMAIN"

# Generate APP_KEY
echo -e "${GREEN}[INFO]${NC} Generating APP_KEY..."
if command -v php &> /dev/null; then
    APP_KEY=$(php -r "echo 'base64:'.base64_encode(random_bytes(32));")
else
    # Fallback: generate with openssl
    APP_KEY="base64:$(openssl rand -base64 32)"
fi

# Generate random passwords
DB_PASSWORD=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 32)
BACKUP_ENCRYPTION_KEY=$(openssl rand -hex 32)

# Create .env.production from template
sed \
    -e "s|# AKAN DIGENERATE OTOMATIS|$APP_KEY|g" \
    -e "s|https://\[DOMAIN_ANDA\]|https://$DOMAIN|g" \
    -e "s|\[DOMAIN_ANDA\]|$DOMAIN|g" \
    -e "s|# GANTI DENGAN PASSWORD PRODUCTION|$DB_PASSWORD|g" \
    -e "s|# GANTI DENGAN RANDOM 32 CHARACTER STRING|$BACKUP_ENCRYPTION_KEY|g" \
    "$TEMPLATE_FILE" > "$OUTPUT_FILE"

# Set permissions
chmod 600 "$OUTPUT_FILE"

echo -e "${GREEN}[INFO]${NC}=========================================="
echo -e "${GREEN}[INFO]${NC}  .env.production berhasil dibuat!"
echo -e "${GREEN}[INFO]${NC}=========================================="
echo
echo -e "${YELLOW}[IMPORTANT]${NC} Hal yang perlu dilakukan:"
echo
echo "1. Edit .env.production dan isi:"
echo "   - MIDTRANS_SERVER_KEY"
echo "   - MIDTRANS_CLIENT_KEY"
echo "   - MIDTRANS_MERCHANT_ID"
echo "   - FCM_SERVER_KEY"
echo "   - FCM_SENDER_ID"
echo "   - SENTRY_LARAVEL_DSN (opsional)"
echo "   - BACKUP_AWS_* (opsional)"
echo "   - TELEGRAM_* (opsional)"
echo "   - SLACK_* (opsional)"
echo
echo "2. JANGAN commit file .env.production ke git!"
echo
echo "3. Copy ke server production:"
echo "   scp $OUTPUT_FILE user@server:/var/www/absensiqrpro/.env"
echo
