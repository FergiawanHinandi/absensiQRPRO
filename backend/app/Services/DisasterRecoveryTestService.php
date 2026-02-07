<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Process;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Carbon\Carbon;
use ZipArchive;

class DisasterRecoveryTestService
{
    private string $tempPath;
    private string $stagingDbName;
    private array $results = [];
    private ?string $extractedSqlPath = null;

    public function __construct()
    {
        $this->tempPath = storage_path('app/dr-testing-' . time());
        $this->stagingDbName = 'absensi_staging_dr_' . date('Ymd_His');
    }

    /**
     * Run complete DR test workflow
     */
    public function runFullTest(string $diskName = 'local', bool $createFreshBackup = false): array
    {
        $startTime = microtime(true);

        try {
            $this->results = [
                'test_id' => uniqid('dr_'),
                'started_at' => now()->toIso8601String(),
                'staging_db' => $this->stagingDbName,
                'steps' => [],
                'integrity_checks' => [],
                'overall_status' => 'running',
            ];

            // Step 1: Create fresh backup if requested
            if ($createFreshBackup) {
                $this->logStep('create_backup', 'Creating fresh backup...');
                $this->createFreshBackup();
                $this->markStepComplete('create_backup', 'Fresh backup created');
            }

            // Step 2: Find and retrieve latest backup
            $this->logStep('find_backup', 'Locating latest backup...');
            $backup = $this->findLatestBackup($diskName);
            if (!$backup) {
                throw new \Exception("No backup found on disk: {$diskName}");
            }
            $this->markStepComplete('find_backup', "Found backup: {$backup->path()}", [
                'backup_path' => $backup->path(),
                'backup_size' => $backup->sizeInBytes(),
                'backup_date' => $backup->date()->toIso8601String(),
            ]);

            // Step 3: Extract backup
            $this->logStep('extract_backup', 'Extracting backup archive...');
            $this->extractedSqlPath = $this->extractBackup($backup);
            $this->markStepComplete('extract_backup', 'Backup extracted successfully', [
                'sql_file' => basename($this->extractedSqlPath),
            ]);

            // Step 4: Provision staging database
            $this->logStep('provision_staging', "Creating staging database: {$this->stagingDbName}");
            $this->provisionStagingDatabase();
            $this->markStepComplete('provision_staging', 'Staging database created');

            // Step 5: Restore to staging
            $this->logStep('restore_backup', 'Restoring backup to staging database...');
            $this->restoreToStaging();
            $this->markStepComplete('restore_backup', 'Backup restored to staging');

            // Step 6: Run integrity checks
            $this->logStep('integrity_checks', 'Running integrity verification...');
            $this->runAllIntegrityChecks();
            $this->markStepComplete('integrity_checks', 'Integrity checks completed');

            // Calculate overall status
            $failedChecks = collect($this->results['integrity_checks'])
                ->filter(fn($check) => $check['status'] === 'FAIL')
                ->count();

            $this->results['overall_status'] = $failedChecks === 0 ? 'PASS' : 'FAIL';
            $this->results['completed_at'] = now()->toIso8601String();
            $this->results['duration_seconds'] = round(microtime(true) - $startTime, 2);

            // Log results
            $this->logTestResults();

            return $this->results;

        } catch (\Exception $e) {
            $this->results['overall_status'] = 'ERROR';
            $this->results['error'] = $e->getMessage();
            $this->results['completed_at'] = now()->toIso8601String();
            $this->results['duration_seconds'] = round(microtime(true) - $startTime, 2);

            Log::channel('daily')->error('DR Test Failed', [
                'test_id' => $this->results['test_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->results;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Find the latest backup from specified disk
     */
    private function findLatestBackup(string $diskName): ?Backup
    {
        $backupName = config('backup.backup.name', env('APP_NAME', 'laravel-backup'));
        $destination = BackupDestination::create($diskName, $backupName);
        return $destination->newestBackup();
    }

    /**
     * Create a fresh backup before testing
     */
    private function createFreshBackup(): void
    {
        \Artisan::call('backup:run', [
            '--only-db' => true,
            '--disable-notifications' => true,
        ]);
    }

    /**
     * Extract backup archive and locate SQL file
     */
    private function extractBackup(Backup $backup): string
    {
        if (!File::isDirectory($this->tempPath)) {
            File::makeDirectory($this->tempPath, 0755, true);
        }

        // Download backup
        $zipPath = $this->tempPath . '/backup.zip';
        $stream = $backup->stream();
        File::put($zipPath, stream_get_contents($stream));
        fclose($stream);

        // Verify and extract
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \Exception("Invalid or corrupted backup archive");
        }
        $zip->extractTo($this->tempPath);
        $zip->close();

        // Find SQL file
        $searchPaths = [
            $this->tempPath . '/db-dumps',
            $this->tempPath,
        ];

        foreach ($searchPaths as $searchPath) {
            if (!File::isDirectory($searchPath)) {
                continue;
            }
            
            $files = File::allFiles($searchPath);
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.sql')) {
                    return $file->getPathname();
                }
            }
        }

        throw new \Exception("No SQL file found in backup archive");
    }

    /**
     * Create staging database for restore testing
     */
    private function provisionStagingDatabase(): void
    {
        $defaultConfig = config('database.connections.pgsql');

        // Connect to postgres maintenance database
        config(['database.connections.pgsql_admin' => array_merge($defaultConfig, [
            'database' => 'postgres',
        ])]);

        try {
            DB::connection('pgsql_admin')->statement(
                "CREATE DATABASE \"{$this->stagingDbName}\""
            );
        } catch (\Exception $e) {
            // Fallback: try from main connection
            DB::connection('pgsql')->statement(
                "CREATE DATABASE \"{$this->stagingDbName}\""
            );
        }
    }

    /**
     * Restore SQL dump to staging database
     */
    private function restoreToStaging(): void
    {
        $config = config('database.connections.pgsql');
        $dumpPath = $config['dump']['dump_binary_path'] ?? '';

        $env = [
            'PGPASSWORD' => $config['password'],
            'PGUSER' => $config['username'],
            'PGHOST' => $config['host'],
            'PGPORT' => $config['port'],
        ];

        // Determine psql executable path
        $psql = $this->findPsqlExecutable($dumpPath);

        $command = sprintf(
            '%s -d "%s" -f "%s" -q',
            $psql,
            $this->stagingDbName,
            $this->extractedSqlPath
        );

        $process = Process::env($env)->timeout(600)->run($command);

        if ($process->failed()) {
            throw new \Exception("Restore failed: " . $process->errorOutput());
        }
    }

    /**
     * Find psql executable
     */
    private function findPsqlExecutable(string $dumpPath): string
    {
        if (empty($dumpPath)) {
            return 'psql';
        }

        $candidates = [
            rtrim($dumpPath, '\\/') . DIRECTORY_SEPARATOR . 'psql.exe',
            rtrim($dumpPath, '\\/') . DIRECTORY_SEPARATOR . 'psql',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return '"' . $candidate . '"';
            }
        }

        return 'psql';
    }

    /**
     * Run all integrity checks
     */
    private function runAllIntegrityChecks(): void
    {
        // Configure staging connection
        $config = config('database.connections.pgsql');
        config(['database.connections.pgsql_staging' => array_merge($config, [
            'database' => $this->stagingDbName,
        ])]);
        DB::purge('pgsql_staging');

        // Run each integrity check
        $this->checkAttendanceCountMatches();
        $this->checkRandomStudentHistoryValid();
        $this->checkRandomTeacherDataValid();
        $this->checkSchoolDataIntegrity();
        $this->checkFileStorageAccessible();
        $this->checkForeignKeyIntegrity();
        $this->checkIndexesExist();
    }

    /**
     * Check 1: Attendance count matches between live and staging
     */
    private function checkAttendanceCountMatches(): void
    {
        $checkName = 'attendance_count_match';

        try {
            $liveCount = DB::connection('pgsql')->table('attendances')->count();
            $stagingCount = DB::connection('pgsql_staging')->table('attendances')->count();

            // Allow small tolerance for data created during backup
            $diff = abs($liveCount - $stagingCount);
            $tolerance = max(5, (int)($liveCount * 0.001)); // 0.1% or 5, whichever is higher
            
            $status = $diff <= $tolerance ? 'PASS' : 'FAIL';

            $this->addIntegrityCheck($checkName, $status, [
                'live_count' => $liveCount,
                'staging_count' => $stagingCount,
                'difference' => $diff,
                'tolerance' => $tolerance,
                'message' => "Live: {$liveCount}, Staging: {$stagingCount}, Diff: {$diff}",
            ]);

        } catch (\Exception $e) {
            $this->addIntegrityCheck($checkName, 'FAIL', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check 2: Random student attendance history is valid and consistent
     */
    private function checkRandomStudentHistoryValid(): void
    {
        $checkName = 'random_student_history';

        try {
            // Get random student from staging
            $randomStudent = DB::connection('pgsql_staging')
                ->table('users')
                ->where('role_type', 'student')
                ->inRandomOrder()
                ->first();

            if (!$randomStudent) {
                $this->addIntegrityCheck($checkName, 'SKIP', [
                    'message' => 'No students found in staging database',
                ]);
                return;
            }

            // Get attendance history from both databases
            $stagingHistory = DB::connection('pgsql_staging')
                ->table('attendances')
                ->where('student_id', $randomStudent->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $liveHistory = DB::connection('pgsql')
                ->table('attendances')
                ->where('student_id', $randomStudent->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Compare record counts (staging should have equal or slightly fewer)
            $stagingCount = $stagingHistory->count();
            $liveCount = $liveHistory->count();

            // Verify data consistency - check if oldest records match
            $dataMatches = true;
            if ($stagingCount > 0 && $liveCount > 0) {
                // Compare the oldest record in staging with live
                $stagingOldest = $stagingHistory->last();
                $liveMatch = $liveHistory->firstWhere('id', $stagingOldest->id);
                $dataMatches = $liveMatch !== null;
            }

            $status = $dataMatches ? 'PASS' : 'FAIL';

            $this->addIntegrityCheck($checkName, $status, [
                'student_id' => $randomStudent->id,
                'student_name' => $randomStudent->name ?? 'N/A',
                'staging_record_count' => $stagingCount,
                'live_record_count' => $liveCount,
                'data_consistent' => $dataMatches,
                'message' => "Student {$randomStudent->id}: Staging has {$stagingCount} records, Live has {$liveCount}",
            ]);

        } catch (\Exception $e) {
            $this->addIntegrityCheck($checkName, 'FAIL', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check 3: Random teacher data integrity
     */
    private function checkRandomTeacherDataValid(): void
    {
        $checkName = 'random_teacher_data';

        try {
            $teacher = DB::connection('pgsql_staging')
                ->table('users')
                ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
                ->inRandomOrder()
                ->first();

            if (!$teacher) {
                $this->addIntegrityCheck($checkName, 'SKIP', [
                    'message' => 'No teachers found',
                ]);
                return;
            }

            // Check teacher has associated schedules
            $hasSchedules = DB::connection('pgsql_staging')
                ->table('schedules')
                ->where('teacher_id', $teacher->id)
                ->exists();

            $status = $hasSchedules ? 'PASS' : 'WARN';

            $this->addIntegrityCheck($checkName, $status, [
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->name ?? 'N/A',
                'has_schedules' => $hasSchedules,
                'message' => "Teacher {$teacher->id}: " . ($hasSchedules ? 'Has schedules' : 'No schedules found'),
            ]);

        } catch (\Exception $e) {
            $this->addIntegrityCheck($checkName, 'FAIL', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check 4: School data integrity (multi-tenant validation)
     */
    private function checkSchoolDataIntegrity(): void
    {
        $checkName = 'school_data_integrity';

        try {
            $liveSchoolCount = DB::connection('pgsql')->table('schools')->count();
            $stagingSchoolCount = DB::connection('pgsql_staging')->table('schools')->count();

            // Get a random school and verify its data
            $randomSchool = DB::connection('pgsql_staging')
                ->table('schools')
                ->inRandomOrder()
                ->first();

            $schoolIntact = false;
            $schoolDetails = [];

            if ($randomSchool) {
                // Verify school has expected related data
                $userCount = DB::connection('pgsql_staging')
                    ->table('users')
                    ->where('school_id', $randomSchool->id)
                    ->count();

                $liveUserCount = DB::connection('pgsql')
                    ->table('users')
                    ->where('school_id', $randomSchool->id)
                    ->count();

                // Allow small tolerance
                $schoolIntact = abs($userCount - $liveUserCount) <= 5;

                $schoolDetails = [
                    'school_id' => $randomSchool->id,
                    'school_name' => $randomSchool->name ?? 'N/A',
                    'staging_users' => $userCount,
                    'live_users' => $liveUserCount,
                ];
            }

            $status = ($liveSchoolCount === $stagingSchoolCount && $schoolIntact) ? 'PASS' : 'FAIL';

            $this->addIntegrityCheck($checkName, $status, array_merge([
                'live_school_count' => $liveSchoolCount,
                'staging_school_count' => $stagingSchoolCount,
                'schools_match' => $liveSchoolCount === $stagingSchoolCount,
            ], $schoolDetails));

        } catch (\Exception $e) {
            $this->addIntegrityCheck($checkName, 'FAIL', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check 5: File storage is accessible
     */
    private function checkFileStorageAccessible(): void
    {
        $checkName = 'file_storage_accessible';

        try {
            // Check backup extracted structure
            $backupStructureValid = File::isDirectory($this->tempPath . '/db-dumps') ||
                                   File::exists($this->extractedSqlPath);

            // Check if main storage disk is accessible
            $mainStorageAccessible = Storage::disk('local')->exists('.') ||
                                    Storage::disk('local')->directories();

            // Check if we can list files in public storage
            $publicStorageAccessible = false;
            try {
                Storage::disk('public')->exists('.');
                $publicStorageAccessible = true;
            } catch (\Exception $e) {
                // Silent fail - public disk might not be configured
            }

            $status = ($backupStructureValid && $mainStorageAccessible) ? 'PASS' : 'FAIL';

            $this->addIntegrityCheck($checkName, $status, [
                'backup_structure_valid' => $backupStructureValid,
                'main_storage_accessible' => $mainStorageAccessible,
                'public_storage_accessible' => $publicStorageAccessible,
                'message' => $status === 'PASS' ? 'All storage accessible' : 'Storage access issues detected',
            ]);

        } catch (\Exception $e) {
            $this->addIntegrityCheck($checkName, 'FAIL', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check 6: Foreign key relationships are intact
     */
    private function checkForeignKeyIntegrity(): void
    {
        $checkName = 'foreign_key_integrity';

        try {
            // Check for orphaned attendance records (student_id not in users)
            $orphanedAttendances = DB::connection('pgsql_staging')
                ->table('attendances')
                ->leftJoin('users', 'attendances.student_id', '=', 'users.id')
                ->whereNull('users.id')
                ->count();

            // Check for orphaned schedules (teacher_id not in users)
            $orphanedSchedules = DB::connection('pgsql_staging')
                ->table('schedules')
                ->leftJoin('users', 'schedules.teacher_id', '=', 'users.id')
                ->whereNull('users.id')
                ->count();

            $status = ($orphanedAttendances === 0 && $orphanedSchedules === 0) ? 'PASS' : 'FAIL';

            $this->addIntegrityCheck($checkName, $status, [
                'orphaned_attendances' => $orphanedAttendances,
                'orphaned_schedules' => $orphanedSchedules,
                'message' => $status === 'PASS' 
                    ? 'No orphaned records found' 
                    : "Found {$orphanedAttendances} orphaned attendances, {$orphanedSchedules} orphaned schedules",
            ]);

        } catch (\Exception $e) {
            $this->addIntegrityCheck($checkName, 'FAIL', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check 7: Critical indexes exist
     */
    private function checkIndexesExist(): void
    {
        $checkName = 'indexes_exist';

        try {
            // Check for critical indexes on attendances table
            $indexes = DB::connection('pgsql_staging')
                ->select("
                    SELECT indexname 
                    FROM pg_indexes 
                    WHERE tablename = 'attendances'
                ");

            $indexNames = collect($indexes)->pluck('indexname')->toArray();

            // Expected critical indexes
            $criticalIndexes = [
                'attendances_pkey',
                'attendances_student_id_index',
            ];

            $missingIndexes = array_diff($criticalIndexes, $indexNames);
            $status = empty($missingIndexes) ? 'PASS' : 'WARN';

            $this->addIntegrityCheck($checkName, $status, [
                'found_indexes' => count($indexNames),
                'missing_critical' => $missingIndexes,
                'message' => $status === 'PASS' 
                    ? 'All critical indexes present' 
                    : 'Missing indexes: ' . implode(', ', $missingIndexes),
            ]);

        } catch (\Exception $e) {
            $this->addIntegrityCheck($checkName, 'FAIL', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add integrity check result
     */
    private function addIntegrityCheck(string $name, string $status, array $details = []): void
    {
        $this->results['integrity_checks'][] = [
            'name' => $name,
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'details' => $details,
        ];
    }

    /**
     * Log step start
     */
    private function logStep(string $step, string $message): void
    {
        $this->results['steps'][$step] = [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'message' => $message,
        ];
    }

    /**
     * Mark step as complete
     */
    private function markStepComplete(string $step, string $message, array $details = []): void
    {
        $this->results['steps'][$step] = array_merge(
            $this->results['steps'][$step] ?? [],
            [
                'status' => 'complete',
                'completed_at' => now()->toIso8601String(),
                'message' => $message,
                'details' => $details,
            ]
        );
    }

    /**
     * Log test results
     */
    private function logTestResults(): void
    {
        $channel = $this->results['overall_status'] === 'PASS' ? 'info' : 'error';
        
        Log::channel('daily')->{$channel}('DR Test Completed', [
            'test_id' => $this->results['test_id'],
            'status' => $this->results['overall_status'],
            'duration' => $this->results['duration_seconds'],
            'checks_passed' => collect($this->results['integrity_checks'])
                ->filter(fn($c) => $c['status'] === 'PASS')->count(),
            'checks_failed' => collect($this->results['integrity_checks'])
                ->filter(fn($c) => $c['status'] === 'FAIL')->count(),
        ]);
    }

    /**
     * Cleanup staging database and temp files
     */
    private function cleanup(): void
    {
        // Drop staging database
        try {
            DB::connection('pgsql_admin')->statement(
                "DROP DATABASE IF EXISTS \"{$this->stagingDbName}\" WITH (FORCE)"
            );
        } catch (\Exception $e) {
            Log::warning("Could not drop staging DB: " . $e->getMessage());
        }

        // Delete temp files
        if (File::isDirectory($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
    }

    /**
     * Get staging database name (for external use)
     */
    public function getStagingDbName(): string
    {
        return $this->stagingDbName;
    }

    /**
     * Generate JSON report
     */
    public function generateJsonReport(): string
    {
        return json_encode($this->results, JSON_PRETTY_PRINT);
    }

    /**
     * Generate summary for notifications
     */
    public function generateSummary(): string
    {
        $status = $this->results['overall_status'] ?? 'UNKNOWN';
        $duration = $this->results['duration_seconds'] ?? 0;
        
        $passed = collect($this->results['integrity_checks'] ?? [])
            ->filter(fn($c) => $c['status'] === 'PASS')->count();
        $failed = collect($this->results['integrity_checks'] ?? [])
            ->filter(fn($c) => $c['status'] === 'FAIL')->count();
        $warnings = collect($this->results['integrity_checks'] ?? [])
            ->filter(fn($c) => $c['status'] === 'WARN')->count();

        $icon = $status === 'PASS' ? '✅' : ($status === 'ERROR' ? '❌' : '⚠️');

        return "{$icon} DR Test {$status}\n" .
               "Duration: {$duration}s\n" .
               "Checks: {$passed} passed, {$failed} failed, {$warnings} warnings";
    }
}
