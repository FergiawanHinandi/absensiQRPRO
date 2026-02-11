# Spatie Backup - Quick Reference Guide

## Quick Commands

### Daily Operations

```bash
# Run manual backup
php artisan backup:run

# Database only (faster)
php artisan backup:run --only-db

# Check backup status
php artisan backup:list

# Monitor health
php artisan backup:monitor

# Clean old backups
php artisan backup:clean
```

### Emergency Restore

```bash
# 1. Download from S3
aws s3 cp s3://absensi-backups-production/latest.zip ./

# 2. Run restore script
chmod +x scripts/restore-backup.sh
./scripts/restore-backup.sh latest.zip

# 3. Update .env
DB_DATABASE=absensi_db_restore

# 4. Verify
php artisan tinker
>>> DB::table('users')->count()
```

## Environment Variables

```env
# Required
BACKUP_ENCRYPTION_KEY="base64:..."
BACKUP_AWS_BUCKET=absensi-backups-production
BACKUP_AWS_ACCESS_KEY_ID=...
BACKUP_AWS_SECRET_ACCESS_KEY=...

# Optional
BACKUP_AWS_DEFAULT_REGION=ap-southeast-1
BACKUP_NOTIFICATION_EMAIL=admin@yourdomain.com
BACKUP_SLACK_WEBHOOK_URL=https://hooks.slack.com/...
BACKUP_USE_GZIP=true
```

## Scheduled Tasks

| Task | Schedule | Command |
|------|----------|---------|
| Database Backup | Daily 2:00 AM | `backup:run --only-db` |
| Full Backup | Sunday 3:00 AM | `backup:run` |
| Cleanup | Daily 4:00 AM | `backup:clean` |
| Health Check | Every 6 hours | `backup:monitor` |

## Retention Policy

| Type | Retention |
|------|-----------|
| All backups | 7 days |
| Daily backups | 30 days |
| Weekly backups | 8 weeks |
| Monthly backups | 6 months |
| Yearly backups | 2 years |

## Notification Events

| Event | Channels | Action Required |
|-------|----------|-----------------|
| Backup Failed | Email + Slack | Immediate |
| Unhealthy Backup | Email + Slack | Within 1 hour |
| Cleanup Failed | Email | Within 24 hours |
| Backup Success | None | - |

## S3 Bucket Configuration

```bash
# Create bucket
aws s3 mb s3://absensi-backups-production --region ap-southeast-1

# Enable versioning
aws s3api put-bucket-versioning \
  --bucket absensi-backups-production \
  --versioning-configuration Status=Enabled

# Enable encryption
aws s3api put-bucket-encryption \
  --bucket absensi-backups-production \
  --server-side-encryption-configuration '{
    "Rules": [{
      "ApplyServerSideEncryptionByDefault": {
        "SSEAlgorithm": "AES256"
      }
    }]
  }'

# Block public access
aws s3api put-public-access-block \
  --bucket absensi-backups-production \
  --public-access-block-configuration \
    "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true"
```

## Troubleshooting

### Backup Fails

```bash
# Check disk space
df -h

# Check permissions
ls -la storage/app/backup-temp

# Test S3 connection
php artisan tinker
>>> Storage::disk('backups-s3')->put('test.txt', 'test')
```

### Restore Fails

```bash
# Verify encryption key
php artisan tinker
>>> config('backup.backup.password')

# Check SQL file
head -n 10 database.sql
tail -n 10 database.sql

# Test database connection
psql -h localhost -U postgres -l
```

### Notifications Not Working

```bash
# Test email
php artisan tinker
>>> Mail::raw('Test', fn($m) => $m->to('admin@example.com')->subject('Test'))

# Check Slack webhook
curl -X POST -H 'Content-type: application/json' \
  --data '{"text":"Test from AbsensiQRPro"}' \
  YOUR_SLACK_WEBHOOK_URL
```

## Monitoring Queries

```sql
-- Recent backups
SELECT 
    created_at,
    context->>'disk' as disk,
    context->>'size_mb' as size_mb
FROM logs
WHERE message LIKE '%backup%'
ORDER BY created_at DESC
LIMIT 10;

-- Failed backups
SELECT 
    created_at,
    context->>'error' as error
FROM logs
WHERE message LIKE '%backup%failed%'
ORDER BY created_at DESC;
```

## Security Checklist

- [ ] Encryption key generated and stored securely
- [ ] S3 bucket is private (no public access)
- [ ] Server-side encryption enabled on S3
- [ ] Versioning enabled on S3
- [ ] Notifications configured and tested
- [ ] Restore procedure tested
- [ ] Backup schedule verified in cron
- [ ] Old backups cleaned automatically

## Support

- Documentation: `docs/SPATIE_BACKUP_CONFIGURATION.md`
- Setup Script: `scripts/setup-backup.sh`
- Restore Script: `scripts/restore-backup.sh`
- Official Docs: https://spatie.be/docs/laravel-backup
