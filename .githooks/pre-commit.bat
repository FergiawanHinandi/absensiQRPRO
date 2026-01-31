@echo off
REM ============================================================================
REM Pre-commit Hook: Secret Detection & Security Validation (Windows)
REM ============================================================================
REM 
REM This hook prevents committing sensitive data to the repository.
REM 
REM Installation:
REM   git config core.hooksPath .githooks
REM ============================================================================

echo [92m🔒 Running secret detection pre-commit hook...[0m

REM Get list of staged files
git diff --cached --name-only --diff-filter=ACM > %TEMP%\staged_files.txt

set SECRETS_FOUND=0

REM Pattern 1: APP_KEY with actual value
git diff --cached | findstr /R "^\+.*APP_KEY=base64:" > nul
if %ERRORLEVEL% EQU 0 (
    echo [91m❌ BLOCKED: APP_KEY with actual value detected![0m
    echo [93m   Never commit APP_KEY from .env file[0m
    set SECRETS_FOUND=1
)

REM Pattern 2: Database passwords
git diff --cached | findstr /R "^\+.*DB_PASSWORD=" | findstr /V "your-secure-password" > nul
if %ERRORLEVEL% EQU 0 (
    echo [91m❌ BLOCKED: DB_PASSWORD with actual value detected![0m
    echo [93m   Never commit real database passwords[0m
    set SECRETS_FOUND=1
)

REM Pattern 3: QR_SECRET_KEY
git diff --cached | findstr /R "^\+.*QR_SECRET_KEY=" | findstr /V "your-secret-key-here" > nul
if %ERRORLEVEL% EQU 0 (
    echo [91m❌ BLOCKED: QR_SECRET_KEY with actual value detected![0m
    echo [93m   Never commit QR secret keys[0m
    set SECRETS_FOUND=1
)

REM Pattern 4: Private keys
git diff --cached | findstr /R "^\+.*-----BEGIN.*PRIVATE KEY-----" > nul
if %ERRORLEVEL% EQU 0 (
    echo [91m❌ BLOCKED: Private key detected![0m
    echo [93m   Never commit private keys or certificates[0m
    set SECRETS_FOUND=1
)

REM Pattern 5: Check for .env file
findstr /C:".env" %TEMP%\staged_files.txt | findstr /V ".env.example" > nul
if %ERRORLEVEL% EQU 0 (
    echo [91m❌ BLOCKED: Attempting to commit .env file![0m
    echo [93m   The .env file should NEVER be committed[0m
    echo    Run: git reset HEAD .env
    set SECRETS_FOUND=1
)

REM Pattern 6: Certificate files
findstr /R "\.pem$ \.key$ \.p12$ \.pfx$" %TEMP%\staged_files.txt > nul
if %ERRORLEVEL% EQU 0 (
    echo [91m❌ BLOCKED: Certificate or key file detected![0m
    echo [93m   Never commit certificate or key files[0m
    set SECRETS_FOUND=1
)

REM Clean up temp file
del %TEMP%\staged_files.txt

REM If secrets found, block the commit
if %SECRETS_FOUND% EQU 1 (
    echo.
    echo [91m╔══════════════════════════════════════════════════════════════╗[0m
    echo [91m║  COMMIT BLOCKED: Secrets detected in staged changes         ║[0m
    echo [91m╚══════════════════════════════════════════════════════════════╝[0m
    echo.
    echo [93mWhat to do:[0m
    echo   1. Remove secrets from staged files
    echo   2. Use environment variables instead
    echo   3. Check docs/SECURITY_GUIDELINES.md for best practices
    echo.
    echo [93mTo unstage files:[0m
    echo   git reset HEAD ^<file^>
    echo.
    echo [93mTo bypass this hook (NOT RECOMMENDED):[0m
    echo   git commit --no-verify
    echo.
    exit /b 1
)

REM Success
echo [92m✅ No secrets detected - commit allowed[0m
exit /b 0
