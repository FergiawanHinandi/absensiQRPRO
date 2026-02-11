# Webhook Tests Verification Script
# Run this from the backend directory: .\verify-webhook-tests.ps1

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Webhook Concurrency Tests Verification" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check if we're in the backend directory
if (-not (Test-Path "vendor/phpunit/phpunit/phpunit")) {
    Write-Host "ERROR: Please run this script from the backend directory" -ForegroundColor Red
    exit 1
}

# Check if test file exists
if (-not (Test-Path "tests/Feature/WebhookConcurrencyTest.php")) {
    Write-Host "ERROR: WebhookConcurrencyTest.php not found" -ForegroundColor Red
    exit 1
}

Write-Host "✓ Test file found" -ForegroundColor Green
Write-Host ""

# Run the tests
Write-Host "Running webhook tests..." -ForegroundColor Yellow
Write-Host ""

& php vendor/phpunit/phpunit/phpunit --filter=WebhookConcurrencyTest --testdox

if ($LASTEXITCODE -eq 0) {
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Green
    Write-Host "✓ ALL TESTS PASSED!" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Task 8.3 is complete. All 12 webhook tests are passing." -ForegroundColor Green
} else {
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Red
    Write-Host "✗ SOME TESTS FAILED" -ForegroundColor Red
    Write-Host "========================================" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please review the errors above and fix any issues." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "For detailed output, run:" -ForegroundColor Cyan
Write-Host "  php vendor/phpunit/phpunit/phpunit --filter=WebhookConcurrencyTest" -ForegroundColor White
Write-Host ""
