@echo off
REM ============================================================================
REM Security Setup Script - AbsensiQRPro
REM ============================================================================
REM 
REM This script configures Git hooks for secret detection
REM 
REM Usage:
REM   setup-security.bat
REM ============================================================================

echo.
echo ╔══════════════════════════════════════════════════════════════╗
echo ║  AbsensiQRPro - Security Setup                               ║
echo ╚══════════════════════════════════════════════════════════════╝
echo.

echo [92m🔒 Configuring Git hooks for secret detection...[0m
echo.

REM Configure Git to use .githooks directory
git config core.hooksPath .githooks

if %ERRORLEVEL% EQU 0 (
    echo [92m✅ Git hooks configured successfully![0m
    echo.
    
    REM Verify configuration
    echo [96mVerifying configuration...[0m
    git config core.hooksPath
    echo.
    
    echo [92m✅ Security setup complete![0m
    echo.
    echo [93mWhat's protected:[0m
    echo   - APP_KEY with actual values
    echo   - DB_PASSWORD with real passwords
    echo   - QR_SECRET_KEY with actual keys
    echo   - API tokens and Bearer tokens
    echo   - Private keys and certificates
    echo   - AWS credentials
    echo   - .env files
    echo.
    echo [93mNext steps:[0m
    echo   1. Start developing with confidence
    echo   2. The pre-commit hook will automatically scan your commits
    echo   3. Read docs/SECURITY_GUIDELINES.md for best practices
    echo.
    echo [96mTest the hook:[0m
    echo   echo APP_KEY=base64:testkey123 ^> test.txt
    echo   git add test.txt
    echo   git commit -m "test"
    echo   # Should be BLOCKED
    echo.
) else (
    echo [91m❌ Failed to configure Git hooks[0m
    echo.
    echo [93mTroubleshooting:[0m
    echo   1. Make sure you're in the project root directory
    echo   2. Ensure Git is installed and in PATH
    echo   3. Check that .githooks directory exists
    echo.
    exit /b 1
)

echo [92mPress any key to continue...[0m
pause >nul
