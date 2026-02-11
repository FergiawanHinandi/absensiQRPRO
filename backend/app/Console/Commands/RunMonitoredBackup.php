<?php

namespace App\Console\Commands;

use App\Services\BackupRestoreMonitoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;
use Spatie\Backup\BackupDestination\BackupDestination;

class RunMonitoredBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:monitored {--only-db : Backup only the database} {--only-files : Backup only the files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run spatie backup with integrated monitoring and logging';

    /**
     * Execute the console command.
     */
    public function handle(BackupRestoreMonitoringService $monitoring)
    {
        $options = [];
        if ($this->option('only-db')) {
            $options[] = '--only-db';
        }
        if ($this->option('only-files')) {
            $options[] = '--only-files';
        }

        $jobType = 'backup';
        if ($this->option('only-db')) $jobType .= '_db';
        elseif ($this->option('only-files')) $jobType .= '_files';
        else $jobType .= '_full';

        // Start monitoring
        $monitoring->startJob($jobType, [
            'options' => $options,
            'triggered_by' => 'scheduler',
        ]);

        $this->info("Starting monitored backup: {$jobType}");

        try {
            $monitoring->updateProgress(10, 'Initializing backup process...');
            
            // Execute the actual backup command
            // We pass --disable-notifications because we handle notifications via the monitoring service
            $exitCode = Artisan::call('backup:run', array_merge($options, ['--disable-notifications' => true]), $this->output);

            if ($exitCode !== 0) {
                throw new \Exception("Backup command failed with exit code {$exitCode}");
            }

            $monitoring->updateProgress(90, 'Backup command finished, verifying output...');

            // Retrieve the latest backup size for metrics
            $backupSize = 0;
            // We need to check all configured disks
            $disks = config('backup.backup.destination.disks');
            
            // Just checking the first configured disk (usually 'local') for the latest file size
            if (!empty($disks)) {
                 $diskName = $disks[0];
                 // This is a simplified check. For a robust solution we'd query Spatie's BackupDestination
                 // However, since we just ran it, we can assume it's the newest file in the backup directory.
                 // Using Spatie's own tools is better:
                 
                 $backupDestinations = BackupDestination::create($diskName, config('backup.backup.name'));
                 $newestBackup = $backupDestinations->newestBackup();
                 
                 if ($newestBackup) {
                     $backupSize = $newestBackup->sizeInBytes();
                 }
            }

            $monitoring->completeJob([
                'backup_size_bytes' => $backupSize,
                'exit_code' => $exitCode
            ]);

            $this->info('Backup completed successfully.');
            return 0;

        } catch (\Exception $e) {
            $this->error("Backup failed: " . $e->getMessage());
            
            $monitoring->failJob($e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1;
        }
    }
}
