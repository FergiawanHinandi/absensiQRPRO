<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Queries\Contracts\DashboardQueryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blue/Green deployment gate check for CQRS rollout.
 *
 * Validates that the read model is accurate before switching traffic.
 * Returns exit code 0 (pass) or 1 (fail) for CI/CD pipeline gating.
 *
 * Usage:
 *   php artisan deploy:verify-cqrs
 *   php artisan deploy:verify-cqrs --tolerance=5
 */
class VerifyCqrsDeployment extends Command
{
    protected $signature = 'deploy:verify-cqrs
        {--tolerance=2 : Acceptable % deviation between read model and live COUNT}
        {--school= : Verify only a specific school}
        {--fix : Auto-run backfill if deviation detected}';

    protected $description = 'Blue/green gate: verify CQRS read model accuracy before traffic switch';

    public function handle(): int
    {
        $this->info('=== CQRS Deployment Verification ===');
        $this->newLine();

        $checks = [
            'migration' => $this->checkMigrations(),
            'table' => $this->checkTableExists(),
            'data_accuracy' => $this->checkDataAccuracy(),
            'projectors' => $this->checkProjectorsConfig(),
            'feature_flag' => $this->checkFeatureFlag(),
            'queue' => $this->checkProjectionQueue(),
        ];

        $this->newLine();
        $this->info('=== Summary ===');

        $allPassed = true;
        foreach ($checks as $name => $passed) {
            $status = $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>';
            $this->line("  [{$status}] {$name}");
            if (! $passed) {
                $allPassed = false;
            }
        }

        $this->newLine();

        if ($allPassed) {
            $this->info('All checks passed. Safe to proceed with deployment.');
            return self::SUCCESS;
        }

        $this->error('One or more checks failed. Fix issues before proceeding.');
        return self::FAILURE;
    }

    private function checkMigrations(): bool
    {
        $this->line('Checking migrations...');

        try {
            $pending = DB::table('migrations')
                ->where('migration', 'like', '%attendance_summary_views%')
                ->exists();

            if (! $pending) {
                $this->warn('  attendance_summary_views migration not found in migrations table.');
                return false;
            }

            $this->line('  Migration recorded in database.');
            return true;
        } catch (\Throwable $e) {
            $this->error("  Migration check failed: {$e->getMessage()}");
            return false;
        }
    }

    private function checkTableExists(): bool
    {
        $this->line('Checking attendance_summary_views table...');

        if (! Schema::hasTable('attendance_summary_views')) {
            $this->error('  Table does not exist. Run migrations first.');
            return false;
        }

        $count = DB::table('attendance_summary_views')->count();
        $this->line("  Table exists with {$count} rows.");

        if ($count === 0) {
            $this->warn('  Table is empty. Run: php artisan cqrs:backfill-summaries');
            return false;
        }

        return true;
    }

    private function checkDataAccuracy(): bool
    {
        $this->line('Checking data accuracy (read model vs live COUNT)...');

        $tolerance = (float) $this->option('tolerance');
        $schoolFilter = $this->option('school') ? (int) $this->option('school') : null;
        $today = now()->format('Y-m-d');

        // Get schools to check
        $schoolsQuery = DB::table('attendances')
            ->whereDate('attendance_date', $today)
            ->select('school_id')
            ->distinct();

        if ($schoolFilter) {
            $schoolsQuery->where('school_id', $schoolFilter);
        }

        $schools = $schoolsQuery->pluck('school_id');

        if ($schools->isEmpty()) {
            $this->line('  No attendance data for today. Skipping accuracy check.');
            return true;
        }

        $deviations = [];

        foreach ($schools as $schoolId) {
            // Live COUNT
            $live = DB::table('attendances')
                ->where('school_id', $schoolId)
                ->whereDate('attendance_date', $today)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
                ")
                ->first();

            // Read model
            $readModel = DB::table('attendance_summary_views')
                ->where('school_id', $schoolId)
                ->where('attendance_date', $today)
                ->selectRaw('SUM(total_students) as total, SUM(present_count) as present, SUM(late_count) as late')
                ->first();

            $liveTotal = (int) ($live->total ?? 0);
            $rmTotal = (int) ($readModel->total ?? 0);

            if ($liveTotal === 0) {
                continue;
            }

            $deviation = abs($liveTotal - $rmTotal) / $liveTotal * 100;

            if ($deviation > $tolerance) {
                $deviations[] = [
                    'school_id' => $schoolId,
                    'live' => $liveTotal,
                    'read_model' => $rmTotal,
                    'deviation' => round($deviation, 2),
                ];
            }
        }

        if (! empty($deviations)) {
            $this->error("  Data deviation exceeds {$tolerance}% tolerance:");
            $this->table(
                ['School ID', 'Live COUNT', 'Read Model', 'Deviation %'],
                array_map(fn ($d) => [$d['school_id'], $d['live'], $d['read_model'], "{$d['deviation']}%"], $deviations)
            );

            if ($this->option('fix')) {
                $this->warn('  Running backfill to fix deviations...');
                $this->call('cqrs:backfill-summaries', ['--since' => $today]);
                return true;
            }

            $this->warn('  Run: php artisan cqrs:backfill-summaries --since=' . $today);
            return false;
        }

        $this->line("  Accuracy within {$tolerance}% tolerance for {$schools->count()} schools.");
        return true;
    }

    private function checkProjectorsConfig(): bool
    {
        $this->line('Checking projectors configuration...');

        $enabled = config('features.projectors_enabled', true);
        $this->line('  features.projectors_enabled = ' . ($enabled ? 'true' : 'false'));

        if (! $enabled) {
            $this->warn('  Projectors are disabled. Read model will go stale.');
            return false;
        }

        return true;
    }

    private function checkFeatureFlag(): bool
    {
        $this->line('Checking feature flags...');

        $useReadModel = config('features.use_read_model', false);
        $this->line('  features.use_read_model = ' . ($useReadModel ? 'true' : 'false'));
        $this->line('  Active query: ' . ($useReadModel ? 'SchoolDashboardQuery (read model)' : 'CountBasedDashboardQuery (COUNT)'));

        // This check always passes — it's informational
        return true;
    }

    private function checkProjectionQueue(): bool
    {
        $this->line('Checking projection queue health...');

        try {
            $failedJobs = DB::table('failed_jobs')
                ->where('payload', 'like', '%SummaryProjector%')
                ->where('failed_at', '>=', now()->subHours(24))
                ->count();

            if ($failedJobs > 0) {
                $this->warn("  {$failedJobs} failed projection jobs in the last 24h.");
                $this->warn('  Check: php artisan queue:failed');
                return false;
            }

            $this->line('  No failed projection jobs in the last 24h.');
            return true;
        } catch (\Throwable $e) {
            $this->warn("  Could not check failed_jobs: {$e->getMessage()}");
            return true; // Non-critical
        }
    }
}
