@echo off
REM Simple test script to verify query plans
REM Task 13.4 - Verify query plans with EXPLAIN

echo ================================================================
echo   Query Plan Verification Test - Task 13.4
echo ================================================================
echo.

cd /d "%~dp0..\.."

echo Running benchmark command...
echo.

php artisan benchmark:queries --iterations=5

echo.
echo Verification complete!
echo.
echo Next steps:
echo   1. Review the output above
echo   2. Verify all indexes are being used
echo   3. Check performance metrics meet targets
echo   4. Document any issues found
echo.

pause
