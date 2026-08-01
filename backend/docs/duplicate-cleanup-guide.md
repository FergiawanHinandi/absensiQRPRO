# Duplicate Attendance Cleanup Guide

## Overview

This guide provides step-by-step instructions for cleaning up duplicate attendance records in the AbsensiQR Pro system using the automated cleanup script.

## Prerequisites

- Database backup completed
- Staging environment tested
- Team notified of maintenance window
- Read access to production database

## Cleanup Script

### Command Syntax

```bash
php artisan attendance:cleanup-duplicates [options]
```

### Options

| Option | Description |
|--------|-------------|
| `--school=ID` | Filter cleanup by specific school_id |
| `--dry-run` | Preview changes without executing (recommended first) |
| `--force` | Skip confirmation prompt |

## Step-by-Step Process

### Phase 1: Analysis (Read-Only)

#### Step 1: Identify Duplicates

```bash
# Identify all duplicates
php artisan attendance:identify-duplicates

# Export to CSV for review
php artisan attendance:identify-duplicates --export=csv

# Filter by specific school
php artisan attendance:identify-duplicates --school=1
```

**Expected Output:**
```
🔍 Scanning for duplicate attendance records...

❌ Found 15 duplicate groups affecting 42 records
📊 Records to clean: 27

📋 Breakdown by School:
+----------+------------------+---------------+------+--------+
| School ID| Duplicate Groups | Total Records | Keep | Delete |
+----------+------------------+---------------+------+--------+
| 1        | 10               | 28            | 10   | 18     |
| 2        | 5                | 14            | 5    | 9      |
+----------+------------------+---------------+------+--------+
```

#### Step 2: Review Analysis

1. Check the exported CSV file in `storage/app/`
2. Review duplicate groups for patterns
3. Identify any high-risk duplicates (conflicting states)
4. Document findings

### Phase 2: Dry Run (No Changes)

#### Step 3: Run Dry Run

```bash
# Preview cleanup without making changes
php artisan attendance:cleanup-duplicates --dry-run
```

**Expected Output:**
```
🧹 Duplicate Attendance Cleanup Script

🔍 DRY RUN MODE - No changes will be made

Step 1: Identifying duplicate records...
❌ Found 15 duplicate groups
📊 Total records: 42
📊 Records to keep: 15
📊 Records to delete: 27

Processing: Student 123, Schedule 456, Date 2026-03-01
  ✅ Keeping: ID 1001 (created: 2026-03-01 08:00:00)
  ❌ Deleting: 1 duplicate(s) - IDs: 1002

...

═══════════════════════════════════════
           CLEANUP SUMMARY
═══════════════════════════════════════

🔍 DRY RUN - No changes were made

Would have processed: 15 duplicate groups
Would have kept: 15 records
Would have deleted: 27 records
```

#### Step 4: Verify Dry Run Results

1. Review the output carefully
2. Verify that oldest records are being kept
3. Check that the deletion count matches expectations
4. Confirm no critical records will be lost

### Phase 3: Staging Test

#### Step 5: Test on Staging

```bash
# On staging environment
php artisan attendance:cleanup-duplicates --force
```

#### Step 6: Verify Staging Results

```bash
# Verify no duplicates remain
php artisan attendance:identify-duplicates

# Check application functionality
# - QR scanning
# - Manual attendance entry
# - Reports
# - Dashboard statistics
```

### Phase 4: Production Cleanup

#### Step 7: Backup Database

```bash
# Create backup before cleanup
php artisan backup:run --only-db
```

**Verify backup:**
```bash
# Check backup file exists
ls -lh storage/app/backups/
```

#### Step 8: Execute Cleanup

```bash
# Run cleanup on production
php artisan attendance:cleanup-duplicates --force
```

**Monitor output for errors:**
- Watch for transaction rollback messages
- Check for any exceptions
- Verify summary shows expected numbers

#### Step 9: Verify Cleanup

```bash
# Confirm no duplicates remain
php artisan attendance:identify-duplicates

# Expected output:
# ✅ No duplicate attendance records found!
```

### Phase 5: Post-Cleanup Verification

#### Step 10: Data Integrity Checks

```bash
# Check attendance counts
php artisan tinker
>>> Attendance::whereNull('deleted_at')->count()
>>> Attendance::onlyTrashed()->count()
```

#### Step 11: Application Testing

Test the following functionality:
- [ ] QR code scanning
- [ ] Manual attendance entry
- [ ] Attendance reports
- [ ] Dashboard statistics
- [ ] Parent notifications
- [ ] Export functionality

#### Step 12: Review Cleanup Log

```bash
# View cleanup log
cat storage/logs/duplicate-cleanup-YYYY-MM-DD-HHMMSS.json
```

**Log structure:**
```json
{
  "executed_at": "2026-03-04 10:30:00",
  "executed_by": "artisan:attendance:cleanup-duplicates",
  "summary": {
    "duplicate_groups_processed": 15,
    "records_kept": 15,
    "records_deleted": 27
  },
  "details": [
    {
      "student_id": 123,
      "schedule_id": 456,
      "attendance_date": "2026-03-01",
      "school_id": 1,
      "kept_id": 1001,
      "kept_created_at": "2026-03-01 08:00:00",
      "deleted_ids": [1002],
      "deleted_count": 1,
      "timestamp": "2026-03-04 10:30:15"
    }
  ]
}
```

