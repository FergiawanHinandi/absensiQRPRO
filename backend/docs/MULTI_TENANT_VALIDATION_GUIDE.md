# Multi-Tenant Restore Validation Guide

## Overview

The Multi-Tenant Restore Validation system ensures safe data restoration in a multi-tenant school attendance SaaS environment by validating that backup data can only be restored to the correct school.

## Features

### 🔍 Pre-Restore Validation
- **School ID Consistency**: Ensures all records belong to the target school
- **Data Integrity**: Validates foreign keys and data formats
- **Conflict Detection**: Identifies potential conflicts with existing data
- **Structure Validation**: Verifies required tables and columns exist

### 🛡️ Safety Mechanisms
- **Automatic Abort**: Stops restore if validation fails
- **Detailed Logging**: Every validation step is logged
- **Audit Trail**: Complete validation history with timestamps
- **Multi-layer Checks**: File, structure, and data validation

## Usage

### CLI Validation
```bash
# Basic validation
php artisan restore:validate-multi-tenant backup_20240101.sql 123

# With detailed report
php artisan restore:validate-multi-tenant backup_20240101.sql 123 --report

# Using shell scripts (Linux/Mac)
./scripts/validate_restore.sh backup_20240101.sql 123 --report

# Using batch script (Windows)
scripts\validate_restore.bat backup_20240101.sql 123 --report
```

### API Validation
```http
POST /api/rollback/execute
Content-Type: application/json
Authorization: Bearer {token}

{
    "type": "database",
    "backup_identifier": "backup_20240101",
    "school_id": 123
}
```

## Validation Checks

### 1. File Validation
- ✅ Backup file exists and is readable
- ✅ File size is valid (not empty)
- ✅ File format is correct (SQL)

### 2. School ID Consistency
- ✅ All records have matching school_id
- ❌ **ABORT** if records belong to different schools
- ✅ Prevents cross-tenant data contamination

### 3. Table Structure
- ✅ Required tables exist (attendances, students, teachers, classes, schools)
- ✅ school_id column exists in tenant tables
- ✅ Proper table relationships

### 4. Data Integrity
- ✅ Foreign key constraints are valid
- ✅ No duplicate primary keys
- ✅ Valid email formats
- ✅ Valid date formats

### 5. Conflict Detection
- ✅ Target school exists
- ⚠️ Warns about conflicting student IDs
- ✅ Checks for existing data conflicts

## Error Types

### Critical Errors (Abort Restore)
- `SCHOOL_ID_MISMATCH`: Records belong to wrong school
- `MISSING_TABLES`: Required tables missing
- `SCHOOL_NOT_EXISTS`: Target school doesn't exist
- `FILE_NOT_FOUND`: Backup file missing

### Warnings (Allow Restore)
- `FOREIGN_KEY_WARNING`: Invalid foreign key references
- `DUPLICATE_STUDENT`: Duplicate student records
- `INVALID_EMAIL`: Invalid email formats
- `STUDENT_ID_CONFLICT`: Student ID conflicts

## Integration Points

### Middleware Integration
The `ValidateMultiTenantRestore` middleware automatically validates all restore operations:

```php
Route::middleware(['validate.multi.tenant.restore'])->group(function () {
    Route::post('/rollback/execute', [RollbackController::class, 'executeRollback']);
});
```

### Logging
All validation attempts are logged to:
- `rollback` channel: General validation logs
- `security` channel: Security-related events
- Activity log: User actions with full context

## Reports

### Validation Report Structure
```markdown
# Multi-Tenant Restore Validation Report

**Restore ID:** restore_abc123_1640995200
**Timestamp:** 2024-01-01T12:00:00Z
**Status:** ✅ PASSED

## Errors (0)

## Warnings (2)
- **STUDENT_ID_CONFLICT:** Student ID conflict: 1001 already exists
- **INVALID_EMAIL:** Invalid email format detected: student@invalid

## Summary
Validation passed with 2 warnings
```

## Security Features

### Tenant Isolation
- **Strict Validation**: Only allows restore to correct school
- **Cross-Tenant Prevention**: Blocks data from other schools
- **Audit Logging**: All actions tracked with user context

### Access Control
- **Admin Required**: Only admin users can perform restore
- **School Context**: Validates user's school permissions
- **Token Validation**: Requires valid authentication tokens

## Best Practices

### Before Restore
1. **Always Validate**: Never skip validation
2. **Test Environment**: Test in staging first
3. **Current Backup**: Create backup before restore
4. **User Notification**: Inform affected users

### During Validation
1. **Review Warnings**: Check all warning messages
2. **Verify School ID**: Double-check target school
3. **Check Conflicts**: Resolve any conflicts identified
4. **Document**: Keep validation reports

### After Validation
1. **Monitor**: Watch for issues post-restore
2. **Verify Data**: Confirm data integrity
3. **Log Completion**: Record successful restore
4. **Clean Up**: Remove temporary files

## Troubleshooting

### Common Issues

#### School ID Mismatch
```
ERROR: SCHOOL_ID_MISMATCH: School ID inconsistency detected
```
**Solution**: Verify backup source and target school match

#### Missing Tables
```
ERROR: MISSING_TABLES: Required tables missing from backup
```
**Solution**: Ensure backup contains complete schema

#### File Not Found
```
ERROR: FILE_NOT_FOUND: Backup file not found
```
**Solution**: Check file path and permissions

### Debug Mode
Enable detailed logging:
```bash
LOG_LEVEL=debug php artisan restore:validate-multi-tenant backup.sql 123
```

## API Response Examples

### Success Response
```json
{
    "success": true,
    "message": "Rollback executed successfully",
    "data": {
        "rollback_id": "rollback_abc123_1640995200",
        "type": "database",
        "validation_result": {
            "valid": true,
            "error_count": 0,
            "warning_count": 2
        }
    }
}
```

### Validation Failed Response
```json
{
    "success": false,
    "message": "Multi-tenant validation failed - restore aborted for safety",
    "validation_result": {
        "valid": false,
        "errors": [
            {
                "code": "SCHOOL_ID_MISMATCH",
                "message": "School ID inconsistency detected"
            }
        ]
    }
}
```

## Performance Considerations

- **Large Files**: Validation processes files in chunks
- **Memory Usage**: Optimized for minimal memory footprint
- **Caching**: Results cached for repeated validations
- **Async**: Can run validation asynchronously for large backups
