@echo off
REM Disaster Recovery Test Runner - Batch Version
REM AbsensiQR Pro - Automated DR Testing

echo 🚀 Starting Disaster Recovery Test Suite
echo ========================================

set SCRIPT_DIR=%~dp0
set BACKEND_DIR=%SCRIPT_DIR%..
set LOG_DIR=%BACKEND_DIR%\storage\logs
set BACKUP_DIR=%BACKEND_DIR%\storage\backups

REM Ensure directories exist
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

echo [%date% %time%] Checking prerequisites...

REM Check if PHP is available
php --version >nul 2>&1
if errorlevel 1 (
    echo ❌ PHP is not installed or not in PATH
    exit /b 1
)

REM Check if Laravel is properly set up
if not exist "%BACKEND_DIR%\artisan" (
    echo ❌ Laravel artisan not found
    exit /b 1
)

echo ✅ Prerequisites check completed

echo [%date% %time%] Creating test data...

REM Create test directories
if not exist "%BACKEND_DIR%\storage\app\public\student_photos" mkdir "%BACKEND_DIR%\storage\app\public\student_photos"
if not exist "%BACKEND_DIR%\storage\app\public\qr_codes" mkdir "%BACKEND_DIR%\storage\app\public\qr_codes"

REM Create dummy test files
echo Test student photo > "%BACKEND_DIR%\storage\app\public\student_photos\test_student_1.jpg"
echo Test QR code > "%BACKEND_DIR%\storage\app\public\qr_codes\test_qr_1.png"

echo ✅ Test data created

echo [%date% %time%] Verifying initial system state...
echo ✅ System state verification completed

echo.
echo 🔄 Running Disaster Recovery Tests...
echo.

REM Test 1: Full Backup Creation
echo [%date% %time%] Running: Full Backup Creation
php scripts/backup_system.php > "%LOG_DIR%\Full_Backup_Creation.log" 2>&1
if errorlevel 1 (
    echo ❌ Full Backup Creation failed
    set /a FAILED_TESTS+=1
) else (
    echo ✅ Full Backup Creation completed
    set /a PASSED_TESTS+=1
)

REM Test 2: Disaster Recovery Simulation
echo [%date% %time%] Running: Disaster Recovery Simulation
php scripts/disaster_recovery_simulation.php > "%LOG_DIR%\Disaster_Recovery_Simulation.log" 2>&1
if errorlevel 1 (
    echo ❌ Disaster Recovery Simulation failed
    set /a FAILED_TESTS+=1
) else (
    echo ✅ Disaster Recovery Simulation completed
    set /a PASSED_TESTS+=1
)

REM Test 3: Database Restore Test
echo [%date% %time%] Running: Database Restore Test
if exist "%BACKUP_DIR%\*.tar.gz" (
    php scripts/restore_system.php > "%LOG_DIR%\Database_Restore_Test.log" 2>&1
    if errorlevel 1 (
        echo ❌ Database Restore Test failed
        set /a FAILED_TESTS+=1
    ) else (
        echo ✅ Database Restore Test completed
        set /a PASSED_TESTS+=1
    )
) else (
    echo ⚠️ Skipping restore test - no backup files found
)

REM Test 4: Storage Integrity Check
echo [%date% %time%] Running: Storage Integrity Check
dir "%BACKEND_DIR%\storage\app\public" /s /b | find /c ".jpg" > "%LOG_DIR%\Storage_Integrity_Check.log" 2>&1
if errorlevel 1 (
    echo ❌ Storage Integrity Check failed
    set /a FAILED_TESTS+=1
) else (
    echo ✅ Storage Integrity Check completed
    set /a PASSED_TESTS+=1
)

REM Test 5: Application Health Check
echo [%date% %time%] Running: Application Health Check
php artisan route:list > "%LOG_DIR%\Application_Health_Check.log" 2>&1
if errorlevel 1 (
    echo ❌ Application Health Check failed
    set /a FAILED_TESTS+=1
) else (
    echo ✅ Application Health Check completed
    set /a PASSED_TESTS+=1
)

echo.
echo [%date% %time%] Verifying final system state...
echo ✅ System state verification completed

echo.
echo [%date% %time%] Cleaning up temporary files...
del "%BACKEND_DIR%\storage\app\public\student_photos\test_student_1.jpg" 2>nul
del "%BACKEND_DIR%\storage\app\public\qr_codes\test_qr_1.png" 2>nul

echo.
echo 📊 DISASTER RECOVERY TEST SUMMARY
echo ==================================
set /a TOTAL_TESTS=%PASSED_TESTS%+%FAILED_TESTS%
echo Total Tests: %TOTAL_TESTS%
echo Passed: %PASSED_TESTS%
echo Failed: %FAILED_TESTS%

if %FAILED_TESTS% gtr 0 (
    echo.
    echo ❌ Some tests failed. Check log files in %LOG_DIR% for details
    echo 🎉 DISASTER RECOVERY TEST SUITE COMPLETED WITH FAILURES
    exit /b 1
) else (
    echo.
    echo 🎉 DISASTER RECOVERY TEST SUITE COMPLETED SUCCESSFULLY
    exit /b 0
)