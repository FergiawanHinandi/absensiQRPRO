<?php

namespace App\Console\Commands;

use App\Services\ImmutableSecurityLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupSecurityLogHashes extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'security:backup-hashes 
                            {--path= : Custom output path (default: storage/app/security-backups)}
                            {--s3 : Upload to S3 bucket after local backup}
                            {--verify : Verify chain integrity before backup}';

    /**
     * The console command description.
     */
    protected $description = 'Export security log hashes for external backup and verification';

    protected ImmutableSecurityLogService $logService;

    public function __construct(ImmutableSecurityLogService $logService)
    {
        parent::__construct();
        $this->logService = $logService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔐 Security Log Hash Backup');
        $this->newLine();

        // Optional: Verify before backup
        if ($this->option('verify')) {
            $this->info('Step 1: Verifying chain integrity...');

            $result = $this->logService->verifyChainIntegrity();

            if (! $result['is_valid']) {
                $this->error('❌ Chain integrity verification failed! Cannot backup compromised data.');
                $this->error('Run `php artisan security:verify-log-integrity --alert` for details.');

                return Command::FAILURE;
            }

            $this->info('✅ Chain integrity verified.');
            $this->newLine();
        }

        // Determine output path
        $basePath = $this->option('path') ?: storage_path('app/security-backups');

        // Ensure directory exists
        if (! is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        // Generate filename with timestamp
        $timestamp = now()->format('Y-m-d_His');
        $filename = "security_hashes_{$timestamp}.json";
        $outputPath = "{$basePath}/{$filename}";

        $this->info('Step 2: Exporting hashes...');

        try {
            $result = $this->logService->exportHashes($outputPath);
        } catch (\Exception $e) {
            $this->error("❌ Export failed: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->info("✅ Exported {$result['total_records']} records to:");
        $this->line("   {$outputPath}");
        $this->line("   Master checksum: {$result['export_checksum']}");
        $this->newLine();

        // Optional: Upload to S3
        if ($this->option('s3')) {
            $this->info('Step 3: Uploading to S3...');

            try {
                $s3Path = "security-backups/{$filename}";
                Storage::disk('s3')->put($s3Path, file_get_contents($outputPath));

                // Also upload the checksum file
                $checksumFile = "{$outputPath}.sha256";
                if (file_exists($checksumFile)) {
                    Storage::disk('s3')->put("{$s3Path}.sha256", file_get_contents($checksumFile));
                }

                $this->info("✅ Uploaded to S3: {$s3Path}");
            } catch (\Exception $e) {
                $this->warn("⚠️  S3 upload failed: {$e->getMessage()}");
                $this->warn('   Local backup was successful.');
            }
        }

        // Display summary
        $this->newLine();

        $firstSeq = $result['chain'][0]['seq'] ?? 0;
        $lastSeq = end($result['chain'])['seq'] ?? 0;

        $this->table(
            ['Metric', 'Value'],
            [
                ['Records Exported', $result['total_records']],
                ['First Sequence', $firstSeq],
                ['Last Sequence', $lastSeq],
                ['Local Path', $outputPath],
                ['Checksum', substr($result['export_checksum'], 0, 16).'...'],
            ]
        );

        // Cleanup old backups (keep last 30 days)
        $this->cleanupOldBackups($basePath);

        return Command::SUCCESS;
    }

    /**
     * Remove backups older than 30 days.
     */
    protected function cleanupOldBackups(string $basePath): void
    {
        $files = glob("{$basePath}/security_hashes_*.json");
        $thirtyDaysAgo = now()->subDays(30)->timestamp;
        $cleaned = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $thirtyDaysAgo) {
                unlink($file);

                // Also remove checksum file if exists
                $checksumFile = "{$file}.sha256";
                if (file_exists($checksumFile)) {
                    unlink($checksumFile);
                }

                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->line("🗑️  Cleaned up {$cleaned} backup(s) older than 30 days.");
        }
    }
}
