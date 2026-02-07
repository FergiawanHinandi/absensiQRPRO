@echo off
REM Setup Backup Scheduled Tasks for Windows
REM AbsensiQR Pro - Automated Backup Scheduling

echo 🔧 Setting up backup scheduled tasks for AbsensiQR Pro

set SCRIPT_DIR=%~dp0
set BACKEND_DIR=%SCRIPT_DIR%..
set PHP_PATH=php

echo Creating scheduled tasks...

REM Backup scheduler check every 5 minutes
schtasks /create /tn "AbsensiQR_BackupScheduler" /tr "%PHP_PATH% %BACKEND_DIR%\scripts\backup_scheduler.php" /sc minute /mo 5 /f

REM Weekly full backup (Sunday 02:00)
schtasks /create /tn "AbsensiQR_FullBackup" /tr "%PHP_PATH% %BACKEND_DIR%\scripts\advanced_backup_strategy.php backup" /sc weekly /d SUN /st 02:00 /f

REM Daily incremental backup (02:00, Monday-Saturday)
schtasks /create /tn "AbsensiQR_IncrementalBackup" /tr "%PHP_PATH% %BACKEND_DIR%\scripts\advanced_backup_strategy.php backup" /sc daily /st 02:00 /f

REM WAL/Binlog archiving every 5 minutes
schtasks /create /tn "AbsensiQR_WALArchive" /tr "%PHP_PATH% %BACKEND_DIR%\scripts\wal_binlog_archiver.php" /sc minute /mo 5 /f

REM Backup cleanup daily at 01:00
schtasks /create /tn "AbsensiQR_BackupCleanup" /tr "%PHP_PATH% %BACKEND_DIR%\scripts\backup_cleanup.php" /sc daily /st 01:00 /f

REM Backup verification daily at 03:00
schtasks /create /tn "AbsensiQR_BackupVerification" /tr "%PHP_PATH% %BACKEND_DIR%\scripts\backup_verifier.php" /sc daily /st 03:00 /f

REM Health check every hour
schtasks /create /tn "AbsensiQR_BackupHealthCheck" /tr "%PHP_PATH% %BACKEND_DIR%\scripts\backup_health_check.php" /sc hourly /f

echo.
echo ✅ Backup scheduled tasks have been set up successfully!
echo.
echo 📋 Scheduled backup operations:
echo   • Backup scheduler check: Every 5 minutes
echo   • Full backup: Sunday 02:00
echo   • Incremental backup: Daily 02:00
echo   • WAL/Binlog archive: Every 5 minutes
echo   • Cleanup: Daily 01:00
echo   • Verification: Daily 03:00
echo   • Health check: Every hour
echo.
echo 📝 Log files location: %BACKEND_DIR%\storage\logs\
echo.
echo 🔍 To view scheduled tasks: schtasks /query /tn "AbsensiQR_*"
echo 🗑️  To remove tasks: schtasks /delete /tn "AbsensiQR_*" /f

pause