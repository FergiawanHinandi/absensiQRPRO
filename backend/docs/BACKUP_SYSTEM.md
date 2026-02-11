# Automated Encrypted Backup System

## Overview

AbsensiQRPro implements a production-grade automated encrypted backup system using `spatie/laravel-backup`. This system protects against:

- **Database corruption**: Regular PostgreSQL dumps with integrity verification
- **Accidental deletion**: Multi-tier retention policy with local + S3 storage
- **Server compromise**: AES-256 encryption with separate backup key

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    BACKUP SCHEDULE                          │
├─────────────────────────────────────────────────────────────┤
│  Every 15m │  Incremental DB backup (--only-db)            │
│  02:00 AM  │  Full Database backup (--only-db)             │
│  02:45 AM  │  Files backup (--only-files)                  │
│  03:00 AM  │  Daily Cleanup                                │
│  Monthly   │  Automated Restore Validation (1st of month)  │
│  Every 6h  │  Health monitoring                            │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    BACKUP PIPELINE                          │
├─────────────────────────────────────────────────────────────┤
│  1. Create database dump (pg_dump + gzip)                  │
│  2. Collect files (storage/app/public, security-reports)   │
│  3. Encrypt with AES-256 (BACKUP_ENCRYPTION_KEY)           │
│  4. Store locally (temporary)                              │
│  5. Upload to S3 (backups-s3 disk)                         │
│  6. Apply retention policy                                 │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    RETENTION POLICY                         │
├─────────────────────────────────────────────────────────────┤
│  Keep all:      7 days                                     │
│  Daily:         14 days                                    │
│  Weekly:        8 weeks                                    │
│  Monthly:       6 months                                   │
│  Yearly:        2 years                                    │
│  Max storage:   20 GB                                      │
└─────────────────────────────────────────────────────────────┘
```

## Configuration

### Environment Variables (.env)

```dotenv
# Encryption Key (CRITICAL - keep secure!)
# Generate: php artisan tinker --execute="echo bin2hex(random_bytes(32));"
BACKUP_ENCRYPTION_KEY=your_secure_encryption_key_here

# Notification Settings
BACKUP_NOTIFICATION_EMAIL=admin@yourschool.com
BACKUP_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
BACKUP_SLACK_CHANNEL=#alerts

# S3 Backup Storage (Dedicated bucket)
BACKUP_AWS_ACCESS_KEY_ID=your_backup_iam_key
BACKUP_AWS_SECRET_ACCESS_KEY=your_backup_iam_secret
BACKUP_AWS_DEFAULT_REGION=ap-southeast-1
BACKUP_AWS_BUCKET=absensi-backups-prod
```

### S3 Bucket Configuration

Create a dedicated S3 bucket with:

1. **Private access only** (block all public access)
2. **Versioning enabled** (protection against accidental overwrites)
3. **Server-side encryption** (AES-256)
4. **Lifecycle rules** (optional: transition to Glacier after 90 days)

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::absensi-backups-prod",
        "arn:aws:s3:::absensi-backups-prod/*"
      ]
    }
  ]
}
```

## Commands

### Manual Backup (Monitored)

```bash
# Full backup (database + files)
php artisan backup:monitored

# Database only
php artisan backup:monitored --only-db

# Files only
php artisan backup:monitored --only-files
```

### Automated Validation

```bash
# Run the validation process immediately
php artisan backup:validate-restore
```

### Health Check

```bash
# Check backup health
php artisan backup:health-check

# Silent mode (for cron)
php artisan backup:health-check --silent

# Custom max age (hours)
php artisan backup:health-check --max-age=48
```

### Test Restore

```bash
# Test restore from local disk
php artisan backup:test-restore

# Test restore from S3
php artisan backup:test-restore --disk=backups-s3

# Keep extracted files for inspection
php artisan backup:test-restore --keep
```

### List Backups

```bash
php artisan backup:list
```

### Cleanup Old Backups

```bash
php artisan backup:clean
```

## API Endpoint

### GET /api/v1/admin/system/backup-status

**Authorization**: `super_admin` role only

**Response**:

```json
{
  "status": "success",
  "data": {
    "last_backup_at": "2026-01-29T02:15:32+00:00",
    "backup_age_hours": 12,
    "status": "healthy",
    "storage_disks": [
      {
        "disk": "local",
        "status": "healthy",
        "backup_count": 14,
        "total_size_human": "2.5 GB",
        "last_backup_at": "2026-01-29T02:15:32+00:00",
        "backup_age_hours": 12
      },
      {
        "disk": "backups-s3",
        "status": "healthy",
        "backup_count": 14,
        "total_size_human": "2.5 GB",
        "last_backup_at": "2026-01-29T02:15:32+00:00",
        "backup_age_hours": 12
      }
    ],
    "encryption_enabled": true,
    "retention_policy": {
      "daily_backups_days": 14,
      "weekly_backups_weeks": 8,
      "monthly_backups_months": 6
    },
    "checked_at": "2026-01-29T14:30:00+00:00"
  }
}
```

