<?php

namespace App\Console\Commands;

use App\Services\ImmutableSecurityLogService;
use Illuminate\Console\Command;

class VerifySecurityLogIntegrity extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'security:verify-log-integrity 
                            {--alert : Send alerts if tampering detected}
                            {--silent : Suppress output except errors}';

    /**
     * The console command description.
     */
    protected $description = 'Verify the integrity of the immutable security log hash chain';

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
        $silent = $this->option('silent');
        $sendAlerts = $this->option('alert');

        if (! $silent) {
            $this->info('🔐 Starting security log integrity verification...');
            $this->newLine();
        }

        // Progress callback for verbose output
        $progressCallback = $silent ? null : function ($verified, $total) {
            $percentage = round(($verified / $total) * 100, 1);
            $this->output->write("\r  Verifying: {$verified}/{$total} ({$percentage}%)");
        };

        // Run verification
        $startTime = microtime(true);
        $result = $this->logService->verifyChainIntegrity($progressCallback);
        $duration = round(microtime(true) - $startTime, 2);

        if (! $silent) {
            $this->newLine(2);
        }

        // Display results
        if ($result['is_valid']) {
            if (! $silent) {
                $this->info('✅ VERIFICATION PASSED');
                $this->newLine();
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total Records', $result['total_records']],
                        ['Verified Records', $result['verified_records']],
                        ['Duration', "{$duration}s"],
                        ['Status', 'CHAIN INTACT'],
                    ]
                );
            }

            return Command::SUCCESS;
        }

        // Tampering detected!
        $this->error('⚠️  VERIFICATION FAILED - TAMPERING DETECTED!');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Records', $result['total_records']],
                ['Verified Records', $result['verified_records']],
                ['Errors Found', count($result['errors'])],
                ['Duration', "{$duration}s"],
                ['Status', 'CHAIN COMPROMISED'],
            ]
        );

        $this->newLine();
        $this->error('Error Details:');

        foreach (array_slice($result['errors'], 0, 10) as $error) {
            $this->line("  [{$error['type']}] Sequence #{$error['sequence_number']}: {$error['message']}");
        }

        if (count($result['errors']) > 10) {
            $this->line('  ... and '.(count($result['errors']) - 10).' more errors');
        }

        // Handle tampering detection
        if ($sendAlerts) {
            $this->newLine();
            $this->warn('Sending security alerts...');
            $this->logService->handleTamperingDetected($result);
            $this->info('Alerts dispatched to administrators.');
        }

        return Command::FAILURE;
    }
}
