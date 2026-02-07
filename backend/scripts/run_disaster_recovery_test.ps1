# Disaster Recovery Test Runner - PowerShell Version
# AbsensiQR Pro - Automated DR Testing

param(
    [string]$LogLevel = "INFO"
)

# Configuration
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$BackendDir = Split-Path -Parent $ScriptDir
$LogDir = Join-Path $BackendDir "storage\logs"
$BackupDir = Join-Path $BackendDir "storage\backups"
$TestResultsFile = Join-Path $LogDir "dr_test_results_$(Get-Date -Format 'yyyyMMdd_HHmmss').json"

# Ensure directories exist
if (!(Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force }
if (!(Test-Path $BackupDir)) { New-Item -ItemType Directory -Path $BackupDir -Force }

# Colors for output
$Colors = @{
    Red = "Red"
    Green = "Green"
    Yellow = "Yellow"
    Blue = "Blue"
    White = "White"
}

# Function to log with timestamp
function Write-Log {
    param([string]$Message, [string]$Color = "White")
    $Timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $LogMessage = "[$Timestamp] $Message"
    Write-Host $LogMessage -ForegroundColor $Color
}

# Function to run test and capture results
function Invoke-Test {
    param(
        [string]$TestName,
        [string]$TestCommand,
        [string]$WorkingDirectory = $BackendDir
    )
    
    $StartTime = Get-Date
    Write-Log "Running: $TestName" $Colors.Blue
    
    try {
        $LogFile = Join-Path $LogDir "$($TestName -replace ' ', '_').log"
        
        # Execute command and capture output
        $Process = Start-Process -FilePath "powershell" -ArgumentList "-Command", $TestCommand -WorkingDirectory $WorkingDirectory -RedirectStandardOutput $LogFile -RedirectStandardError $LogFile -Wait -PassThru -NoNewWindow
        
        $EndTime = Get-Date
        $Duration = [math]::Round(($EndTime - $StartTime).TotalSeconds, 2)
        
        if ($Process.ExitCode -eq 0) {
            Write-Log "✅ $TestName completed in ${Duration}s" $Colors.Green
            $TestResult = @{
                test = $TestName
                status = "success"
                duration = $Duration
                timestamp = $StartTime.ToString("yyyy-MM-ddTHH:mm:ss")
            }
        } else {
            Write-Log "❌ $TestName failed after ${Duration}s" $Colors.Red
            $TestResult = @{
                test = $TestName
                status = "failed"
                duration = $Duration
                timestamp = $StartTime.ToString("yyyy-MM-ddTHH:mm:ss")
            }
        }
        
        return $TestResult
    }
    catch {
        $EndTime = Get-Date
        $Duration = [math]::Round(($EndTime - $StartTime).TotalSeconds, 2)
        Write-Log "❌ $TestName failed with exception after ${Duration}s: $($_.Exception.Message)" $Colors.Red
        
        return @{
            test = $TestName
            status = "failed"
            duration = $Duration
            timestamp = $StartTime.ToString("yyyy-MM-ddTHH:mm:ss")
            error = $_.Exception.Message
        }
    }
}

# Function to check prerequisites
function Test-Prerequisites {
    Write-Log "Checking prerequisites..." $Colors.Blue
    
    # Check if PHP is available
    try {
        $PhpVersion = & php --version 2>$null
        if ($LASTEXITCODE -ne 0) {
            Write-Log "❌ PHP is not installed or not in PATH" $Colors.Red
            return $false
        }
    }
    catch {
        Write-Log "❌ PHP is not installed or not in PATH" $Colors.Red
        return $false
    }
    
    # Check if Laravel is properly set up
    $ArtisanPath = Join-Path $BackendDir "artisan"
    if (!(Test-Path $ArtisanPath)) {
        Write-Log "❌ Laravel artisan not found" $Colors.Red
        return $false
    }
    
    Write-Log "✅ Prerequisites check completed" $Colors.Green
    return $true
}

# Function to create test data
function New-TestData {
    Write-Log "Creating test data..." $Colors.Blue
    
    # Create test directories
    $StudentPhotosDir = Join-Path $BackendDir "storage\app\public\student_photos"
    $QrCodesDir = Join-Path $BackendDir "storage\app\public\qr_codes"
    
    if (!(Test-Path $StudentPhotosDir)) { New-Item -ItemType Directory -Path $StudentPhotosDir -Force }
    if (!(Test-Path $QrCodesDir)) { New-Item -ItemType Directory -Path $QrCodesDir -Force }
    
    # Create dummy test files
    "Test student photo" | Out-File -FilePath (Join-Path $StudentPhotosDir "test_student_1.jpg") -Encoding UTF8
    "Test QR code" | Out-File -FilePath (Join-Path $QrCodesDir "test_qr_1.png") -Encoding UTF8
    
    Write-Log "✅ Test data created" $Colors.Green
}

# Function to verify system state
function Test-SystemState {
    param([string]$Phase)
    
    Write-Log "Verifying system state ($Phase)..." $Colors.Blue
    
    # Check database tables (simplified for PowerShell)
    try {
        $UserCount = & php artisan tinker --execute="echo DB::table('users')->count();" 2>$null | Select-Object -Last 1
        $SchoolCount = & php artisan tinker --execute="echo DB::table('schools')->count();" 2>$null | Select-Object -Last 1
        
        Write-Log "  📊 users: $UserCount records"
        Write-Log "  📊 schools: $SchoolCount records"
    }
    catch {
        Write-Log "  ⚠️ Could not verify database state: $($_.Exception.Message)" $Colors.Yellow
    }
    
    # Check storage files
    $StudentPhotos = @(Get-ChildItem -Path (Join-Path $BackendDir "storage\app\public\student_photos") -File -ErrorAction SilentlyContinue).Count
    $QrCodes = @(Get-ChildItem -Path (Join-Path $BackendDir "storage\app\public\qr_codes") -File -ErrorAction SilentlyContinue).Count
    
    Write-Log "  📁 Student photos: $StudentPhotos files"
    Write-Log "  📁 QR codes: $QrCodes files"
    
    Write-Log "✅ System state verification completed" $Colors.Green
}

# Function to generate summary report
function New-SummaryReport {
    param([array]$TestResults)
    
    Write-Log "Generating summary report..." $Colors.Blue
    
    $TotalTests = $TestResults.Count
    $PassedTests = ($TestResults | Where-Object { $_.status -eq "success" }).Count
    $FailedTests = ($TestResults | Where-Object { $_.status -eq "failed" }).Count
    $TotalDuration = ($TestResults | Measure-Object -Property duration -Sum).Sum
    
    Write-Host ""
    Write-Host "📊 DISASTER RECOVERY TEST SUMMARY" -ForegroundColor $Colors.Blue
    Write-Host "==================================" -ForegroundColor $Colors.Blue
    Write-Host "Total Tests: $TotalTests"
    Write-Host "Passed: $PassedTests" -ForegroundColor $Colors.Green
    Write-Host "Failed: $FailedTests" -ForegroundColor $(if ($FailedTests -gt 0) { $Colors.Red } else { $Colors.Green })
    Write-Host "Total Duration: ${TotalDuration}s"
    Write-Host "Success Rate: $([math]::Round($PassedTests * 100 / $TotalTests, 1))%"
    Write-Host ""
    Write-Host "Detailed results saved to: $TestResultsFile"
    
    # Show failed tests if any
    if ($FailedTests -gt 0) {
        Write-Host ""
        Write-Log "❌ FAILED TESTS:" $Colors.Red
        $TestResults | Where-Object { $_.status -eq "failed" } | ForEach-Object {
            Write-Host "  - $($_.test)" -ForegroundColor $Colors.Red
        }
        Write-Host ""
        Write-Log "Check individual log files in $LogDir for details" $Colors.Yellow
    }
    
    # Save results to JSON
    $TestResults | ConvertTo-Json -Depth 3 | Out-File -FilePath $TestResultsFile -Encoding UTF8
}

# Cleanup function
function Remove-TestData {
    Write-Log "Cleaning up temporary files..." $Colors.Blue
    
    $TestFiles = @(
        (Join-Path $BackendDir "storage\app\public\student_photos\test_student_1.jpg"),
        (Join-Path $BackendDir "storage\app\public\qr_codes\test_qr_1.png")
    )
    
    foreach ($File in $TestFiles) {
        if (Test-Path $File) {
            Remove-Item $File -Force -ErrorAction SilentlyContinue
        }
    }
}

# Main execution
function Main {
    Write-Log "🔥 DISASTER RECOVERY TEST SUITE STARTED" $Colors.Blue
    
    # Check prerequisites
    if (!(Test-Prerequisites)) {
        Write-Log "❌ Prerequisites check failed. Exiting." $Colors.Red
        exit 1
    }
    
    # Create test data
    New-TestData
    
    # Verify initial system state
    Test-SystemState "initial"
    
    # Initialize test results array
    $TestResults = @()
    
    # Test 1: Full Backup Creation
    $TestResults += Invoke-Test "Full Backup Creation" "php scripts/backup_system.php"
    
    # Test 2: Disaster Recovery Simulation
    $TestResults += Invoke-Test "Disaster Recovery Simulation" "php scripts/disaster_recovery_simulation.php"
    
    # Test 3: Database Restore Test
    if ((Get-ChildItem -Path $BackupDir -Filter "*.tar.gz" -ErrorAction SilentlyContinue) -or 
        (Get-ChildItem -Path $BackupDir -Filter "full_backup_*" -Directory -ErrorAction SilentlyContinue)) {
        $TestResults += Invoke-Test "Database Restore Test" "php scripts/restore_system.php"
    } else {
        Write-Log "⚠️ Skipping restore test - no backup files found" $Colors.Yellow
    }
    
    # Test 4: Storage Integrity Check
    $TestResults += Invoke-Test "Storage Integrity Check" "Get-ChildItem -Path 'storage\app\public' -Recurse -File | Select-Object -First 5 | Measure-Object | Select-Object -ExpandProperty Count"
    
    # Test 5: Application Health Check
    $TestResults += Invoke-Test "Application Health Check" "php artisan route:list --compact"
    
    # Verify final system state
    Test-SystemState "final"
    
    # Generate summary report
    New-SummaryReport $TestResults
    
    # Cleanup
    Remove-TestData
    
    Write-Log "🎉 DISASTER RECOVERY TEST SUITE COMPLETED" $Colors.Green
    
    # Return exit code based on test results
    $FailedCount = ($TestResults | Where-Object { $_.status -eq "failed" }).Count
    if ($FailedCount -gt 0) {
        exit 1
    } else {
        exit 0
    }
}

# Run main function
try {
    Main
}
catch {
    Write-Log "❌ Test suite failed with exception: $($_.Exception.Message)" $Colors.Red
    exit 1
}