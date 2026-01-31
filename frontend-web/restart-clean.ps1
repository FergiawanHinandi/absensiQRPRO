# Clean restart Vite dev server
Write-Host "🔄 Stopping all Node processes..." -ForegroundColor Yellow
Get-Process node -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 1

Write-Host "🗑️  Clearing Vite cache..." -ForegroundColor Yellow
if (Test-Path "node_modules\.vite") {
    Remove-Item -Path "node_modules\.vite" -Recurse -Force
    Write-Host "   ✅ Vite cache cleared" -ForegroundColor Green
} else {
    Write-Host "   ℹ️  No cache to clear" -ForegroundColor Gray
}

Write-Host ""
Write-Host "✅ Clean restart ready!" -ForegroundColor Green
Write-Host ""
Write-Host "📝 Next steps:" -ForegroundColor Cyan
Write-Host "   1. Run: npm run dev" -ForegroundColor White
Write-Host "   2. Clear browser cache (Ctrl+Shift+Delete)" -ForegroundColor White
Write-Host "   3. Select 'Cached images and files'" -ForegroundColor White
Write-Host "   4. Click 'Clear data'" -ForegroundColor White
Write-Host "   5. Open: http://localhost:5173/login" -ForegroundColor White
Write-Host ""
Write-Host "🔐 Login credentials:" -ForegroundColor Cyan
Write-Host "   Email: super@admin.com" -ForegroundColor White
Write-Host "   Password: password" -ForegroundColor White
