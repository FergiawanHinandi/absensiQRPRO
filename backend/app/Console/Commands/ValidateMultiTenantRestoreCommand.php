<?php

namespace App\Console\Commands;

use App\Services\MultiTenantRestoreValidationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ValidateMultiTenantRestoreCommand extends Command
{
    protected $signature = 'restore:validate-multi-tenant 
                            {backup-path : Path to backup file}
                            {school-id : Target school ID}
                            {--report : Generate detailed validation report}
                            {--report-path : Path to save report (default: storage/app/validation_reports)}';

    protected $description = 'Validate multi-tenant backup file for safe restore';

    private $validationService;

    public function __construct(MultiTenantRestoreValidationService $validationService)
    {
        parent::__construct();
        $this->validationService = $validationService;
    }

    public function handle()
    {
        $backupPath = $this->argument('backup-path');
        $schoolId = $this->argument('school-id');
        $generateReport = $this->option('report');
        $reportPath = $this->option('report-path') ?? storage_path('app/validation_reports');

        $this->info("🔍 Starting multi-tenant restore validation...");
        $this->info("📁 Backup: {$backupPath}");
        $this->info("🏫 Target School ID: {$schoolId}");

        // Validate school ID format
        if (!is_numeric($schoolId) || $schoolId <= 0) {
            $this->error("❌ Invalid school ID: {$schoolId}");
            return 1;
        }

        // Check if backup file exists
        if (!file_exists($backupPath)) {
            $this->error("❌ Backup file not found: {$backupPath}");
            return 1;
        }

        // Perform validation
        $this->line("\n⏳ Validating backup file...");
        $validationResult = $this->validationService->validateBackupForRestore($backupPath, (int)$schoolId);

        // Display results
        $this->displayValidationResults($validationResult);

        // Generate report if requested
        if ($generateReport) {
            $this->generateReport($validationResult, $reportPath, $schoolId);
        }

        // Return appropriate exit code
        return $validationResult['valid'] ? 0 : 1;
    }

    private function displayValidationResults(array $result): void
    {
        $this->newLine();

        // Overall status
        if ($result['valid']) {
            $this->info("✅ Validation PASSED - Backup is safe for restore");
        } else {
            $this->error("❌ Validation FAILED - Backup is NOT safe for restore");
        }

        $this->line("📊 Summary: {$result['error_count']} errors, {$result['warning_count']} warnings");
        $this->line("🆔 Restore ID: {$result['restore_id']}");
        $this->line("⏰ Timestamp: {$result['timestamp']}");

        // Display errors
        if (!empty($result['errors'])) {
            $this->newLine();
            $this->error("🚨 ERRORS ({$result['error_count']}):");
            
            foreach ($result['errors'] as $error) {
                $this->line("  • {$error['code']}: {$error['message']}");
                
                if ($error['details']) {
                    foreach ($error['details'] as $key => $value) {
                        if (is_array($value)) {
                            $this->line("    - {$key}: " . json_encode($value));
                        } else {
                            $this->line("    - {$key}: {$value}");
                        }
                    }
                }
            }
        }

        // Display warnings
        if (!empty($result['warnings'])) {
            $this->newLine();
            $this->warn("⚠️  WARNINGS ({$result['warning_count']}):");
            
            foreach ($result['warnings'] as $warning) {
                $this->line("  • {$warning['code']}: {$warning['message']}");
                
                if ($warning['details']) {
                    foreach ($warning['details'] as $key => $value) {
                        if (is_array($value)) {
                            $this->line("    - {$key}: " . json_encode($value));
                        } else {
                            $this->line("    - {$key}: {$value}");
                        }
                    }
                }
            }
        }

        // Recommendations
        $this->newLine();
        $this->displayRecommendations($result);
    }

    private function displayRecommendations(array $result): void
    {
        $this->info("💡 RECOMMENDATIONS:");

        if (!$result['valid']) {
            $this->line("  ❌ DO NOT PROCEED with restore until all errors are resolved");
            
            // Specific recommendations based on error types
            $errorCodes = array_column($result['errors'], 'code');
            
            if (in_array('SCHOOL_ID_MISMATCH', $errorCodes)) {
                $this->line("  🔧 School ID mismatch detected - verify backup source and target school");
            }
            
            if (in_array('MISSING_TABLES', $errorCodes)) {
                $this->line("  🔧 Missing tables - ensure backup contains complete schema");
            }
            
            if (in_array('SCHOOL_NOT_EXISTS', $errorCodes)) {
                $this->line("  🔧 Target school doesn't exist - create school first or verify school ID");
            }
        } else {
            $this->line("  ✅ Backup is safe to restore");
            
            if ($result['warning_count'] > 0) {
                $this->line("  ⚠️  Review warnings before proceeding - they may cause issues");
            }
        }

        $this->line("  📋 Always test restore in staging environment first");
        $this->line("  💾 Create current state backup before restore");
        $this->line("  👥 Notify affected users about planned restore");
    }

    private function generateReport(array $result, string $reportPath, int $schoolId): void
    {
        try {
            // Create reports directory if it doesn't exist
            if (!is_dir($reportPath)) {
                mkdir($reportPath, 0755, true);
            }

            // Generate report content
            $reportContent = $this->validationService->generateValidationReport($result);

            // Create report filename
            $filename = "validation_report_school_{$schoolId}_{$result['restore_id']}.md";
            $fullPath = $reportPath . '/' . $filename;

            // Save report
            file_put_contents($fullPath, $reportContent);

            $this->newLine();
            $this->info("📄 Detailed report saved to: {$fullPath}");

        } catch (\Exception $e) {
            $this->error("❌ Failed to generate report: " . $e->getMessage());
        }
    }
}
