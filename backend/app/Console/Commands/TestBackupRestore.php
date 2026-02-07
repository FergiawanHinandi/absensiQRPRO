<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use ZipArchive;

class TestBackupRestore extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'backup:test-restore 
                            {--disk=local : The disk to test restore from}
                            {--keep : Keep the extracted files after verification}';

    /**
     * The console command description.
     */
    protected $description = 'Test backup integrity by downloading and verifying latest backup (does NOT overwrite production)';

    protected string $tempPath;

    public function __construct()
    {
        parent::__construct();
        $this->tempPath = storage_path('app/backup-restore-test');
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $diskName = $this->option('disk');
        $keepFiles = $this->option('keep');

        $this->warn('⚠️  BACKUP RESTORE TEST - This will NOT overwrite production data');
        $this->newLine();

        try {
            // Step 1: Find latest backup
            $this->info('Step 1: Finding latest backup...');
            $backup = $this->findLatestBackup($diskName);

            if (! $backup) {
                $this->error('No backup found on disk: '.$diskName);

                return Command::FAILURE;
            }

            $this->line("   Found: {$backup->path()}");
            $this->line("   Size: {$this->formatBytes($backup->sizeInBytes())}");
            $this->line("   Date: {$backup->date()->toIso8601String()}");
            $this->newLine();

            // Step 2: Download/copy to temp
            $this->info('Step 2: Downloading backup to temp directory...');
            $localPath = $this->downloadBackup($backup, $diskName);
            $this->line("   Downloaded to: {$localPath}");
            $this->newLine();

            // Step 3: Verify archive integrity
            $this->info('Step 3: Verifying archive integrity...');
            if (! $this->verifyArchiveIntegrity($localPath)) {
                throw new \Exception('Archive integrity check failed');
            }
            $this->line('   ✅ Archive is valid ZIP');
            $this->newLine();

            // Step 4: Extract and verify contents
            $this->info('Step 4: Extracting and verifying contents...');
            $extractPath = $this->tempPath.'/extracted_'.time();
            $this->extractBackup($localPath, $extractPath);

            $verification = $this->verifyBackupContents($extractPath);
            $this->displayVerificationResults($verification);

            // Step 5: Cleanup
            if (! $keepFiles) {
                $this->info('Step 5: Cleaning up temp files...');
                $this->cleanup();
                $this->line('   ✅ Temp files removed');
            } else {
                $this->warn("Temp files kept at: {$this->tempPath}");
            }

            $this->newLine();

            if ($verification['overall_status']) {
                $this->info('✅ BACKUP RESTORE TEST PASSED');
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Backup Date', $backup->date()->toIso8601String()],
                        ['Backup Size', $this->formatBytes($backup->sizeInBytes())],
                        ['Database Dump', $verification['has_database'] ? 'Found' : 'Missing'],
                        ['Files Archive', $verification['has_files'] ? 'Found' : 'Missing'],
                        ['Status', 'VERIFIED'],
                    ]
                );

                Log::channel('security')->info('Backup restore test passed', [
                    'backup_path' => $backup->path(),
                    'verification' => $verification,
                ]);

                return Command::SUCCESS;
            }

            $this->error('❌ BACKUP RESTORE TEST FAILED');

            return Command::FAILURE;

        } catch (\Exception $e) {
            $this->error("❌ Test failed: {$e->getMessage()}");

            Log::channel('security')->error('Backup restore test failed', [
                'error' => $e->getMessage(),
                'disk' => $diskName,
            ]);

            // Cleanup on failure
            $this->cleanup();

            return Command::FAILURE;
        }
    }

    /**
     * Find the latest backup on the specified disk.
     */
    protected function findLatestBackup(string $diskName): ?Backup
    {
        $backupName = config('backup.backup.name', 'AbsensiQRPro');

        try {
            $destination = BackupDestination::create($diskName, $backupName);

            return $destination->newestBackup();
        } catch (\Exception $e) {
            $this->warn("Could not access backup destination: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Download backup to temp directory.
     */
    protected function downloadBackup(Backup $backup, string $diskName): string
    {
        // Ensure temp directory exists
        if (! File::isDirectory($this->tempPath)) {
            File::makeDirectory($this->tempPath, 0755, true);
        }

        $localPath = $this->tempPath.'/'.basename($backup->path());

        // If disk is local, just copy
        if ($diskName === 'local') {
            $sourcePath = config('filesystems.disks.local.root').'/'.$backup->path();
            File::copy($sourcePath, $localPath);
        } else {
            // Download from remote disk
            $disk = Storage::disk($diskName);
            $contents = $disk->get($backup->path());
            File::put($localPath, $contents);
        }

        return $localPath;
    }

    /**
     * Verify the archive is a valid ZIP file.
     */
    protected function verifyArchiveIntegrity(string $path): bool
    {
        $zip = new ZipArchive;
        $result = $zip->open($path, ZipArchive::CHECKCONS);

        if ($result !== true) {
            $this->error("   ZIP error code: {$result}");

            return false;
        }

        $zip->close();

        return true;
    }

    /**
     * Extract the backup to a directory.
     */
    protected function extractBackup(string $archivePath, string $extractPath): void
    {
        if (! File::isDirectory($extractPath)) {
            File::makeDirectory($extractPath, 0755, true);
        }

        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new \Exception('Could not open ZIP archive');
        }

        // Check if encrypted
        $password = config('backup.encryption.key');
        if ($password) {
            $zip->setPassword($password);
        }

        $extracted = $zip->extractTo($extractPath);
        $zip->close();

        if (! $extracted) {
            throw new \Exception('Failed to extract backup archive. Check encryption password.');
        }
    }

    /**
     * Verify the backup contains required files.
     */
    protected function verifyBackupContents(string $extractPath): array
    {
        $result = [
            'has_database' => false,
            'has_files' => false,
            'database_files' => [],
            'file_count' => 0,
            'overall_status' => false,
        ];

        // Recursively scan extracted directory
        $files = $this->scanDirectory($extractPath);

        foreach ($files as $file) {
            // Check for database dump
            if (preg_match('/\.sql(\.gz)?$/', $file)) {
                $result['has_database'] = true;
                $result['database_files'][] = $file;
            }

            $result['file_count']++;
        }

        // If there are files, we have a files backup
        $result['has_files'] = $result['file_count'] > 1;

        // Overall status - at minimum we need database
        $result['overall_status'] = $result['has_database'];

        return $result;
    }

    /**
     * Recursively scan directory for files.
     */
    protected function scanDirectory(string $path): array
    {
        $files = [];

        if (! File::isDirectory($path)) {
            return $files;
        }

        $items = File::allFiles($path);

        foreach ($items as $item) {
            $files[] = $item->getRelativePathname();
        }

        return $files;
    }

    /**
     * Display verification results.
     */
    protected function displayVerificationResults(array $verification): void
    {
        $dbStatus = $verification['has_database'] ? '✅' : '❌';
        $filesStatus = $verification['has_files'] ? '✅' : '⚠️';

        $this->line("   {$dbStatus} Database dump: ".($verification['has_database'] ? 'Found' : 'Missing'));

        if (! empty($verification['database_files'])) {
            foreach ($verification['database_files'] as $dbFile) {
                $this->line("      - {$dbFile}");
            }
        }

        $this->line("   {$filesStatus} Files: {$verification['file_count']} files found");
        $this->newLine();
    }

    /**
     * Cleanup temp files.
     */
    protected function cleanup(): void
    {
        if (File::isDirectory($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
    }

    /**
     * Format bytes to human readable.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }
}
