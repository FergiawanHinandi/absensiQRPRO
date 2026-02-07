#!/bin/bash

# Setup Backup Cron Jobs
# AbsensiQR Pro - Automated Backup Scheduling

echo "🔧 Setting up backup cron jobs for AbsensiQR Pro"

# Get the current directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(dirname "$SCRIPT_DIR")"

# Create cron job entries
CRON_JOBS="
# AbsensiQR Pro Advanced Backup Strategy
# Run backup scheduler every 5 minutes to check for scheduled tasks
*/5 * * * * cd $BACKEND_DIR && php scripts/backup_scheduler.php >> storage/logs/cron_backup.log 2>&1

# Weekly full backup (Sunday 02:00)
0 2 * * 0 cd $BACKEND_DIR && php scripts/advanced_backup_strategy.php backup >> storage/logs/full_backup.log 2>&1

# Daily incremental backup (02:00, except Sunday)
0 2 * * 1-6 cd $BACKEND_DIR && php scripts/advanced_backup_strategy.php backup >> storage/logs/incremental_backup.log 2>&1

# WAL/Binlog archiving every 5 minutes
*/5 * * * * cd $BACKEND_DIR && php scripts/wal_binlog_archiver.php >> storage/logs/wal_archive.log 2>&1

# Backup cleanup daily at 01:00
0 1 * * * cd $BACKEND_DIR && php scripts/backup_cleanup.php >> storage/logs/backup_cleanup.log 2>&1

# Backup verification daily at 03:00
0 3 * * * cd $BACKEND_DIR && php scripts/backup_verifier.php >> storage/logs/backup_verification.log 2>&1

# Health check every hour
0 * * * * cd $BACKEND_DIR && php scripts/backup_health_check.php >> storage/logs/backup_health.log 2>&1
"

# Add cron jobs
echo "Adding cron jobs..."
(crontab -l 2>/dev/null; echo "$CRON_JOBS") | crontab -

echo "✅ Backup cron jobs have been set up successfully!"
echo ""
echo "📋 Scheduled backup operations:"
echo "  • Backup scheduler check: Every 5 minutes"
echo "  • Full backup: Sunday 02:00"
echo "  • Incremental backup: Monday-Saturday 02:00"
echo "  • WAL/Binlog archive: Every 5 minutes"
echo "  • Cleanup: Daily 01:00"
echo "  • Verification: Daily 03:00"
echo "  • Health check: Every hour"
echo ""
echo "📝 Log files location: $BACKEND_DIR/storage/logs/"
echo ""
echo "🔍 To view current cron jobs: crontab -l"
echo "🗑️  To remove cron jobs: crontab -r"