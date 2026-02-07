#!/bin/bash

# Multi-Tenant Restore Validation Script
# Usage: ./scripts/validate_restore.sh <backup_file> <school_id> [--report]

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# Function to show usage
show_usage() {
    echo "Usage: $0 <backup_file> <school_id> [--report]"
    echo ""
    echo "Arguments:"
    echo "  backup_file    Path to SQL backup file"
    echo "  school_id      Target school ID for validation"
    echo "  --report       Generate detailed validation report"
    echo ""
    echo "Examples:"
    echo "  $0 /path/to/backup.sql 123"
    echo "  $0 backup_20240101.sql 456 --report"
    exit 1
}

# Validate arguments
if [ $# -lt 2 ]; then
    print_status $RED "❌ Error: Missing required arguments"
    show_usage
fi

BACKUP_FILE="$1"
SCHOOL_ID="$2"
GENERATE_REPORT=""

if [ "$3" = "--report" ]; then
    GENERATE_REPORT="--report"
fi

# Validate school ID is numeric
if ! [[ "$SCHOOL_ID" =~ ^[0-9]+$ ]]; then
    print_status $RED "❌ Error: School ID must be a positive integer"
    exit 1
fi

# Check if backup file exists
if [ ! -f "$BACKUP_FILE" ]; then
    print_status $RED "❌ Error: Backup file not found: $BACKUP_FILE"
    exit 1
fi

# Check if backup file is readable
if [ ! -r "$BACKUP_FILE" ]; then
    print_status $RED "❌ Error: Backup file is not readable: $BACKUP_FILE"
    exit 1
fi

# Get file size for reporting
FILE_SIZE=$(stat -f%z "$BACKUP_FILE" 2>/dev/null || stat -c%s "$BACKUP_FILE" 2>/dev/null)
if [ $? -ne 0 ]; then
    FILE_SIZE="unknown"
fi

print_status $BLUE "🔍 Starting Multi-Tenant Restore Validation"
print_status $BLUE "=========================================="
echo ""
print_status $BLUE "📁 Backup File: $BACKUP_FILE"
print_status $BLUE "📏 File Size: $FILE_SIZE bytes"
print_status $BLUE "🏫 Target School ID: $SCHOOL_ID"
echo ""

# Change to Laravel project directory
cd "$(dirname "$0")/.." || exit 1

# Run Laravel validation command
print_status $YELLOW "⏳ Running validation checks..."

VALIDATION_OUTPUT=$(php artisan restore:validate-multi-tenant "$BACKUP_FILE" "$SCHOOL_ID" $GENERATE_REPORT 2>&1)
VALIDATION_EXIT_CODE=$?

echo "$VALIDATION_OUTPUT"

# Check validation result
if [ $VALIDATION_EXIT_CODE -eq 0 ]; then
    echo ""
    print_status $GREEN "✅ Validation PASSED - Backup is safe for restore"
    
    # Additional safety checks
    print_status $BLUE "🔍 Performing additional safety checks..."
    
    # Check for potential SQL injection patterns
    if grep -qi "DROP\|DELETE\|TRUNCATE\|UPDATE.*SET.*school_id" "$BACKUP_FILE"; then
        print_status $YELLOW "⚠️  Warning: Backup contains potentially dangerous SQL operations"
        print_status $YELLOW "   Please review the backup file manually before proceeding"
    fi
    
    # Check for cross-school data
    if grep -qi "school_id.*[0-9]" "$BACKUP_FILE"; then
        OTHER_SCHOOLS=$(grep -o "school_id.*[0-9]*" "$BACKUP_FILE" | grep -v "school_id.*$SCHOOL_ID" | sort | uniq | wc -l)
        if [ "$OTHER_SCHOOLS" -gt 0 ]; then
            print_status $YELLOW "⚠️  Warning: Backup contains data from other schools"
            print_status $YELLOW "   This may indicate cross-contamination in the backup"
        fi
    fi
    
    print_status $GREEN "🎉 Backup validation completed successfully"
    
else
    echo ""
    print_status $RED "❌ Validation FAILED - Backup is NOT safe for restore"
    print_status $RED "🚨 DO NOT proceed with restore until all issues are resolved"
    
    # Suggest next steps
    echo ""
    print_status $BLUE "💡 Suggested next steps:"
    echo "   1. Review the validation errors above"
    echo "   2. Fix the issues in the backup file"
    echo "   3. Re-run this validation script"
    echo "   4. Test restore in staging environment"
    echo "   5. Contact support if issues persist"
fi

echo ""
print_status $BLUE "📋 Validation Summary:"
echo "   • Exit Code: $VALIDATION_EXIT_CODE"
echo "   • Timestamp: $(date)"
echo "   • User: $(whoami)"
echo "   • Host: $(hostname)"

# Exit with validation result
exit $VALIDATION_EXIT_CODE
