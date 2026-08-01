@echo off
REM Redis Sentinel Cluster Startup Script for Windows
REM This script starts the Redis Sentinel high availability cluster

echo ========================================
echo Redis Sentinel Cluster Startup
echo ========================================
echo.

REM Check if Docker is running
docker info >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Docker is not running!
    echo Please start Docker Desktop and try again.
    pause
    exit /b 1
)

echo Docker is running...
echo.

REM Check if .env file exists
if not exist ".env" (
    echo WARNING: .env file not found!
    echo Creating .env from .env.example...
    copy .env.example .env
    echo.
    echo IMPORTANT: Please edit redis/.env and set a strong REDIS_PASSWORD
    echo Then run this script again.
    pause
    exit /b 1
)

echo Starting Redis Sentinel cluster...
echo.

REM Navigate to backend directory and start cluster
cd ..
docker-compose -f docker-compose.redis-sentinel.yml up -d

if %errorlevel% neq 0 (
    echo.
    echo ERROR: Failed to start Redis Sentinel cluster!
    pause
    exit /b 1
)

echo.
echo ========================================
echo Redis Sentinel cluster started successfully!
echo ========================================
echo.
echo Services running:
echo - Redis Master: localhost:6379
echo - Redis Replica 1: localhost:6380
echo - Redis Replica 2: localhost:6381
echo - Sentinel 1: localhost:26379
echo - Sentinel 2: localhost:26380
echo - Sentinel 3: localhost:26381
echo.
echo To check cluster status:
echo   docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL masters
echo.
echo To view logs:
echo   docker-compose -f docker-compose.redis-sentinel.yml logs -f
echo.
echo To stop cluster:
echo   docker-compose -f docker-compose.redis-sentinel.yml down
echo.
pause
