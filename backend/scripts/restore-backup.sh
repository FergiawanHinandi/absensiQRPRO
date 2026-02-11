#!/bin/bash

###############################################################################
# AbsensiQRPro - Automated Backup Restore Script
###############################################################################
#
# Usage: ./restore-backup.sh <backup-file.zip>
#
# This script automates the restoration of:
# - PostgreSQL database
# - Application files (uploads, security reports)
#
# Requirements:
# - PostgreSQL client (psql, createdb)
# - unzip
# - Backup encryption password (BACKUP_ENCRYPTION_KEY)
#
###############################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
BACKUP_FILE=$1
RESTORE_DIR="./restore-$(date +%Y%m%d-%H%M%S)"
DB_NAME="${DB_RESTORE_NAME:-absensi_db_restore}"
DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-postgres}"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

###############################################################################
# Functions
###############################################################################

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

check_requirements() {
    print_info "Checking requirements..."
    
    # Check unzip
    if ! command -v unzip &> /dev/null; then
        print_error "unzip is not installed"
        exit 1
    fi
    
    # Check PostgreSQL client
    if ! command -v psql &> /dev/null; then
        print_error "PostgreSQL client (psql) is not installed"
        exit 1
    fi
    
    # Check createdb
    if ! command -v createdb &> /dev/null; then
        print_error "createdb is not installed"
        exit 1
    fi
    
    print_success "All requirements met"
}

###############################################################################
# Main Script
###############################################################################

# Validate arguments
if [ -z "$BACKUP_FILE" ]; then
    print_error "Usage: $0 <backup-file.zip>"
    echo ""
    echo "Examples:"
    echo "  $0 absensi-backup-2026-02-09-020015.zip"
    echo "  $0 /path/to/backup.zip"
    echo ""
    echo "Environment variables:"
    echo "  DB_RESTORE_NAME  - Database name for restore (default: absensi_db_restore)"
    echo "  DB_HOST          - Database host (default: localhost)"
    echo "  DB_USER          - Database user (default: postgres)"
    exit 1
fi

# Check if backup file exists
if [ ! -f "$BACKUP_FILE" ]; then
    print_error "Backup file not found: $BACKUP_FILE"
    exit 1
fi

# Print header
clear
print_header "AbsensiQRPro Backup Restore"
echo ""
echo "Backup file:       $BACKUP_FILE"
echo "Restore directory: $RESTORE_DIR"
echo "Database name:     $DB_NAME"
echo "Database host:     $DB_HOST"
echo "Database user:     $DB_USER"
echo "Project root:      $PROJECT_ROOT"
echo ""

# Check requirements
check_requirements
echo ""

# Confirm before proceeding
read -p "Continue with restore? (y/N) " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    print_warning "Restore cancelled"
    exit 0
fi

echo ""

###############################################################################
# Step 1: Create restore directory
###############################################################################
print_info "[1/6] Creating restore directory..."
mkdir -p "$RESTORE_DIR"
cd "$RESTORE_DIR"
print_success "Restore directory created: $RESTORE_DIR"
echo ""

###############################################################################
# Step 2: Extract backup
###############################################################################
print_info "[2/6] Extracting backup..."
print_warning "You will be prompted for the backup encryption password"
print_warning "Use the value from BACKUP_ENCRYPTION_KEY in .env"
echo ""

if unzip "../$BACKUP_FILE"; then
    print_success "Backup extracted successfully"
else
    print_error "Failed to extract backup"
    print_info "Make sure you entered the correct encryption password"
    exit 1
fi
echo ""

###############################################################################
# Step 3: Verify extracted files
###############################################################################
print_info "[3/6] Verifying extracted files..."

# Check for SQL dump
SQL_FILE=$(find . -name "*.sql" -type f | head -n 1)
if [ -z "$SQL_FILE" ]; then
    print_error "No SQL dump found in backup"
    exit 1
fi
print_success "Found SQL dump: $SQL_FILE"

