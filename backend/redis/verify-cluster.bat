@echo off
REM Redis Sentinel Cluster Verification Script for Windows
REM This script verifies the Redis Sentinel cluster is properly configured and operational

echo ========================================
echo Redis Sentinel Cluster Verification
echo ========================================
echo.

REM Check if Docker is running
docker info >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Docker is not running
    pause
    exit /b 1
)
echo [OK] Docker is running
echo.

REM Load environment variables
if not exist ".env" (
    echo [ERROR] .env file not found
    pause
    exit /b 1
)

REM Function to check container status
echo Checking Redis Nodes...
echo ------------------------
docker ps --format "{{.Names}}" | findstr /C:"redis-master" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] redis-master is running
) else (
    echo [ERROR] redis-master is not running
)

docker ps --format "{{.Names}}" | findstr /C:"redis-replica-1" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] redis-replica-1 is running
) else (
    echo [ERROR] redis-replica-1 is not running
)

docker ps --format "{{.Names}}" | findstr /C:"redis-replica-2" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] redis-replica-2 is running
) else (
    echo [ERROR] redis-replica-2 is not running
)

echo.
echo Checking Sentinel Nodes...
echo ---------------------------
docker ps --format "{{.Names}}" | findstr /C:"redis-sentinel-1" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] redis-sentinel-1 is running
) else (
    echo [ERROR] redis-sentinel-1 is not running
)

docker ps --format "{{.Names}}" | findstr /C:"redis-sentinel-2" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] redis-sentinel-2 is running
) else (
    echo [ERROR] redis-sentinel-2 is not running
)

docker ps --format "{{.Names}}" | findstr /C:"redis-sentinel-3" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] redis-sentinel-3 is running
) else (
    echo [ERROR] redis-sentinel-3 is not running
)

echo.
echo Checking Redis Connectivity...
echo -------------------------------
docker exec redis-master redis-cli -a "%REDIS_PASSWORD%" ping >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] redis-master is responding
) else (
    echo [ERROR] redis-master is not responding
)

docker exec redis-replica-1 redis-cli -a "%REDIS_PASSWORD%" ping >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] redis-replica-1 is responding
) else (
    echo [ERROR] redis-replica-1 is not responding
)

docker exec redis-replica-2 redis-cli -a "%REDIS_PASSWORD%" ping >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] redis-replica-2 is responding
) else (
    echo [ERROR] redis-replica-2 is not responding
)

echo.
echo Checking Sentinel Connectivity...
echo ----------------------------------
docker exec redis-sentinel-1 redis-cli -p 26379 ping >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Sentinel 1 is responding
) else (
    echo [ERROR] Sentinel 1 is not responding
)

docker exec redis-sentinel-2 redis-cli -p 26379 ping >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Sentinel 2 is responding
) else (
    echo [ERROR] Sentinel 2 is not responding
)

docker exec redis-sentinel-3 redis-cli -p 26379 ping >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Sentinel 3 is responding
) else (
    echo [ERROR] Sentinel 3 is not responding
)

echo.
echo Checking Replication Status...
echo -------------------------------
docker exec redis-master redis-cli -a "%REDIS_PASSWORD%" INFO replication 2>nul | findstr "connected_slaves"

echo.
echo Checking Sentinel Master Discovery...
echo --------------------------------------
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL get-master-addr-by-name mymaster 2>nul
if %errorlevel% equ 0 (
    echo [OK] Sentinel can discover master
) else (
    echo [ERROR] Sentinel cannot discover master
)

echo.
echo Checking Data Persistence...
echo ----------------------------
docker exec redis-master redis-cli -a "%REDIS_PASSWORD%" SET test_key "test_value" >nul 2>&1
timeout /t 1 /nobreak >nul

docker exec redis-replica-1 redis-cli -a "%REDIS_PASSWORD%" GET test_key 2>nul | findstr "test_value" >nul
if %errorlevel% equ 0 (
    echo [OK] Data is replicating to replicas
) else (
    echo [ERROR] Data is not replicating properly
)

docker exec redis-master redis-cli -a "%REDIS_PASSWORD%" DEL test_key >nul 2>&1

echo.
echo Checking Memory Usage...
echo ------------------------
echo Master memory usage:
docker exec redis-master redis-cli -a "%REDIS_PASSWORD%" INFO memory 2>nul | findstr "used_memory_human"

echo Replica 1 memory usage:
docker exec redis-replica-1 redis-cli -a "%REDIS_PASSWORD%" INFO memory 2>nul | findstr "used_memory_human"

echo Replica 2 memory usage:
docker exec redis-replica-2 redis-cli -a "%REDIS_PASSWORD%" INFO memory 2>nul | findstr "used_memory_human"

echo.
echo Checking Persistence Configuration...
echo --------------------------------------
docker exec redis-master redis-cli -a "%REDIS_PASSWORD%" CONFIG GET appendonly 2>nul | findstr "yes" >nul
if %errorlevel% equ 0 (
    echo [OK] AOF persistence is enabled
) else (
    echo [WARNING] AOF persistence is disabled
)

echo.
echo ========================================
echo Verification Complete
echo ========================================
echo.
echo Summary:
echo --------
echo Run 'docker-compose -f docker-compose.redis-sentinel.yml ps' to see all containers
echo Run 'docker-compose -f docker-compose.redis-sentinel.yml logs -f' to view logs
echo.
echo To test failover manually:
echo   docker stop redis-master
echo   docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL get-master-addr-by-name mymaster
echo   docker start redis-master
echo.
pause
