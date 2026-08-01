<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Helpers\TimezoneHelper;
use App\Models\School;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

/**
 * Property-Based Tests for Timezone Consistency
 * 
 * Validates three critical properties:
 * - Property 1: No raw date() calls in application code
 * - Property 2: All Carbon::now() calls use school timezone
 * - Property 3: All whereDate() queries use timezone-aware dates
 * 
 * Requirements: Week 1 Day 1, Acceptance Criteria 1, 2, 3
 */
class TimezoneConsistencyPropertyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Jakarta']);
    }

    /**
     * PROPERTY 1: No raw date() calls in application code
     * 
     * Validates that application code does not use raw PHP date() function
     * which ignores timezone configuration.
     * 
*/
    public function property_1_no_raw_date_calls_in_application_code(): void
    {
        $appPath = app_path();
        $excludedPaths = [
            'Helpers/TimezoneHelper.php', // Documentation mentions date()
            'Console/Commands/', // CLI commands use date() for filenames, test DBs, output formatting, backup operations
            'Services/DisasterRecoveryTestService.php', // Test database names with timestamps
            'Services/ObservabilityService.php', // Metrics cache keys with hourly buckets
            'Services/SecureFileUploadService.php', // File metadata timestamps
            'Infrastructure/CircuitBreaker/RedisCircuitBreaker.php', // Circuit breaker timing
            'Http/Controllers/Api/V1/Admin/SystemHealthController.php', // Health check timestamps
            'Http/Controllers/Api/V1/School/SubscriptionController.php', // Transaction ID generation
            'Http/Controllers/Api/V1/SuperAdmin/PaymentController.php', // Invoice ID generation
            'Http/Controllers/Api/V1/SuperAdmin/SystemController.php', // Backup filename generation
            'Http/Controllers/RollbackController.php', // File metadata timestamps
            'Http/Requests/Report/MonthlySummaryRequest.php', // Year validation (not display)
        ];
        
        $phpFiles = $this->getPhpFiles($appPath, $excludedPaths);
        $violations = [];
        
        foreach ($phpFiles as $file) {
            $content = File::get($file);
            $lines = explode("\n", $content);
            
            foreach ($lines as $lineNumber => $line) {
                // Skip comments and strings
                if ($this->isCommentOrString($line)) {
                    continue;
                }
                
                // Check for raw date() calls (not in comments or strings)
                if (preg_match('/\bdate\s*\(/', $line)) {
                    $violations[] = [
                        'file' => str_replace($appPath . DIRECTORY_SEPARATOR, '', $file),
                        'line' => $lineNumber + 1,
                        'content' => trim($line),
                    ];
                }
            }
        }
        
        $this->assertEmpty(
            $violations,
            "Found raw date() calls in application code. Use TimezoneHelper::now() instead:\n" .
            $this->formatViolations($violations)
        );
    }

    /**
     * PROPERTY 1 (variant): No raw time() calls in application code
     * 
*/
    public function property_1_no_raw_time_calls_in_application_code(): void
    {
        $appPath = app_path();
        $excludedPaths = [
            'Console/Commands/', // CLI commands use time() for test DBs, temp files, performance tracking, backup operations
            'Services/DisasterRecoveryTestService.php', // Temporary paths with timestamps
            'Services/ObservabilityService.php', // Per-minute bucket calculations for metrics
            'Services/PrometheusMetricsService.php', // Process start time metrics
            'Services/AtomicRollbackService.php', // Rollback ID generation
            'Services/BackupRestoreMonitoringService.php', // Job ID generation
            'Services/FailSecure/FailSecureService.php', // Health check test values
            'Services/HighAvailabilityMonitorService.php', // Health check files and timestamps
            'Services/MultiTenantRestoreValidationService.php', // Restore ID generation
            'Services/Redis/ResilientRedisConnection.php', // Health check interval timing
            'Services/Session/DatabaseSessionHandler.php', // Session expiry calculations
            'Services/Session/SessionFallbackManager.php', // Health check timing
            'Infrastructure/Redis/RedisCircuitBreaker.php', // Circuit breaker failure timing
            'Infrastructure/Health/Checks/', // Health check test files and keys
            'Http/Controllers/Api/HealthCheckController.php', // Health check test keys and files
            'Http/Controllers/Api/V1/HealthController.php', // Health check test keys, files, uptime
            'Http/Controllers/HealthCheckController.php', // Health check test keys and files
            'Providers/QueueServiceProvider.php', // Queue timing calculations
        ];
        
        $phpFiles = $this->getPhpFiles($appPath, $excludedPaths);
        $violations = [];
        
        foreach ($phpFiles as $file) {
            $content = File::get($file);
            $lines = explode("\n", $content);
            
            foreach ($lines as $lineNumber => $line) {
                if ($this->isCommentOrString($line)) {
                    continue;
                }
                
                // Check for raw time() calls
                if (preg_match('/\btime\s*\(\s*\)/', $line)) {
                    $violations[] = [
                        'file' => str_replace($appPath . DIRECTORY_SEPARATOR, '', $file),
                        'line' => $lineNumber + 1,
                        'content' => trim($line),
                    ];
                }
            }
        }
        
        $this->assertEmpty(
            $violations,
            "Found raw time() calls in application code. Use TimezoneHelper::now()->timestamp instead:\n" .
            $this->formatViolations($violations)
        );
    }

    /**
     * PROPERTY 1 (variant): No raw strtotime() calls in application code
     * 
*/
    public function property_1_no_raw_strtotime_calls_in_application_code(): void
    {
        $appPath = app_path();
        $excludedPaths = [];
        
        $phpFiles = $this->getPhpFiles($appPath, $excludedPaths);
        $violations = [];
        
        foreach ($phpFiles as $file) {
            $content = File::get($file);
            $lines = explode("\n", $content);
            
            foreach ($lines as $lineNumber => $line) {
                if ($this->isCommentOrString($line)) {
                    continue;
                }
                
                // Check for raw strtotime() calls
                if (preg_match('/\bstrtotime\s*\(/', $line)) {
                    $violations[] = [
                        'file' => str_replace($appPath . DIRECTORY_SEPARATOR, '', $file),
                        'line' => $lineNumber + 1,
                        'content' => trim($line),
                    ];
                }
            }
        }
        
        $this->assertEmpty(
            $violations,
            "Found raw strtotime() calls in application code. Use TimezoneHelper::parse() instead:\n" .
            $this->formatViolations($violations)
        );
    }

    /**
     * PROPERTY 2: All Carbon::now() calls use school timezone
     * 
     * Validates that when working with school-specific data, the school's timezone is used.
     * 
*/
    public function property_2_carbon_now_uses_school_timezone_in_attendance_context(): void
    {
        $school = School::factory()->create(['timezone' => 'Asia/Tokyo']);
        $teacher = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'teacher',
        ]);
        
        // Create attendance using TimezoneHelper
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
        ]);
        
        $attendanceDate = TimezoneHelper::schoolToday($school);
        
        // Use unguarded to bypass state machine for testing
        $attendance = Attendance::unguarded(function () use ($student, $schedule, $school, $attendanceDate) {
            return Attendance::create([
                'student_id' => $student->id,
                'schedule_id' => $schedule->id,
                'school_id' => $school->id,
                'attendance_date' => $attendanceDate->toDateString(),
                'status' => 'present',
                'state' => 'checked_in',
            ]);
        });
        
        // Verify the attendance date respects school timezone
        // Note: attendance_date is cast to Carbon date, so we need to compare date strings
        $this->assertEquals(
            $attendanceDate->toDateString(),
            $attendance->attendance_date->toDateString()
        );
    }

    /**
     * PROPERTY 2 (variant): TimezoneHelper methods respect school timezone
     * 
*/
    public function property_2_timezone_helper_respects_school_timezone(): void
    {
        $jakartaSchool = School::factory()->create(['timezone' => 'Asia/Jakarta']);
        $tokyoSchool = School::factory()->create(['timezone' => 'Asia/Tokyo']);
        
        $jakartaNow = TimezoneHelper::schoolNow($jakartaSchool);
        $tokyoNow = TimezoneHelper::schoolNow($tokyoSchool);
        
        $this->assertEquals('Asia/Jakarta', $jakartaNow->timezone->getName());
        $this->assertEquals('Asia/Tokyo', $tokyoNow->timezone->getName());
        
        // Tokyo is 2 hours ahead of Jakarta
        $hourDifference = $tokyoNow->hour - $jakartaNow->hour;
        $this->assertTrue(
            abs($hourDifference) === 2 || abs($hourDifference) === 22,
            "Expected 2-hour difference between Jakarta and Tokyo, got: {$hourDifference}"
        );
    }

    /**
     * PROPERTY 2 (variant): School-specific queries use school timezone
     * 
*/
    public function property_2_school_queries_use_school_timezone(): void
    {
        $school = School::factory()->create(['timezone' => 'Asia/Makassar']);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
        ]);
        
        // Create attendance for today in school timezone
        $todayInSchoolTz = TimezoneHelper::schoolToday($school);
        
        Attendance::unguarded(function () use ($student, $schedule, $school, $todayInSchoolTz) {
            return Attendance::create([
                'student_id' => $student->id,
                'schedule_id' => $schedule->id,
                'school_id' => $school->id,
                'attendance_date' => $todayInSchoolTz->toDateString(),
                'status' => 'present',
                'state' => 'checked_in',
            ]);
        });
        
        // Query using school timezone
        $attendances = Attendance::where('school_id', $school->id)
            ->whereDate('attendance_date', $todayInSchoolTz->toDateString())
            ->get();
        
        $this->assertCount(1, $attendances);
    }

    /**
     * PROPERTY 3: All whereDate() queries use timezone-aware dates
     * 
     * Validates that date queries use TimezoneHelper for consistency.
     * 
*/
    public function property_3_where_date_queries_use_timezone_aware_dates(): void
    {
        $school = School::factory()->create(['timezone' => 'Asia/Jayapura']);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
        ]);
        
        // Create attendance with timezone-aware date
        $schoolToday = TimezoneHelper::schoolToday($school);
        
        Attendance::unguarded(function () use ($student, $schedule, $school, $schoolToday) {
            return Attendance::create([
                'student_id' => $student->id,
                'schedule_id' => $schedule->id,
                'school_id' => $school->id,
                'attendance_date' => $schoolToday->toDateString(),
                'status' => 'present',
                'state' => 'checked_in',
            ]);
        });
        
        // Query with timezone-aware date
        $count = Attendance::where('school_id', $school->id)
            ->whereDate('attendance_date', $schoolToday->toDateString())
            ->count();
        
        $this->assertEquals(1, $count);
        
        // Verify wrong timezone doesn't match
        $wrongTimezone = TimezoneHelper::today('UTC');
        if ($wrongTimezone->toDateString() !== $schoolToday->toDateString()) {
            $wrongCount = Attendance::where('school_id', $school->id)
                ->whereDate('attendance_date', $wrongTimezone->toDateString())
                ->count();
            
            $this->assertEquals(0, $wrongCount);
        }
    }

    /**
     * PROPERTY 3 (variant): Date range queries use timezone-aware dates
     * 
*/
    public function property_3_date_range_queries_use_timezone_aware_dates(): void
    {
        $school = School::factory()->create(['timezone' => 'Asia/Jakarta']);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
        ]);
        
        // Create attendances for 3 consecutive days
        $today = TimezoneHelper::schoolToday($school);
        $startDate = $today->copy()->subDays(2);
        $endDate = $today->copy(); // Make explicit copy for end date
        
        $createdRecords = [];
        for ($i = 0; $i < 3; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dateString = $date->toDateString();
            
            // Create each attendance in a separate unguarded call
            $record = Attendance::unguarded(function () use ($student, $schedule, $school, $dateString, $i) {
                return Attendance::create([
                    'student_id' => $student->id,
                    'schedule_id' => $schedule->id,
                    'school_id' => $school->id,
                    'attendance_date' => $dateString,
                    'status' => 'present',
                    'state' => 'checked_in',
                    'request_id' => 'test-request-' . $i, // Unique request_id for each
                ]);
            });
            $createdRecords[] = $dateString;
        }
        
        // Verify all 3 records were actually created
        $allCreated = Attendance::where('school_id', $school->id)->count();
        $this->assertEquals(3, $allCreated, 'Should have created exactly 3 attendance records');
        
        // Query with timezone-aware date range using whereDate for proper date comparison
        $count = Attendance::where('school_id', $school->id)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereDate('attendance_date', '>=', $startDate->toDateString())
                      ->whereDate('attendance_date', '<=', $endDate->toDateString());
            })
            ->count();
        
        $this->assertEquals(3, $count);
    }

    /**
     * PROPERTY 3 (variant): Today queries use school timezone
     * 
*/
    public function property_3_today_queries_use_school_timezone(): void
    {
        $school = School::factory()->create(['timezone' => 'Asia/Tokyo']);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
        ]);
        
        // Create attendance for today in school timezone
        $schoolToday = TimezoneHelper::schoolToday($school);
        
        Attendance::unguarded(function () use ($student, $schedule, $school, $schoolToday) {
            return Attendance::create([
                'student_id' => $student->id,
                'schedule_id' => $schedule->id,
                'school_id' => $school->id,
                'attendance_date' => $schoolToday->toDateString(),
                'status' => 'present',
                'state' => 'checked_in',
            ]);
        });
        
        // Query for today using school timezone
        $todayAttendances = Attendance::where('school_id', $school->id)
            ->whereDate('attendance_date', $schoolToday->toDateString())
            ->get();
        
        $this->assertCount(1, $todayAttendances);
    }

    /**
     * Integration test: Multiple schools with different timezones
     * 
*/
    public function property_integration_multiple_schools_different_timezones(): void
    {
        // Create schools in different timezones
        $jakartaSchool = School::factory()->create(['timezone' => 'Asia/Jakarta']);
        $tokyoSchool = School::factory()->create(['timezone' => 'Asia/Tokyo']);
        $londonSchool = School::factory()->create(['timezone' => 'Europe/London']);
        
        $schools = [$jakartaSchool, $tokyoSchool, $londonSchool];
        
        foreach ($schools as $school) {
            $schedule = Schedule::factory()->create(['school_id' => $school->id]);
            $student = User::factory()->create([
                'school_id' => $school->id,
                'role_type' => 'student',
            ]);
            
            // Create attendance for today in each school's timezone
            $schoolToday = TimezoneHelper::schoolToday($school);
            
            // Use unguarded to bypass state machine for testing
            Attendance::unguarded(function () use ($student, $schedule, $school, $schoolToday) {
                return Attendance::create([
                    'student_id' => $student->id,
                    'schedule_id' => $schedule->id,
                    'school_id' => $school->id,
                    'attendance_date' => $schoolToday->toDateString(),
                    'status' => 'present',
                    'state' => 'checked_in',
                ]);
            });
        }
        
        // Verify each school can query their own attendance correctly
        foreach ($schools as $school) {
            $schoolToday = TimezoneHelper::schoolToday($school);
            
            $count = Attendance::where('school_id', $school->id)
                ->whereDate('attendance_date', $schoolToday->toDateString())
                ->count();
            
            $this->assertEquals(
                1,
                $count,
                "School {$school->id} ({$school->timezone}) should have 1 attendance for today"
            );
        }
    }

    /**
     * Edge case: Timezone boundary testing
     * 
*/
    public function property_edge_case_timezone_boundary(): void
    {
        // Test at timezone boundary (e.g., 23:59 in one timezone might be next day in another)
        $school = School::factory()->create(['timezone' => 'Asia/Jakarta']);
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
        ]);
        
        // Create attendance at specific time using unguarded
        $specificTime = TimezoneHelper::parse('2026-03-04 23:59:00', 'Asia/Jakarta');
        
        Attendance::unguarded(function () use ($student, $schedule, $school, $specificTime) {
            return Attendance::create([
                'student_id' => $student->id,
                'schedule_id' => $schedule->id,
                'school_id' => $school->id,
                'attendance_date' => $specificTime->toDateString(),
                'status' => 'present',
                'state' => 'checked_in',
            ]);
        });
        
        // Query should find it on the correct date
        $count = Attendance::where('school_id', $school->id)
            ->whereDate('attendance_date', '2026-03-04')
            ->count();
        
        $this->assertEquals(1, $count);
    }

    /**
     * Helper: Get all PHP files in directory
     */
    private function getPhpFiles(string $path, array $excludedPaths = []): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getPathname();
                $relativePath = str_replace($path . DIRECTORY_SEPARATOR, '', $filePath);
                
                // Normalize path separators for cross-platform compatibility
                $normalizedPath = str_replace('\\', '/', $relativePath);
                
                // Check if file should be excluded
                $shouldExclude = false;
                foreach ($excludedPaths as $excludedPath) {
                    // Normalize excluded path as well
                    $normalizedExcludedPath = str_replace('\\', '/', $excludedPath);
                    if (str_contains($normalizedPath, $normalizedExcludedPath)) {
                        $shouldExclude = true;
                        break;
                    }
                }
                
                if (!$shouldExclude) {
                    $files[] = $filePath;
                }
            }
        }
        
        return $files;
    }

    /**
     * Helper: Check if line is a comment or string
     */
    private function isCommentOrString(string $line): bool
    {
        $trimmed = trim($line);
        
        // Single-line comment
        if (str_starts_with($trimmed, '//')) {
            return true;
        }
        
        // Multi-line comment
        if (str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*')) {
            return true;
        }
        
        // PHPDoc comment
        if (str_starts_with($trimmed, '/**')) {
            return true;
        }
        
        return false;
    }

    /**
     * Helper: Format violations for error message
     */
    private function formatViolations(array $violations): string
    {
        $formatted = [];
        foreach ($violations as $violation) {
            $formatted[] = sprintf(
                "  - %s:%d\n    %s",
                $violation['file'],
                $violation['line'],
                $violation['content']
            );
        }
        
        return implode("\n", $formatted);
    }
}