## Cleanup Strategy

### Record Selection Logic

The cleanup script uses the following strategy:

1. **Group duplicates** by: `(student_id, schedule_id, attendance_date, school_id)`
2. **Keep oldest record** (by `created_at` timestamp)
3. **Soft delete** all newer duplicate records
4. **Log all actions** for audit trail

### Rationale

- **Oldest record is kept** because it's likely the original/correct entry
- **Newer records are duplicates** likely created by accident or race conditions
- **Soft delete** allows recovery if needed
- **Audit logging** maintains compliance and traceability

## Rollback Procedures

### If Issues Discovered Within 24 Hours

#### Option 1: Restore Soft-Deleted Records

```bash
php artisan tinker
>>> Attendance::onlyTrashed()
       ->where('deleted_at', '>', now()->subHours(24))
       ->restore();
```

#### Option 2: Restore from Backup

```bash
# List available backups
php artisan backup:list

# Restore specific backup
php artisan backup:restore --backup-name=<backup-name>
```

### If Issues Discovered After 24 Hours

1. Review cleanup log to identify affected records
2. Manually restore specific records if needed
3. Investigate root cause of issues
4. Document lessons learned

## Troubleshooting

### Issue: Command Fails with Transaction Error

**Symptom:**
```
❌ Error during cleanup: SQLSTATE[40001]: Serialization failure
```

**Solution:**
1. Check database connection
2. Verify no long-running queries
3. Retry cleanup during low-traffic period

### Issue: Cleanup Takes Too Long

**Symptom:**
Command runs for > 5 minutes

**Solution:**
```bash
# Clean up one school at a time
php artisan attendance:cleanup-duplicates --school=1 --force
php artisan attendance:cleanup-duplicates --school=2 --force
```

### Issue: Unexpected Duplicate Count

**Symptom:**
More/fewer duplicates than expected

**Solution:**
1. Re-run identification command
2. Export to CSV and review manually
3. Check for recent data imports
4. Verify no concurrent attendance creation

## Safety Features

### Built-in Protections

1. **Transaction Wrapper**: All changes in single transaction
2. **Soft Deletes**: Records can be restored
3. **Audit Logging**: All actions logged to Laravel log
4. **Cleanup Log**: Detailed JSON log saved to file
5. **Dry Run Mode**: Preview changes before execution
6. **Confirmation Prompt**: Requires explicit confirmation (unless --force)

### Monitoring

The cleanup script logs to:
- **Laravel Log**: `storage/logs/laravel.log`
- **Cleanup Log**: `storage/logs/duplicate-cleanup-*.json`

Monitor these logs for:
- Errors during cleanup
- Unexpected deletion counts
- Transaction rollbacks

## Next Steps After Cleanup

### 1. Add Unique Constraint

```bash
# Create migration
php artisan make:migration add_unique_attendance_constraint

# Run migration
php artisan migrate
```

**Migration content:**
```php
Schema::table('attendances', function (Blueprint $table) {
    $table->unique(
        ['student_id', 'schedule_id', 'attendance_date', 'school_id'],
        'unique_attendance_per_day'
    );
});
```

### 2. Update Application Code

Replace all attendance creation with `firstOrCreate`:

```php
// ❌ OLD
Attendance::create([...]);

// ✅ NEW
Attendance::firstOrCreate(
    [
        'student_id' => $student->id,
        'schedule_id' => $scheduleId,
        'attendance_date' => $date,
        'school_id' => $schoolId,
    ],
    [
        'check_in_time' => now(),
        // ... other fields
    ]
);
```

### 3. Add Tests

Write tests to verify:
- Unique constraint enforcement
- `firstOrCreate` usage
- Duplicate prevention

## Checklist

### Pre-Cleanup
- [ ] Database backup completed
- [ ] Staging environment tested
- [ ] Team notified
- [ ] Maintenance window scheduled
- [ ] Dry run executed and reviewed

### During Cleanup
- [ ] Cleanup executed successfully
- [ ] No errors in output
- [ ] Summary matches expectations
- [ ] Cleanup log saved

### Post-Cleanup
- [ ] No duplicates remain (verified)
- [ ] Application functionality tested
- [ ] Data integrity verified
- [ ] Cleanup log reviewed
- [ ] Team notified of completion

### Follow-up
- [ ] Unique constraint added
- [ ] Application code updated
- [ ] Tests written
- [ ] Documentation updated

## Support

For issues or questions:
1. Review this guide
2. Check cleanup logs
3. Consult with team lead
4. Review requirements document: `.kiro/specs/saas-hardening-30-days/requirements.md`

## References

- **Requirements**: Week 1, Day 2 - Unique Attendance Constraint
- **Tasks**: Task 2.2 - Create cleanup script for duplicates
- **Analysis**: `backend/docs/duplicate-attendance-analysis.md`
- **Identification Command**: `backend/app/Console/Commands/IdentifyDuplicateAttendance.php`
- **Cleanup Command**: `backend/app/Console/Commands/CleanupDuplicateAttendance.php`
