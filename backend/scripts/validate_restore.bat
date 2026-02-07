@echo off
setlocal enabledelayedexpansion

REM Multi-Tenant Restore Validation Script for Windows
REM Usage: validate_restore.bat <backup_file> <school_id> [--report]

set "RED=[91m"
set "GREEN=[92m"
set "YELLOW=[93m"
set "BLUE=[94m"
set "NC=[0m"

if "%~1"=="" (
    echo %RED%❌ Error: Missing backup file argument%NC%
    echo Usage: %0 ^<backup_file^> ^<school_id^> [--report]
    echo Example: %0 backup.sql 123 --report
    exit /b 1
)

if "%~2"=="" (
    echo %RED%❌ Error: Missing school ID argument%NC%
    echo Usage: %0 ^<backup_file^> ^<school_id^> [--report]
    echo Example: %0 backup.sql 123 --report
    exit /b 1
)

set "BACKUP_FILE=%~1"
set "SCHOOL_ID=%~2"
set "GENERATE_REPORT="

if "%~3"=="--report" set "GENERATE_REPORT=--report"

REM Validate school ID is numeric
echo %SCHOOL_ID%| findstr /r "^[0-9][0-9]*$" >nul
if errorlevel 1 (
    echo %RED%❌ Error: School ID must be a positive integer%NC%
    exit /b 1
)

REM Check if backup file exists
if not exist "%BACKUP_FILE%" (
    echo %RED%❌ Error: Backup file not found: %BACKUP_FILE%%NC%
    exit /b 1
)

echo %BLUE%🔍 Starting Multi-Tenant Restore Validation%NC%
echo %BLUE%==========================================%NC%
echo.
echo %BLUE%📁 Backup File: %BACKUP_FILE%%NC%
echo %BLUE%🏫 Target School ID: %SCHOOL_ID%%NC%
echo.

REM Change to Laravel project directory
cd /d "%~dp0\.."

REM Run Laravel validation command
echo %YELLOW%⏳ Running validation checks...%NC%

php artisan restore:validate-multi-tenant "%BACKUP_FILE%" "%SCHOOL_ID%" %GENERATE_REPORT%
set "VALIDATION_EXIT_CODE=%errorlevel%"

if %VALIDATION_EXIT_CODE% equ 0 (
    echo.
    echo %GREEN%✅ Validation PASSED - Backup is safe for restore%NC%
    
    REM Additional safety checks
    echo %BLUE%🔍 Performing additional safety checks...%NC%
    
    REM Check for potentially dangerous SQL operations
    findstr /i "DROP DELETE TRUNCATE" "%BACKUP_FILE%" >nul 2>&1
    if not errorlevel 1 (
        echo %YELLOW%⚠️  Warning: Backup contains potentially dangerous SQL operations%NC%
        echo %YELLOW%   Please review the backup file manually before proceeding%NC%
    )
    
    echo %GREEN%🎉 Backup validation completed successfully%NC%
) else (
    echo.
    echo %RED%❌ Validation FAILED - Backup is NOT safe for restore%NC%
    echo %RED%🚨 DO NOT proceed with restore until all issues are resolved%NC%
    
    echo.
    echo %BLUE%💡 Suggested next steps:%NC%
    echo    1. Review the validation errors above
    echo    2. Fix the issues in the backup file
    echo    3. Re-run this validation script
    echo    4. Test restore in staging environment
    echo    5. Contact support if issues persist
)

echo.
echo %BLUE%📋 Validation Summary:%NC%
echo    • Exit Code: %VALIDATION_EXIT_CODE%
echo    • Timestamp: %date% %time%
echo    • User: %username%
echo    • Host: %computername%

exit /b %VALIDATION_EXIT_CODE%
