<?php

namespace App\Console\Commands;

use App\Services\BackupRestoreMonitoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use ZipArchive;

class ValidateBackupRestore extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:validate-restore';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download the latest backup, restore it to a temporary database, and validate integrity';

    /**
     * Execute the console command.
     */
    public function handle(BackupRestoreMonitoringService $monitoring)
    {
        $monitoring->startJob('restore_validation', [
            'triggered_by' => 'scheduler',
        ]);

        $this->info('Starting backup restore validation...');

        $tempDbName = 'absensi_restore_validation_' . time();
        $tempDir = storage_path('app/backup-temp/validation-' . time());
        
        try {
            // 1. Find Latest Backup
            $monitoring->updateProgress(10, 'Locating latest backup...');
            $diskName = config('backup.backup.destination.disks')[0] ?? 'local';
            $backupDestinations = BackupDestination::create($diskName, config('backup.backup.name'));
            $newestBackup = $backupDestinations->newestBackup();

            if (!$newestBackup) {
                throw new \Exception('No backups found to validate.');
            }

            $this->info("Found backup: " . $newestBackup->path());
            $monitoring->updateProgress(20, 'Downloading backup...');

            // 2. Extract Backup
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
            
            // Stream read to file
            $zipPath = $tempDir . '/backup.zip';
            $stream = $newestBackup->stream();
            file_put_contents($zipPath, stream_get_contents($stream));
            if (is_resource($stream)) fclose($stream);

            $monitoring->updateProgress(30, 'Extracting backup...');
            
            $zip = new ZipArchive;
            if ($zip->open($zipPath) === TRUE) {
                $zip->extractTo($tempDir);
                $zip->close();
            } else {
                throw new \Exception('Failed to open backup zip file.');
            }

            // Find SQL dump
            $sqlFile = glob($tempDir . '/db-dumps/*.sql')[0] ?? null;
            if (!$sqlFile) {
                throw new \Exception('No SQL dump found in backup.');
            }

            // 3. Create Temporary Database
            $monitoring->updateProgress(50, 'Creating temporary database...');
            
            // We assume the user executing this has permission to create databases
            // Or we use a separate connection. Use the default connection to create the new DB.
            $defaultConn = config('database.default');
            
            // Usually we can't switch DB on the fly easily without raw PDO or Config mutation
            // We'll try to create it using the current connection
            DB::statement("CREATE DATABASE {$tempDbName}");
            
            // 4. Restore
            $monitoring->updateProgress(60, 'Restoring database...');
            
            // Use mysql/pg command line for speed
            $dbConfig = config("database.connections.{$defaultConn}");
            $username = $dbConfig['username'];
            $password = $dbConfig['password'];
            $host = $dbConfig['host'];
            $port = $dbConfig['port'];
            
            // Support PostgreSQL and MySQL
            if ($defaultConn === 'pgsql') {
                $envVars = "PGPASSWORD='{$password}'";
                $command = "{$envVars} psql -h {$host} -p {$port} -U {$username} -d {$tempDbName} -f \"{$sqlFile}\"";
            } else {
                $command = "mysql -h {$host} -P {$port} -u {$username} -p'{$password}' {$tempDbName} < \"{$sqlFile}\"";
            }

            // Execute restore
            exec($command . ' 2>&1', $output, $returnVar);
            
            if ($returnVar !== 0) {
                throw new \Exception("Restore command failed: " . implode("\n", $output));
            }

            // 5. Validate Data
            $monitoring->updateProgress(80, 'Validating data integrity...');
            
            // Configure a dynamic connection to the new DB
            config(['database.connections.temp_restore' => array_merge($dbConfig, ['database' => $tempDbName])]);
            
            $userCount = DB::connection('temp_restore')->table('users')->count();
            // Add more specific checks here based on critical business logic
            // e.g., check recent attendance records
            
            if ($userCount === 0) {
                throw new \Exception("Restored database is empty (0 users found).");
            }

            $this->info("Validation successful: Found {$userCount} users.");

            // 6. Cleanup
            $monitoring->updateProgress(90, 'Cleaning up...');
            DB::statement("DROP DATABASE {$tempDbName}");
            $this->rrmdir($tempDir);

            $monitoring->completeJob([
                'users_count' => $userCount,
                'verified_at' => now()
            ]);

            return 0;

        } catch (\Exception $e) {
            // Attempt cleanup even on failure
            try {
                // DB::statement("DROP DATABASE IF EXISTS {$tempDbName}");
                // Not running this blindly to avoid accidents, but in a perfect script we would.
            } catch (\Exception $ex) {}
            
            $this->rrmdir($tempDir);
            
            $monitoring->failJob($e->getMessage());
            $this->error($e->getMessage());
            
            return 1;
        }
    }

    private function rrmdir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object) && !is_link($dir . "/" . $object))
                        $this->rrmdir($dir . "/" . $object);
                    else
                        unlink($dir . "/" . $object);
                }
            }
            rmdir($dir);
        }
    }
}
