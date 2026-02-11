<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TenantResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShardManagementCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shard:manage 
                            {action : Action to perform (stats|validate|clear-cache|migrate)}
                            {--school= : School ID for migrate action}
                            {--target= : Target shard for migrate action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage database sharding';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'stats' => $this->showStatistics(),
            'validate' => $this->validateConfiguration(),
            'clear-cache' => $this->clearCache(),
            'migrate' => $this->migrateSchool(),
            default => $this->error("Unknown action: {$action}"),
        };
    }

    /**
     * Show shard statistics
     */
    private function showStatistics(): int
    {
        $this->info('📊 Shard Statistics');
        $this->newLine();

        $stats = TenantResolver::getShardStatistics();

        $headers = ['Shard', 'Range', 'Schools', 'Students', 'Attendances', 'Size (MB)', 'Status'];
        $rows = [];

        foreach ($stats as $stat) {
            if (isset($stat['error'])) {
                $rows[] = [
                    $stat['shard_name'],
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    "❌ {$stat['error']}",
                ];
            } else {
                $rows[] = [
                    $stat['shard_name'],
                    $stat['school_id_range'],
                    number_format($stat['school_count']),
                    number_format($stat['student_count']),
                    number_format($stat['attendance_count']),
                    number_format($stat['size_mb'], 2),
                    $stat['status'] === 'active' ? '✅ Active' : "⚠️ {$stat['status']}",
                ];
            }
        }

        $this->table($headers, $rows);

        // Show totals
        $totalSchools = collect($stats)->sum('school_count');
        $totalStudents = collect($stats)->sum('student_count');
        $totalAttendances = collect($stats)->sum('attendance_count');
        $totalSize = collect($stats)->sum('size_mb');

        $this->newLine();
        $this->info("Total Schools: " . number_format($totalSchools));
        $this->info("Total Students: " . number_format($totalStudents));
        $this->info("Total Attendances: " . number_format($totalAttendances));
        $this->info("Total Size: " . number_format($totalSize, 2) . " MB");

        return self::SUCCESS;
    }

    /**
     * Validate shard configuration
     */
    private function validateConfiguration(): int
    {
        $this->info('🔍 Validating Shard Configuration');
        $this->newLine();

        $validation = TenantResolver::validateShardConfiguration();

        if ($validation['valid']) {
            $this->info("✅ Configuration is valid");
            $this->info("Total shards: {$validation['shard_count']}");
            return self::SUCCESS;
        }

        $this->error("❌ Configuration has issues:");
        $this->newLine();

        foreach ($validation['issues'] as $issue) {
            $this->warn("• [{$issue['type']}] {$issue['message']}");
            
            if (isset($issue['current_range'])) {
                $this->line("  Current: {$issue['current_range']}");
                $this->line("  Next: {$issue['next_range']}");
            }
            
            if (isset($issue['gap_range'])) {
                $this->line("  Gap: {$issue['gap_range']}");
            }
            
            if (isset($issue['duplicates'])) {
                $this->line("  Duplicates: " . implode(', ', $issue['duplicates']));
            }
            
            $this->newLine();
        }

        return self::FAILURE;
    }

    /**
     * Clear shard mapping cache
     */
    private function clearCache(): int
    {
        $this->info('🗑️  Clearing Shard Mapping Cache');
        
        TenantResolver::clearCache();
        
        $this->info('✅ Cache cleared successfully');

        return self::SUCCESS;
    }

    /**
     * Migrate school to different shard
     */
    private function migrateSchool(): int
    {
        $schoolId = (int) $this->option('school');
        $targetShard = $this->option('target');

        if (!$schoolId || !$targetShard) {
            $this->error('❌ Both --school and --target options are required');
            return self::FAILURE;
        }

        $this->info("🔄 Migrating School {$schoolId} to {$targetShard}");
        $this->newLine();

        // Get current shard
        $currentShard = TenantResolver::resolveConnection($schoolId);
        $this->info("Current shard: {$currentShard}");
        $this->info("Target shard: {$targetShard}");

        if ($currentShard === $targetShard) {
            $this->warn('⚠️  School is already on target shard');
            return self::SUCCESS;
        }

        // Confirm
        if (!$this->confirm('Do you want to proceed with migration?')) {
            $this->info('Migration cancelled');
            return self::SUCCESS;
        }

        // Start migration
        $this->info('Starting migration...');

        try {
            DB::connection($targetShard)->beginTransaction();

            // Copy school data
            $this->task('Copying school data', function () use ($schoolId, $currentShard, $targetShard) {
                $school = DB::connection($currentShard)
                    ->table('schools')
                    ->where('id', $schoolId)
                    ->first();

                if ($school) {
                    DB::connection($targetShard)
                        ->table('schools')
                        ->insert((array) $school);
                }

                return true;
            });

            // Copy students
            $this->task('Copying students', function () use ($schoolId, $currentShard, $targetShard) {
                $students = DB::connection($currentShard)
                    ->table('students')
                    ->where('school_id', $schoolId)
                    ->get();

                foreach ($students->chunk(1000) as $chunk) {
                    DB::connection($targetShard)
                        ->table('students')
                        ->insert($chunk->toArray());
                }

                return true;
            });

            // Copy attendances
            $this->task('Copying attendances', function () use ($schoolId, $currentShard, $targetShard) {
                $attendances = DB::connection($currentShard)
                    ->table('attendances')
                    ->where('school_id', $schoolId)
                    ->get();

                foreach ($attendances->chunk(1000) as $chunk) {
                    DB::connection($targetShard)
                        ->table('attendances')
                        ->insert($chunk->toArray());
                }

                return true;
            });

            // Verify counts
            $this->task('Verifying data integrity', function () use ($schoolId, $currentShard, $targetShard) {
                $sourceCount = DB::connection($currentShard)
                    ->table('attendances')
                    ->where('school_id', $schoolId)
                    ->count();

                $targetCount = DB::connection($targetShard)
                    ->table('attendances')
                    ->where('school_id', $schoolId)
                    ->count();

                if ($sourceCount !== $targetCount) {
                    throw new \Exception("Count mismatch: source={$sourceCount}, target={$targetCount}");
                }

                return true;
            });

            DB::connection($targetShard)->commit();

            $this->newLine();
            $this->info('✅ Migration completed successfully');
            $this->warn('⚠️  Remember to:');
            $this->line('  1. Update shard_mapping table');
            $this->line('  2. Clear shard cache (php artisan shard:manage clear-cache)');
            $this->line('  3. Delete data from source shard (after verification)');

            return self::SUCCESS;

        } catch (\Exception $e) {
            DB::connection($targetShard)->rollBack();
            
            $this->newLine();
            $this->error('❌ Migration failed: ' . $e->getMessage());
            
            return self::FAILURE;
        }
    }
}
