# Duplicate Attendance Cleanup - Quick Reference

## Quick Start

### 1. Check for Duplicates
```bash
php artisan attendance:query-duplicates
```

### 2. Preview Cleanup (Safe)
```bash
php artisan attendance:cleanup-duplicates --dry-run
```

### 3. Execute Cleanup
```bash
php artisan attendance:cleanup-duplicates
```

### 4. Verify Clean
```bash
php artisan attendance:query-duplicates
# Should show: "No duplicate attendance records found!"
```

## Command Options

| Option | Description | Example |
|--------|-------------|---------|
| `--dry-run` | Preview without changes | `php artisan attendance:cleanup-duplicates --dry-run` |
| `--export` | Export report to JSON | `php artisan attendance:cleanup-duplicates --export` |
| `--force` | Skip confirmation | `php artisan attendance:cleanup-duplicates --force` |

## Common Scenarios

### Scenario 1: First Time Cleanup
```bash
# Step 1: Check what exists
php artisan attendance:query-duplicates --detailed

# Step 2: Preview cleanup
php artisan attendance:cleanup-duplicates --dry-run --export

# Step 3: Review export
cat storage/app/logs/dry_run_duplicate_cleanup_*.json

# Step 4: Execute
php artisan attendance:cleanup-duplicates --export

# Step 5: Verify
php artisan attendance:query-duplicates
```

### Scenario 2: Automated Cleanup (CI/CD)
```bash
# Non-interactive cleanup with logging
php artisan attendance:cleanup-duplicates --force --export
```

### Scenario 3: Emergency Manual Cleanup
```bash
# Use standalone script if Artisan unavailable
php database/scripts/cleanup_duplicate_attendances.php --dry-run
php database/scripts/cleanup_duplicate_attendances.php
```

## What Gets Removed?

**Strategy**: Keep oldest record (lowest ID), soft delete duplicates

**Example**:
```
Duplicate Set:
- ID 1001 (created 2026-02-01 08:00) ✅ KEPT
- ID 1002 (created 2026-02-01 08:01) ❌ SOFT DELETED
- ID 1003 (created 2026-02-01 08:02) ❌ SOFT DELETED
```

## Recovery

### Restore All Recent Deletions
```php
php artisan tinker
>>> Attendance::onlyTrashed()->where('deleted_at', '>', now()->subHour())->restore();
```

### Restore Specific IDs (from export report)
```php
php artisan tinker
>>> Attendance::withTrashed()->whereIn('id', [1002, 1003])->restore();
```

## Troubleshooting

### "Command not found"
```bash
# Register command
php artisan list | grep attendance

# If not listed, clear cache
php artisan config:clear
php artisan cache:clear
```

### "No duplicates found" but constraint fails
```sql
-- Check including soft-deleted
SELECT student_id, schedule_id, attendance_date, school_id, COUNT(*)
FROM attendances
GROUP BY student_id, schedule_id, attendance_date, school_id
HAVING COUNT(*) > 1;
```

### Cleanup takes too long
```bash
# Run during maintenance window
php artisan down
php artisan attendance:cleanup-duplicates --force
php artisan up
```

## Safety Checklist

- [ ] Backup database before cleanup
- [ ] Run with `--dry-run` first
- [ ] Review export report
- [ ] Execute during low-traffic period
- [ ] Verify with query command after cleanup
- [ ] Keep export report for audit trail

## Next Steps After Cleanup

1. ✅ Duplicates removed
2. Apply unique constraint: `php artisan migrate`
3. Update code to use `firstOrCreate()`
4. Run tests: `php artisan test --filter=AttendanceTest`

## Support

For detailed documentation, see:
- `docs/DUPLICATE_ATTENDANCE_CLEANUP.md` - Full documentation
- `docs/DUPLICATE_ATTENDANCE_QUERY.md` - Query documentation
- `.kiro/specs/saas-hardening-30-days/` - Project specs
