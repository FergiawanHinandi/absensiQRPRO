<?php

namespace App\Console\Commands;

use App\Services\AtomicRollbackService;
use Illuminate\Console\Command;

class AtomicRollbackCommand extends Command
{
    protected $signature = 'rollback:atomic 
                            {type : Type of rollback (database|storage|full)}
                            {backup : Backup identifier}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Execute atomic rollback with fail-safe checkpoints';

    private $rollbackService;

    public function __construct(AtomicRollbackService $rollbackService)
    {
        parent::__construct();
        $this->rollbackService = $rollbackService;
    }

    public function handle()
    {
        $type = $this->argument('type');
        $backup = $this->argument('backup');

        if (!in_array($type, ['database', 'storage', 'full'])) {
            $this->error('Invalid rollback type. Use: database, storage, or full');
            return 1;
        }

        if (!$this->option('force')) {
            $this->warn("You are about to perform a {$type} rollback using backup: {$backup}");
            $this->warn('This action cannot be undone and may cause data loss!');
            
            if (!$this->confirm('Do you wish to continue?')) {
                $this->info('Rollback cancelled.');
                return 0;
            }
        }

        $this->info("Starting {$type} rollback...");
        
        try {
            $result = $this->rollbackService->executeRollback($type, $backup);
            
            $this->info('✅ Rollback completed successfully!');
            $this->info('Rollback ID: ' . $result['rollback_id']);
            $this->info('Type: ' . $result['type']);
            $this->info('Backup used: ' . $result['backup_used']);
            
            if (isset($result['pre_rollback_backup'])) {
                $this->info('Pre-rollback backup: ' . $result['pre_rollback_backup']);
            }
            
            $this->info('Steps executed: ' . count($result['steps']));
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('❌ Rollback failed: ' . $e->getMessage());
            return 1;
        }
    }
}
