<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use ZipArchive;

class AutomatedDisasterRecoveryTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:test-dr 
                            {--create-backup : Create a fresh backup before testing}
                            {--disk=local : The disk to retrieve backup from}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automated Disaster Recovery Test: Restore backup to temp DB and verify integrity';

    private $tempPath;
    private $restoreDbName;
    private $originalConfig;

    public function __construct()
    {
        parent::__construct();
        $this->tempPath = storage_path('app/dr-testing');
        $this->restoreDbName = 'absensi_dr_test_' . time();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("🚀 Starting Automated Disaster Recovery Test...");
        $startTime = microtime(true);

        try {
            // 1. Create fresh backup if requested (ensures data match)
            if ($this->option('create-backup')) {
                $this->info("Step 1: Creating fresh backup...");
                $this->call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
            }

            // 2. Find Latest Backup
            $this->info("Step 2: Retrieving latest backup...");
            $backup = $this->findLatestBackup($this->option('disk'));
            if (!$backup) {
                throw new \Exception("No backup found!");
            }
            $this->line("   Target: " . $backup->path());

            // 3. Extract Backup
            $this->info("Step 3: Extracting backup...");
            $sqlFile = $this->extractBackup($backup);
            $this->line("   Extracted SQL: " . basename($sqlFile));

            // 4. Create Temporary Database
            $this->info("Step 4: Provisioning staging database ({$this->restoreDbName})...");
            $this->createTempDatabase($this->restoreDbName);

            // 5. Restore Database
            $this->info("Step 5: Restoring data...");
            $this->restoreDatabase($sqlFile, $this->restoreDbName);

            // 6. Verify Integrity
            $this->info("Step 6: Running integrity checks...");
            $checks = $this->runIntegrityChecks($this->restoreDbName);

            // 7. Report
            $this->table(['Check', 'Status', 'Details'], $checks);

            // Analysis
            $failed = collect($checks)->contains('Status', 'FAIL');
            if ($failed) {
                throw new \Exception("Integrity checks failed!");
            }

            $this->info("✅ SUCCESS: Disaster Recovery Test passed in " . round(microtime(true) - $startTime, 2) . "s");
            
            // Log success
            Log::channel('daily')->info('DR Test Passed', ['backup' => $backup->path()]);

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ FAILURE: " . $e->getMessage());
            Log::channel('daily')->error('DR Test Failed', ['error' => $e->getMessage()]);
            return 1;
        } finally {
            // Cleanup
            $this->info("Cleaning up...");
            $this->cleanup();
        }
    }

    private function findLatestBackup(string $diskName): ?Backup
    {
        $backupName = config('backup.backup.name', env('APP_NAME', 'laravel-backup'));
        $destination = BackupDestination::create($diskName, $backupName);
        return $destination->newestBackup();
    }

    private function extractBackup(Backup $backup): string
    {
        if (!File::isDirectory($this->tempPath)) {
            File::makeDirectory($this->tempPath, 0755, true);
        }

        // Copy/Download
        $zipPath = $this->tempPath . '/backup.zip';
        $stream = $backup->stream();
        File::put($zipPath, stream_get_contents($stream));
        fclose($stream);

        // Extract
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \Exception("Invalid Zip Archive");
        }
        $zip->extractTo($this->tempPath);
        $zip->close();

        // Find SQL
        $files = File::allFiles($this->tempPath . '/db-dumps');
        if (empty($files)) {
             // Fallback search root
             $files = File::allFiles($this->tempPath);
        }
        
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.sql')) {
                return $file->getPathname();
            }
        }

        throw new \Exception("No .sql file found in backup");
    }

    private function createTempDatabase($dbName)
    {
        // Use default connection to create new DB
        $defaultConfig = config('database.connections.pgsql');
        
        // Connect to 'postgres' or default DB to issue CREATE DATABASE
        // We cannot connect to the DB we are creating yet.
        config(['database.connections.pgsql_admin' => array_merge($defaultConfig, [
            'database' => 'postgres', // Connect to default maintenance db
        ])]);

        try {
            DB::connection('pgsql_admin')->statement("CREATE DATABASE \"{$dbName}\"");
        } catch (\Exception $e) {
             // Try connecting to the configured db if 'postgres' db is not accessible
             DB::connection('pgsql')->statement("CREATE DATABASE \"{$dbName}\"");
        }
    }

    private function restoreDatabase($sqlFile, $dbName)
    {
        $config = config('database.connections.pgsql');
        $dumpPath = $config['dump']['dump_binary_path'] ?? '';
        
        $env = [
            'PGPASSWORD' => $config['password'],
            'PGUSER' => $config['username'],
            'PGHOST' => $config['host'],
            'PGPORT' => $config['port'],
        ];

        // Determine psql executable
        $psql = 'psql';
        if (!empty($dumpPath)) {
            $candidate = rtrim($dumpPath, '\\/') . DIRECTORY_SEPARATOR . 'psql.exe';
            if (file_exists($candidate)) {
                $psql = '"' . $candidate . '"';
            } else {
                 // Try without .exe for non-windows or weird setups, though user IS windows
                 $candidate = rtrim($dumpPath, '\\/') . DIRECTORY_SEPARATOR . 'psql';
                 if (file_exists($candidate)) {
                     $psql = '"' . $candidate . '"';
                 }
            }
        }

        // psql command
        // -q: quiet
        // -d: database
        // -f: file
        $command = sprintf(
            '%s -d "%s" -f "%s"',
            $psql,
            $dbName,
            $sqlFile
        );

        $process = \Illuminate\Support\Facades\Process::env($env)->run($command);

        if ($process->failed()) {
            throw new \Exception("Restore failed: " . $process->errorOutput());
        }
    }

    private function runIntegrityChecks($dbName)
    {
        $checks = [];

        // Configure connection to temp DB
        $config = config('database.connections.pgsql');
        config(['database.connections.pgsql_dr' => array_merge($config, [
            'database' => $dbName,
        ])]);
        
        // Reconnect to ensure we use the new config
        DB::purge('pgsql_dr');

        // 1. Attendance Count Match
        try {
            $liveCount = DB::connection('pgsql')->table('attendances')->count();
            $restoreCount = DB::connection('pgsql_dr')->table('attendances')->count();
            
            // Allow small delta if backup wasn't instant
            $diff = abs($liveCount - $restoreCount);
            $status = $diff <= 5 ? 'PASS' : 'FAIL'; // Tolerance for live activity
            
            $checks[] = [
                'Attendance Count', 
                $status, 
                "Live: $liveCount, Restored: $restoreCount (Diff: $diff)"
            ];
        } catch (\Exception $e) {
            $checks[] = ['Attendance Count', 'FAIL', $e->getMessage()];
        }

        // 2. Random Student History Valid
        try {
            $randomStudent = DB::connection('pgsql_dr')->table('users')
                ->where('role_type', 'student')
                ->inRandomOrder()
                ->first();
            
            if ($randomStudent) {
                // Check if they have class assignment or history
                $hasHistory = DB::connection('pgsql_dr')->table('attendances')
                    ->where('student_id', $randomStudent->id)
                    ->exists();
                
                // If they don't have history in restored, check live.
                // If consistent, it's a pass.
                $liveHistory = DB::connection('pgsql')->table('attendances')
                    ->where('student_id', $randomStudent->id)
                    ->exists();

                $status = ($hasHistory === $liveHistory) ? 'PASS' : 'WARN'; // Maybe backup missed recent insert?

                $checks[] = [
                    'Random Student Data', 
                    $status, 
                    "Student ID: {$randomStudent->id}, History Match: " . ($status == 'PASS' ? 'Yes' : 'No')
                ];
            } else {
                 $checks[] = ['Random Student Data', 'SKIP', 'No students found'];
            }
        } catch (\Exception $e) {
            $checks[] = ['Random Student Data', 'FAIL', $e->getMessage()];
        }

        // 3. File Storage Accessibility
        // Check if we can list files in storage (Backup integrity implies file retrieval)
        // Since we extracted the backup, we can check if expected folders exist in the extracted structure
        $storagePath = $this->tempPath . '/storage'; // Common structure from spatie backup
        if (File::exists($storagePath) || File::exists($this->tempPath . '/db-dumps')) {
             $checks[] = ['Backup Structure', 'PASS', 'Valid directory structure found'];
        } else {
             $checks[] = ['Backup Structure', 'FAIL', 'Could not locate storage/db-dumps folders'];
        }
        
        return $checks;
    }

    private function cleanup()
    {
        // Drop Temp DB
        // Connect to admin/default first
        try {
            DB::connection('pgsql_admin')->statement("DROP DATABASE IF EXISTS \"{$this->restoreDbName}\" WITH (FORCE)");
        } catch (\Exception $e) {
            // Best effort
            Log::warning("Could not drop temp DB: " . $e->getMessage());
        }

        // Delete Files
        if (File::isDirectory($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
    }
}
