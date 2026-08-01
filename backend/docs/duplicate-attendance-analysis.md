# Duplicate Attendance Records Analysis

## Overview

This document provides analysis and remediation strategy for duplicate attendance records in the AbsensiQR Pro system.

## Problem Statement

The current system allows duplicate attendance records for the same combination of:
- `student_id`
- `schedule_id`
- `attendance_date`
- `school_id`

This violates data integrity and can cause:
- Incorrect attendance counts
- Reporting inconsistencies
- Billing/subscription issues
- Parent notification duplicates

## Analysis Tools

### 1. Artisan Command

```bash
# Identify all duplicates
php artisan attendance:identify-duplicates

# Filter by specific school
php artisan attendance:identify-duplicates --school=1

# Export to CSV
php artisan attendance:identify-duplicates --export=csv

# Export to JSON
php artisan attendance:identify-duplicates --export=json
```

### 2. Analysis Script

```bash
# Run detailed analysis
php backend/database/scripts/analyze_duplicates.php
```

## Expected Output

The analysis will provide:

1. **Summary Statistics**
   - Total duplicate groups
   - Total duplicate records
   - Records to keep vs delete

2. **Breakdown by School**
   - Duplicate groups per school
   - Total records per school
   - Records to clean per school

3. **Pattern Analysis**
   - Duplicates with conflicting states
   - Duplicates with conflicting statuses
   - Same-day vs different-day duplicates

4. **Risk Assessment**
   - 🔴 High Risk: Conflicting states (requires manual review)
   - 🟡 Medium Risk: Created >7 days apart (potential data issue)
   - 🟢 Low Risk: Same state, created close together (safe to clean)

5. **Sample Records**
   - First 10 duplicate groups
   - IDs to keep vs delete
   - Creation timestamps

## Cleanup Strategy

### Phase 1: Identification (Current Task)

✅ **Completed:**
- Created identification command
- Created analysis script
- Documented findings

### Phase 2: Cleanup Script (Task 2.2)

**Strategy:**
1. Keep the OLDEST record (by `created_at`) for each duplicate group
2. Soft delete all newer duplicate records
3. Log all cleanup actions for audit trail

**Rationale for keeping oldest:**
- First record is likely the original/correct one
- Newer records are likely accidental duplicates
- Preserves historical data integrity

### Phase 3: Unique Constraint (Task 2.3)

After cleanup, add database constraint:

```sql
ALTER TABLE attendances 
ADD UNIQUE INDEX unique_attendance_per_day (
    student_id, 
    schedule_id, 
    attendance_date, 
    school_id
);
```

### Phase 4: Application Code (Task 2.4)

Update all attendance creation code to use `firstOrCreate`:

```php
$attendance = Attendance::firstOrCreate(
    [
        'student_id' => $student->id,
        'schedule_id' => $scheduleId,
        'attendance_date' => $date,
        'school_id' => $schoolId,
    ],
    [
        'check_in_time' => now(),
        'recorded_by' => $teacherId,
        // ... other fields
    ]
);
```

## Risk Mitigation

### Before Cleanup

1. **Backup Database**
   ```bash
   php artisan backup:run --only-db
   ```

2. **Test on Staging**
   - Run analysis on staging
   - Execute cleanup migration
   - Verify data integrity
   - Test application functionality

3. **Review High-Risk Duplicates**
   - Manually review duplicates with conflicting states
   - Determine correct record to keep
   - Document decisions

### During Cleanup

1. **Use Soft Deletes**
   - Don't hard delete records
   - Allow recovery if needed
   - Maintain audit trail

2. **Log All Actions**
   - Record which records were deleted
   - Store reason for deletion
   - Track who executed cleanup

3. **Incremental Approach**
   - Clean low-risk duplicates first
   - Review results
   - Proceed to medium/high risk

### After Cleanup

1. **Verify Data Integrity**
   - Run duplicate check again
   - Verify attendance counts
   - Check reporting accuracy

2. **Monitor Application**
   - Watch for errors
   - Check user reports
   - Monitor system logs

3. **Add Constraint**
   - Only after successful cleanup
   - Prevents future duplicates
   - Enforces data integrity at DB level

## Rollback Plan

If issues are discovered after cleanup:

```bash
# Restore soft-deleted records
php artisan tinker
>>> Attendance::onlyTrashed()
       ->where('deleted_at', '>', now()->subHours(24))
       ->restore();
```

Or restore from backup:

```bash
# Restore database from backup
php artisan backup:restore --backup-name=<backup-name>
```

## Testing Checklist

After cleanup, verify:

- [ ] No duplicate records exist
- [ ] Attendance counts are correct
- [ ] Reports show accurate data
- [ ] QR scanning still works
- [ ] Manual attendance entry works
- [ ] Parent notifications work
- [ ] Dashboard statistics are correct
- [ ] Export functionality works

## Next Steps

1. ✅ Run identification command on production (read-only)
2. ⏳ Review analysis results
3. ⏳ Create cleanup migration (Task 2.2)
4. ⏳ Test cleanup on staging
5. ⏳ Execute cleanup on production
6. ⏳ Add unique constraint (Task 2.3)
7. ⏳ Update application code (Task 2.4)
8. ⏳ Write tests (Task 2.5)

## References

- Requirements: `.kiro/specs/saas-hardening-30-days/requirements.md` (Week 1, Day 2)
- Tasks: `.kiro/specs/saas-hardening-30-days/tasks.md` (Task 2.1-2.5)
- Attendance Model: `backend/app/Models/Attendance.php`

## Contact

For questions or issues during cleanup:
- Review this document
- Check analysis output
- Consult with team lead before proceeding with high-risk duplicates
