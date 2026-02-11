# Data Integrity Hardening - Migration Guide

## Overview
Production-safe migration to add unique constraints and indexes without breaking existing data.

---

## Changes Summary

### 1. Attendances Table
```sql
-- Unique Constraint (prevents duplicate attendance)
UNIQUE KEY uniq_attendance_schedule_student_date (schedule_id, student_id, attendance_date)

-- Composite Index (optimizes lookups)
INDEX idx_attendance_lookup (schedule_id, school_id, attendance_date)
```

### 2. Schedules Table
```sql
-- Composite Index (optimizes teacher schedule lookups)
INDEX idx_schedule_lookup (teacher_id, school_id, day_of_week, is_active)
```

### 3. Users Table
```sql
-- Unique Constraints (prevents duplicate emails/usernames per school)
UNIQUE KEY users_school_id_email_unique (school_id, email)
UNIQUE KEY users_school_id_username_unique (school_id, username)
```

### 4. Subscriptions Table
```sql
-- Composite Index (optimizes subscription lookups)
INDEX idx_subscription_lookup (school_id, is_active, expires_at)
```

---

## Safety Features

### ✅ Pre-Migration Checks
- Verifies table exists before modification
- Checks all required columns exist
- Detects duplicate data before adding unique constraints
- Prevents migration if duplicates found

### ✅ Duplicate Detection
```php
// Example: Check for duplicate attendances
SELECT 
    schedule_id, 
    student_id, 
    DATE(attendance_date) as date,
    COUNT(*) as count
FROM attendances
GROUP BY schedule_id, student_id, DATE(attendance_date)
HAVING COUNT(*) > 1
```

### ✅ Index Existence Check
- Checks if index already exists before creating
- Prevents errors from duplicate index creation
- Works with MySQL, PostgreSQL, and SQLite

### ✅ Rollback Support
- Complete `down()` method for rollback
- Safe to run multiple times (idempotent)

---

## Pre-Migration Steps

### Step 1: Check for Duplicates (Dry Run)

```bash
# Run cleanup seeder in dry-run mode
php artisan db:seed --class=CleanupDuplicatesSeeder --dry-run
```

### Step 2: Clean Up Duplicates (If Found)

```bash
# Apply cleanup
php artisan db:seed --class=CleanupDuplicatesSeeder
```

### Step 3: Verify Clean Database

```bash
# Check for remaining duplicates
php artisan db:seed --class=CleanupDuplicatesSeeder --dry-run
```

---

## Migration Steps

### Step 1: Backup Database

```bash
# PostgreSQL
pg_dump -h localhost -U postgres absensi_db > backup_before_integrity.sql

# MySQL
mysqldump -u root -p absensi_db > backup_before_integrity.sql
```

### Step 2: Run Migration

```bash
php artisan migrate
```

### Step 3: Verify Constraints

```sql
-- MySQL
SHOW INDEX FROM attendances;

-- PostgreSQL
\d+ attendances
```

---

## Rollback (If Needed)

```bash
php artisan migrate:rollback --step=1
```

---

## Production Deployment Checklist

- [ ] Backup database
- [ ] Run cleanup seeder (dry-run)
- [ ] Review duplicate records
- [ ] Run cleanup seeder (apply)
- [ ] Verify no duplicates remain
- [ ] Run migration in staging
- [ ] Test application functionality
- [ ] Monitor for errors
- [ ] Run migration in production
- [ ] Verify constraints applied

---

**Ready for Production Deployment!** 🚀