# Check file size
SQL_SIZE=$(du -h "$SQL_FILE" | cut -f1)
print_info "SQL dump size: $SQL_SIZE"
echo ""

###############################################################################
# Step 4: Create/recreate database
###############################################################################
print_info "[4/6] Creating restore database..."

# Check if database exists
if psql -h "$DB_HOST" -U "$DB_USER" -lqt | cut -d \| -f 1 | grep -qw "$DB_NAME"; then
    print_warning "Database $DB_NAME already exists"
    read -p "Drop and recreate? (y/N) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        dropdb -h "$DB_HOST" -U "$DB_USER" "$DB_NAME"
        print_success "Existing database dropped"
    else
        print_warning "Using existing database"
    fi
fi

# Create database if it doesn't exist
if ! psql -h "$DB_HOST" -U "$DB_USER" -lqt | cut -d \| -f 1 | grep -qw "$DB_NAME"; then
    createdb -h "$DB_HOST" -U "$DB_USER" "$DB_NAME"
    print_success "Database created: $DB_NAME"
else
    print_info "Database already exists: $DB_NAME"
fi
echo ""

###############################################################################
# Step 5: Restore database
###############################################################################
print_info "[5/6] Restoring database..."
print_warning "This may take several minutes depending on database size..."
echo ""

START_TIME=$(date +%s)

if psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" < "$SQL_FILE" 2>&1 | tee restore.log; then
    END_TIME=$(date +%s)
    DURATION=$((END_TIME - START_TIME))
    print_success "Database restored successfully in ${DURATION}s"
    
    # Check for errors in log
    if grep -i "error" restore.log > /dev/null; then
        print_warning "Some errors occurred during restore. Check restore.log"
    fi
else
    print_error "Database restore failed"
    print_info "Check restore.log for details"
    exit 1
fi
echo ""

###############################################################################
# Step 6: Restore files
###############################################################################
print_info "[6/6] Restoring files..."

FILES_RESTORED=0

# Restore public uploads
if [ -d "storage/app/public" ]; then
    mkdir -p "$PROJECT_ROOT/storage/app/public"
    cp -r storage/app/public/* "$PROJECT_ROOT/storage/app/public/" 2>/dev/null || true
    FILES_RESTORED=$((FILES_RESTORED + 1))
    print_success "Public uploads restored"
fi

# Restore security reports
if [ -d "storage/app/security-reports" ]; then
    mkdir -p "$PROJECT_ROOT/storage/app/security-reports"
    cp -r storage/app/security-reports/* "$PROJECT_ROOT/storage/app/security-reports/" 2>/dev/null || true
    FILES_RESTORED=$((FILES_RESTORED + 1))
    print_success "Security reports restored"
fi

if [ $FILES_RESTORED -eq 0 ]; then
    print_warning "No files found to restore"
else
    print_success "$FILES_RESTORED file directories restored"
    
    # Fix permissions (Linux only)
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        print_info "Fixing file permissions..."
        chmod -R 775 "$PROJECT_ROOT/storage/app/public" 2>/dev/null || true
        chmod -R 775 "$PROJECT_ROOT/storage/app/security-reports" 2>/dev/null || true
        print_success "Permissions fixed"
    fi
fi
echo ""

###############################################################################
# Summary
###############################################################################
print_header "Restore Completed Successfully!"
echo ""
print_success "Database: $DB_NAME"
print_success "Files: $PROJECT_ROOT/storage/app/"
print_success "Restore directory: $RESTORE_DIR"
echo ""
print_info "Next steps:"
echo "  1. Update .env to use restored database:"
echo "     DB_DATABASE=$DB_NAME"
echo ""
echo "  2. Verify database content:"
echo "     php artisan tinker"
echo "     >>> DB::table('users')->count()"
echo "     >>> DB::table('schools')->count()"
echo ""
echo "  3. Run migrations status:"
echo "     php artisan migrate:status"
echo ""
echo "  4. Test application:"
echo "     php artisan serve"
echo ""
print_warning "Restore log saved to: $RESTORE_DIR/restore.log"
echo ""
