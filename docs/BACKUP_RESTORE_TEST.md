# Manual Backup & Restore Test Guide

This document outlines the procedure to manually test the backup and restore process for AbsensiQRPro.

## 1. Prerequisites

Ensure the following environment variables are set in `.env`:
```bash
BACKUP_ENCRYPTION_KEY=your-secure-encryption-key
BACKUP_AWS_BUCKET=your-s3-bucket-name
BACKUP_NOTIFICATION_EMAIL=admin@example.com
```

## 2. Manual Backup Execution

Run the following command to trigger an immediate backup:

```bash
php artisan backup:run
```

To run only database backup:
```bash
php artisan backup:run --only-db
```

### Verification
Check the output for "Backup failed" or "Backup successful".
Verify the file exists in `storage/app/Project/` or S3 bucket.

## 3. Manual Restore Procedure (Test)

**WARNING:** Do not run this on production without a separate backup! This overwrites the database.

### Step 3.1: Locate Backup File
Find the latest backup zip file:
```bash
php artisan backup:list
```
Identify the latest healthy backup.

### Step 3.2: Download & Extract
On your local machine or test server:
1. Download the zip file from S3 or copy from server.
2. Unzip the file. You will need the encryption password if encrypted.
   *Note: Spatie backups are standard zips. If password protected, use standard unzip with password.*

   If using specific encryption (AES-256 via config), the zip itself might be encrypted or the contents.
   With `spatie/laravel-backup` default encryption, the entire zip is password protected.

   ```bash
   unzip -P "your-encryption-key" backup-2026-02-09-*.zip
   ```

### Step 3.3: Import Database
Inside the extracted folder, locate `db-dumps/postgresql-*.sql` (or mysql).

**PostgreSQL:**
```bash
pg_restore -U username -d database_name -c db-dumps/postgresql-backup.sql
# OR if plain SQL
psql -U username -d database_name -f db-dumps/postgresql-backup.sql
```

**MySQL:**
```bash
mysql -u username -p database_name < db-dumps/mysql-backup.sql
```

## 4. Post-Restore Verification
1. Log in to the application.
2. Check recent data (Attendances, Users).
3. Verify file storage (Images, specific uploads).

## 5. Alert Testing

To test alerts, you can force a failure by temporarily changing the S3 bucket name in `.env` to an invalid one or unsetting the encryption key.

1. Modify `.env` to break configuration (e.g. invalid S3 credentials).
2. Run `php artisan backup:run`.
3. Check email/Slack for "Backup Failed" notification.
4. Revert `.env` changes.

## 6. Schedule Verification
Ensure the schedule is active:
```bash
php artisan schedule:list
```
Look for `backup:run` entries (02:00 Daily, etc).
