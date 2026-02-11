#!/bin/bash

###############################################################################
# AbsensiQRPro - Backup System Setup Script
###############################################################################
#
# This script helps you configure the Spatie Backup system
#
###############################################################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_header() {
    echo -e "${BLUE}==================================="
    echo -e "$1"
    echo -e "===================================${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

###############################################################################
# Main Script
###############################################################################

clear
print_header "AbsensiQRPro Backup System Setup"
echo ""

###############################################################################
# Step 1: Generate Encryption Key
###############################################################################
print_info "[1/5] Generating backup encryption key..."
echo ""

ENCRYPTION_KEY="base64:$(openssl rand -base64 32)"
print_success "Encryption key generated"
echo ""
echo "Add this to your .env file:"
echo ""
echo -e "${GREEN}BACKUP_ENCRYPTION_KEY=\"$ENCRYPTION_KEY\"${NC}"
echo ""
read -p "Press Enter to continue..."
echo ""

###############################################################################
# Step 2: S3 Configuration
###############################################################################
print_info "[2/5] S3 Bucket Configuration"
echo ""

read -p "Enter S3 bucket name (e.g., absensi-backups-production): " S3_BUCKET
read -p "Enter AWS region (default: ap-southeast-1): " AWS_REGION
AWS_REGION=${AWS_REGION:-ap-southeast-1}
read -p "Enter AWS Access Key ID: " AWS_ACCESS_KEY
read -s -p "Enter AWS Secret Access Key: " AWS_SECRET_KEY
echo ""
echo ""

print_success "S3 configuration collected"
echo ""
echo "Add these to your .env file:"
echo ""
echo -e "${GREEN}BACKUP_AWS_BUCKET=$S3_BUCKET"
echo "BACKUP_AWS_DEFAULT_REGION=$AWS_REGION"
echo "BACKUP_AWS_ACCESS_KEY_ID=$AWS_ACCESS_KEY"
echo "BACKUP_AWS_SECRET_ACCESS_KEY=$AWS_SECRET_KEY${NC}"
echo ""
read -p "Press Enter to continue..."
echo ""

###############################################################################
# Step 3: Notification Configuration
###############################################################################
print_info "[3/5] Notification Configuration"
echo ""

read -p "Enter notification email address: " NOTIFICATION_EMAIL
read -p "Enter Slack webhook URL (optional, press Enter to skip): " SLACK_WEBHOOK

print_success "Notification configuration collected"
echo ""
echo "Add these to your .env file:"
echo ""
echo -e "${GREEN}BACKUP_NOTIFICATION_EMAIL=$NOTIFICATION_EMAIL"
if [ ! -z "$SLACK_WEBHOOK" ]; then
    echo "BACKUP_SLACK_WEBHOOK_URL=$SLACK_WEBHOOK"
    echo "BACKUP_SLACK_CHANNEL=#alerts"
fi
echo "${NC}"
read -p "Press Enter to continue..."
echo ""

###############################################################################
# Step 4: Create S3 Bucket
###############################################################################
print_info "[4/5] Creating S3 Bucket..."
echo ""

print_warning "Make sure AWS CLI is installed and configured"
read -p "Create S3 bucket now? (y/N) " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    # Create bucket
    if aws s3 mb "s3://$S3_BUCKET" --region "$AWS_REGION" 2>/dev/null; then
        print_success "S3 bucket created"
    else
        print_warning "Bucket might already exist or creation failed"
    fi
    
    # Enable versioning
    print_info "Enabling versioning..."
    aws s3api put-bucket-versioning \
        --bucket "$S3_BUCKET" \
        --versioning-configuration Status=Enabled
    print_success "Versioning enabled"
    
    # Enable encryption
    print_info "Enabling server-side encryption..."
    aws s3api put-bucket-encryption \
        --bucket "$S3_BUCKET" \
        --server-side-encryption-configuration '{
            "Rules": [{
                "ApplyServerSideEncryptionByDefault": {
                    "SSEAlgorithm": "AES256"
                }
            }]
        }'
    print_success "Encryption enabled"
    
    # Block public access
    print_info "Blocking public access..."
    aws s3api put-public-access-block \
        --bucket "$S3_BUCKET" \
        --public-access-block-configuration \
            "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true"
    print_success "Public access blocked"
else
    print_warning "Skipping S3 bucket creation"
    print_info "You can create it manually later with:"
    echo "  aws s3 mb s3://$S3_BUCKET --region $AWS_REGION"
fi
echo ""

###############################################################################
# Step 5: Test Backup
###############################################################################
print_info "[5/5] Testing Backup System"
echo ""

read -p "Run test backup now? (y/N) " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    print_info "Running test backup (database only)..."
    echo ""
    
    cd "$(dirname "${BASH_SOURCE[0]}")/.."
    
    if php artisan backup:run --only-db --disable-notifications; then
        print_success "Test backup completed successfully!"
        echo ""
        print_info "Checking backup..."
        php artisan backup:list
    else
        print_error "Test backup failed"
        print_info "Check the error messages above"
    fi
else
    print_warning "Skipping test backup"
    print_info "You can test manually with:"
    echo "  php artisan backup:run --only-db"
fi
echo ""

###############################################################################
# Summary
###############################################################################
print_header "Setup Complete!"
echo ""
print_success "Backup system configured successfully"
echo ""
print_info "Summary of configuration:"
echo "  • Encryption key generated"
echo "  • S3 bucket: $S3_BUCKET"
echo "  • Region: $AWS_REGION"
echo "  • Notifications: $NOTIFICATION_EMAIL"
if [ ! -z "$SLACK_WEBHOOK" ]; then
    echo "  • Slack alerts: Enabled"
fi
echo ""
print_warning "Don't forget to:"
echo "  1. Add all environment variables to .env"
echo "  2. Run: php artisan config:cache"
echo "  3. Verify cron is configured for scheduled backups"
echo "  4. Test restore procedure"
echo ""
print_info "Documentation: docs/SPATIE_BACKUP_CONFIGURATION.md"
echo ""
