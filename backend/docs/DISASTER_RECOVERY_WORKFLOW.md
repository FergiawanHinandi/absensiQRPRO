# Disaster Recovery Test Workflow

This document describes the automated disaster recovery (DR) testing workflow for AbsensiQRPro.

## Overview

The DR test workflow automatically:
1. Restores the latest backup to a staging database
2. Runs comprehensive integrity checks
3. Reports success/failure via multiple channels
4. Runs weekly via CI/CD (Sundays at 3 AM UTC)

## Components

### Service: `DisasterRecoveryTestService`

Location: `app/Services/DisasterRecoveryTestService.php`

Core service that handles the complete DR test workflow:

```php
$service = new DisasterRecoveryTestService();
$results = $service->runFullTest(
    diskName: 'local',
    createFreshBackup: true
);
```

### Artisan Command: `dr:test-workflow`

Location: `app/Console/Commands/DisasterRecoveryWorkflow.php`

```bash
# Basic run
php artisan dr:test-workflow

# With fresh backup
php artisan dr:test-workflow --create-backup

# With notifications
php artisan dr:test-workflow --notify

# JSON output for CI/CD
php artisan dr:test-workflow --json

# Save report to storage
php artisan dr:test-workflow --save-report

# Full options
php artisan dr:test-workflow \
    --disk=local \
    --create-backup \
    --notify \
    --save-report \
    --slack-webhook=https://hooks.slack.com/...
```

### GitHub Actions Workflow

Location: `.github/workflows/disaster_recovery.yml`

Runs automatically every Sunday at 3 AM UTC, or can be triggered manually.

## Integrity Checks

The workflow performs the following integrity checks:

| Check | Description | Pass Criteria |
|-------|-------------|---------------|
| `attendance_count_match` | Compares attendance record count | Difference ≤ 0.1% or 5 records |
| `random_student_history` | Validates random student data | Records match between live/staging |
| `random_teacher_data` | Verifies teacher has schedules | Teacher has associated schedules |
| `school_data_integrity` | Multi-tenant data validation | School counts match, user counts ≤ 5 diff |
| `file_storage_accessible` | Storage accessibility | Backup structure valid, storage accessible |
| `foreign_key_integrity` | No orphaned records | 0 orphaned attendances/schedules |
| `indexes_exist` | Critical indexes present | All critical indexes exist |

## Configuration

### Environment Variables

Add to your `.env` file:

```env
# Slack webhook for DR notifications
SLACK_DR_WEBHOOK=https://hooks.slack.com/services/...

# Discord webhook for DR notifications
DISCORD_DR_WEBHOOK=https://discord.com/api/webhooks/...

# Enable/disable DR notifications
DR_NOTIFICATIONS_ENABLED=true
```

### GitHub Secrets

Configure these secrets for CI/CD:

| Secret | Description |
|--------|-------------|
| `SSH_HOST` | Production server hostname |
| `SSH_USERNAME` | SSH username |
| `SSH_PRIVATE_KEY` | SSH private key |
| `SSH_PORT` | SSH port (optional, default 22) |
| `SLACK_DR_WEBHOOK` | Slack webhook URL |

## Output Formats

### JSON Report

```json
{
  "test_id": "dr_abc123",
  "started_at": "2026-02-03T03:00:00Z",
  "completed_at": "2026-02-03T03:02:30Z",
  "staging_db": "absensi_staging_dr_20260203_030000",
  "duration_seconds": 150,
  "overall_status": "PASS",
  "steps": {
    "find_backup": {
      "status": "complete",
      "message": "Found backup: backups/2026-02-02-backup.zip"
    }
  },
  "integrity_checks": [
    {
      "name": "attendance_count_match",
      "status": "PASS",
      "details": {
        "live_count": 50000,
        "staging_count": 49998,
        "difference": 2
      }
    }
  ]
}
```

### Console Output

```
🚀 Starting Disaster Recovery Test Workflow

📋 Workflow Steps:
+-----------------+--------+--------------------------------+
| Step            | Status | Message                        |
+-----------------+--------+--------------------------------+
| find_backup     | ✅     | Found backup: backups/...      |
| extract_backup  | ✅     | Backup extracted successfully  |
| provision_staging| ✅    | Staging database created       |
| restore_backup  | ✅     | Backup restored to staging     |
| integrity_checks| ✅     | Integrity checks completed     |
+-----------------+--------+--------------------------------+

🔍 Integrity Checks:
+------------------------+---------+---------------------------+
| Check                  | Status  | Details                   |
+------------------------+---------+---------------------------+
| attendance_count_match | ✅ PASS | Live: 50000, Staging: ... |
| random_student_history | ✅ PASS | Student 123: Match        |
| school_data_integrity  | ✅ PASS | 5 schools verified        |
+------------------------+---------+---------------------------+

✅ DISASTER RECOVERY TEST PASSED
   Duration: 150s
   Test ID: dr_abc123
```

## Failure Handling

When a DR test fails:

1. **GitHub Issue Created**: Automatic issue with `critical`, `disaster-recovery`, `automated` labels
2. **Slack Notification**: Red alert sent to configured webhook
3. **Logs**: Detailed logs in `storage/logs/laravel-*.log`
4. **Report Artifact**: JSON report uploaded to GitHub Actions

## Manual Testing

To run the DR test manually on the server:

```bash
cd /var/www/absensiQRPro/backend

# Quick test
php artisan dr:test-workflow

# Full test with notifications
php artisan dr:test-workflow --create-backup --notify --save-report
```

## Troubleshooting

### Common Issues

1. **No backup found**
   - Ensure backups are configured: `php artisan backup:run --only-db`
   - Check backup disk in `config/backup.php`

2. **Database creation fails**
   - Verify PostgreSQL user has `CREATEDB` privilege
   - Check `database.connections.pgsql` config

3. **Restore fails**
   - Verify `psql` is in PATH or configured in `config/database.php`
   - Check `dump.dump_binary_path` setting

4. **SSH connection fails**
   - Verify SSH secrets are correctly configured
   - Test SSH connection manually

### Log Locations

- **Laravel logs**: `storage/logs/laravel-*.log`
- **DR reports**: `storage/app/dr-reports/`
- **GitHub Actions**: Actions tab → Workflow runs → Artifacts

## Security Considerations

- Staging database is automatically dropped after test
- Temp files are cleaned up on completion
- No sensitive data exposed in logs/reports
- SSH key rotation recommended quarterly
