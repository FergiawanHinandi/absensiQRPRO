#!/bin/bash
# Pre-Check Multi-Tenant Security Leakage
# Used to enforce "No DB::table() usage" for tenant-scoped data.

echo "🔍 AUDIT: Checking for DB::table usage on tenant tables..."

FORBIDDEN_TABLES="attendances|schedules|students|users|subscriptions|audit_logs|student_cards"

# grep options:
# -r recursive
# -n line number
# -H file name
# -E extended regex
# Exclude vendor, storage, tests (sometimes tests need raw access for preparation)

VIOLATIONS=$(grep -rnH -E "DB::table\(['\"]($FORBIDDEN_TABLES)['\"]\)" app/ | grep -v "test" | grep -v "database/migrations")

if [ -n "$VIOLATIONS" ]; then
    echo "❌ SECURITY FAILURE: Direct table access found!"
    echo "=================================================="
    echo "$VIOLATIONS"
    echo "=================================================="
    echo "FAIL: Use Eloquent Models with Global Scope instead."
    exit 1
else
    echo "✅ SECURITY PASS: No direct tenant table access detected via grep."
    exit 0
fi