**Status Values**:
- `healthy`: Backup < 26 hours old
- `warning`: Backup 26-48 hours old
- `critical`: Backup > 48 hours old or missing

## Alerts

Backup failures trigger:

1. **Security Alert** (event_type: `backup_failure`)
2. **Email notification** to `BACKUP_NOTIFICATION_EMAIL`
3. **Slack notification** to `BACKUP_SLACK_CHANNEL`
4. **Immutable audit log entry**

## Disaster Recovery

### Restore Procedure

1. **Download backup** from S3 or local storage
2. **Decrypt** using the encryption key
3. **Extract** the ZIP archive
4. **Restore database**:
   ```bash
   # Decompress if gzipped
   gunzip database-backup.sql.gz
   
   # Restore to PostgreSQL
   psql -U postgres -d absensi_qr < database-backup.sql
   ```
5. **Restore files** to `storage/app/`

### Testing Restores

Run monthly restore tests:

```bash
# Automated test (verifies integrity without overwriting production)
php artisan backup:test-restore --disk=backups-s3

# Manual test on separate server
# 1. Copy backup to test server
# 2. Decrypt and extract
# 3. Restore to test database
# 4. Verify data integrity
```

## Security Considerations

1. **Encryption Key Rotation**: 
   - Generate new `BACKUP_ENCRYPTION_KEY` quarterly
   - Keep old keys to decrypt historical backups

2. **Access Control**:
   - S3 bucket: Private only, IAM-restricted
   - API endpoint: `super_admin` role only
   - Encryption key: Never commit to Git

3. **Monitoring**:
   - Health check every 6 hours
   - Alert on backup > 26 hours old
   - Alert on backup failures

4. **Testing**:
   - Monthly restore tests
   - Verify encryption/decryption works
   - Test from both local and S3 disks

## Files Modified

- `config/backup.php` - Backup configuration
- `config/filesystems.php` - S3 backup disk
- `routes/console.php` - Scheduled tasks
- `app/Console/Commands/MonitorBackupHealth.php` - Health monitoring
- `app/Console/Commands/TestBackupRestore.php` - Restore testing
- `app/Http/Controllers/Api/V1/Admin/SystemHealthController.php` - API endpoint
- `routes/api.php` - API route
- `.env.example` - Environment template

## 📊 Recovery Objectives (RTO & RPO)

### Recovery Point Objective (RPO)
**Target: 15 Minutes**
- **Database**: We perform incremental (full snap) backups every 15 minutes. In a worst-case disaster, maximum data loss is ~15 minutes of transactions.
- **Files (Uploads)**: Backed up daily (24h RPO). This is acceptable as file changes are less frequent and often recoverable from source or not business-critical for immediate operations.

### Recovery Time Objective (RTO)
**Target: < 1 Hour**
- **Database Restore**: ~15-30 minutes depending on size.
- **Files Restore**: ~30-60 minutes depending on S3 download speed.
- **System Availability**: The application can be brought up in maintenance mode within minutes, with full functionality restored once the database is populated.

## ✅ Restore Validation Checklist

Use this checklist for Monthly Manual Verification or when reviewing Automated Validation logs.

### 1. Preparation
- [ ] Identify the backup artifact to restore (Date/Time).
- [ ] Ensure `BACKUP_ENCRYPTION_KEY` is available.
- [ ] Prepare a clean environment (staging server or local Docker container). **NEVER** test on production.

### 2. Execution
- [ ] Download the backup zip file.
- [ ] Unzip and decrypt the payload.
- [ ] Verify the SQL dump file exists and is not empty.
- [ ] Import the SQL dump into the test database.
  ```bash
  psql -U user -d test_db < dump.sql
  ```

### 3. Data Integrity Verification
- [ ] **User Count**: Check if total users match expected range.
  `SELECT count(*) FROM users;`
- [ ] **Recent Data**: specific check for recent attendance records.
  `SELECT * FROM attendances ORDER BY created_at DESC LIMIT 5;`
- [ ] **Foreign Keys**: Ensure no orphaned records (handled by DB constraints, but good to verify).
- [ ] **Application Logic**: Login as a Super Admin and browse the dashboard.

### 4. File Verification
- [ ] Check if `storage/app/public` contains readable images.
- [ ] Open a random sample of 3 uploaded files/photos.

### 5. Completion
- [ ] Log the result of the test (Success/Failure/Notes).
- [ ] Destroy the test environment/database to prevent data leaks.
