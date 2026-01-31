@echo off
title AbsensiQR Pro - Dev Launcher
color 0A

echo ===================================================
echo      ABSENSI QR PRO - DEVELOPMENT LAUNCHER
echo ===================================================
echo.

:: 1. Start Laravel Backend
echo [1/4] Starting Laravel Backend Server...
start "Backend - API (Port 8000)" /D "backend" cmd /k "php artisan serve"

:: 2. Start Laravel Reverb (WebSocket)
echo [2/4] Starting Reverb WebSocket Server...
start "Backend - WebSocket (Port 8080)" /D "backend" cmd /k "php artisan reverb:start"

:: 3. Start Laravel Queue Worker
echo [3/4] Starting Queue Worker...
start "Backend - Queue Worker" /D "backend" cmd /k "php artisan queue:listen --tries=3"

:: 4. Start Frontend
echo [4/4] Starting React Frontend...
start "Frontend - Web App (Port 5173)" /D "frontend-web" cmd /k "npm run dev"

echo.
echo ===================================================
echo SYSTEM RUNNING!
echo.
echo [URL] Frontend: http://localhost:5173
echo [URL] Backend:  http://localhost:8000
echo.
echo Please wait while the browser opens...
echo ===================================================

timeout /t 8 >nul
start http://localhost:5173
