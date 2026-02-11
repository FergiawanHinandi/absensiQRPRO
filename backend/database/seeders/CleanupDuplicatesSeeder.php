<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Duplicate Data Cleanup Seeder
 * 
 * Cleans up duplicate records before running data integrity migration.
 * 
 * SAFETY FEATURES:
 * - Dry-run mode to preview changes
 * - Keeps the oldest record (first created)
 * - Logs all deletions
 * - Transaction support with rollback
 * 
 * USAGE:
 * php artisan db:seed --class=CleanupDuplicatesSeeder
 * php artisan db:seed --class=CleanupDuplicatesSeeder --dry-run
 * 
 * @author Database Reliability Engineer
 */
class CleanupDuplicatesSeeder extends Seeder
{
    private bool $dryRun = false;
    private array $stats = [
        'attendances' => 0,
        'users_email' => 0,
        'users_username' => 0,
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check for dry-run flag
        $this->dryRun = app()->runningInConsole() && 
                        in_array('--dry-run', $_SERVER['argv'] ?? []);

        if ($this->dryRun) {
            $this->command->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->command->line('');
        }

        $this->command->info('Starting duplicate cleanup...');
        $this->command->line('');

        DB::beginTransaction();

        try {
            // Clean up duplicate attendances
            $this->cleanupDuplicateAttendances();

            // Clean up duplicate user emails
            $this->cleanupDuplicateUserEmails();

            // Clean up duplicate user usernames
            $this->cleanupDuplicateUserUsernames();

            if ($this->dryRun) {
                DB::rollBack();
                $this->command->warn('🔄 Rolled back all changes (dry-run mode)');
            } else {
                DB::commit();
                $this->command->info('✅ All changes committed successfully');
            }

            $this->command->line('');
            $this->printSummary();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Error during cleanup: ' . $e->getMessage());
            Log::error('duplicate_cleanup_failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Clean up duplicate attendance records
     */
    private function cleanupDuplicateAttendances(): void
    {
        $this->command->info('📋 Checking for duplicate attendances...');

        // Find duplicates
        $duplicates = DB::select("
            SELECT 
                schedule_id, 
                student_id, 
                DATE(attendance_date) as date,
                COUNT(*) as count,
                MIN(id) as keep_id
            FROM attendances
            GROUP BY schedule_id, student_id, DATE(attendance_date)
            HAVING COUNT(*) > 1
        ");

        if (count($duplicates) === 0) {
            $this->command->line('  ✓ No duplicate attendances found');
            return;
        }

        $this->command->warn('  Found ' . count($duplicates) . ' duplicate attendance groups');

        foreach ($duplicates as $dup) {
            // Get all IDs for this duplicate group
            $ids = DB::table('attendances')
                ->where('schedule_id', $dup->schedule_id)
                ->where('student_id', $dup->student_id)
                ->whereDate('attendance_date', $dup->date)
                ->pluck('id')
                ->toArray();

            // Keep the oldest (first created) record
            $idsToDelete = array_diff($ids, [$dup->keep_id]);

            if (count($idsToDelete) > 0) {
                $this->command->line("  - Schedule: {$dup->schedule_id}, Student: {$dup->student_id}, Date: {$dup->date}");
                $this->command->line("    Keeping ID: {$dup->keep_id}, Deleting: " . implode(', ', $idsToDelete));

                if (!$this->dryRun) {
                    DB::table('attendances')
                        ->whereIn('id', $idsToDelete)
                        ->delete();

                    Log::info('attendance_duplicates_deleted', [
                        'schedule_id' => $dup->schedule_id,
                        'student_id' => $dup->student_id,
                        'date' => $dup->date,
                        'kept_id' => $dup->keep_id,
                        'deleted_ids' => $idsToDelete,
                    ]);
                }

                $this->stats['attendances'] += count($idsToDelete);
            }
        }

        $this->command->info("  ✓ Cleaned up {$this->stats['attendances']} duplicate attendance records");
    }

    /**
     * Clean up duplicate user emails within same school
     */
    private function cleanupDuplicateUserEmails(): void
    {
        $this->command->info('📧 Checking for duplicate user emails...');

        // Find duplicates
        $duplicates = DB::select("
            SELECT 
                school_id, 
                email,
                COUNT(*) as count,
                MIN(id) as keep_id
            FROM users
            WHERE email IS NOT NULL
            GROUP BY school_id, email
            HAVING COUNT(*) > 1
        ");

        if (count($duplicates) === 0) {
            $this->command->line('  ✓ No duplicate user emails found');
            return;
        }

        $this->command->warn('  Found ' . count($duplicates) . ' duplicate email groups');

        foreach ($duplicates as $dup) {
            // Get all IDs for this duplicate group
            $ids = DB::table('users')
                ->where('school_id', $dup->school_id)
                ->where('email', $dup->email)
                ->pluck('id')
                ->toArray();

            // Keep the oldest (first created) record
            $idsToDelete = array_diff($ids, [$dup->keep_id]);

            if (count($idsToDelete) > 0) {
                $this->command->line("  - School: {$dup->school_id}, Email: {$dup->email}");
                $this->command->line("    Keeping ID: {$dup->keep_id}, Deleting: " . implode(', ', $idsToDelete));

                if (!$this->dryRun) {
                    // Update email to make it unique before deletion
                    foreach ($idsToDelete as $id) {
                        DB::table('users')
                            ->where('id', $id)
                            ->update(['email' => "deleted_{$id}_{$dup->email}"]);
                    }

                    Log::info('user_email_duplicates_cleaned', [
                        'school_id' => $dup->school_id,
                        'email' => $dup->email,
                        'kept_id' => $dup->keep_id,
                        'modified_ids' => $idsToDelete,
                    ]);
                }

                $this->stats['users_email'] += count($idsToDelete);
            }
        }

        $this->command->info("  ✓ Cleaned up {$this->stats['users_email']} duplicate user emails");
    }

    /**
     * Clean up duplicate user usernames within same school
     */
    private function cleanupDuplicateUserUsernames(): void
    {
        $this->command->info('👤 Checking for duplicate user usernames...');

        // Find duplicates
        $duplicates = DB::select("
            SELECT 
                school_id, 
                username,
                COUNT(*) as count,
                MIN(id) as keep_id
            FROM users
            WHERE username IS NOT NULL
            GROUP BY school_id, username
            HAVING COUNT(*) > 1
        ");

        if (count($duplicates) === 0) {
            $this->command->line('  ✓ No duplicate user usernames found');
            return;
        }

        $this->command->warn('  Found ' . count($duplicates) . ' duplicate username groups');

        foreach ($duplicates as $dup) {
            // Get all IDs for this duplicate group
            $ids = DB::table('users')
                ->where('school_id', $dup->school_id)
                ->where('username', $dup->username)
                ->pluck('id')
                ->toArray();

            // Keep the oldest (first created) record
            $idsToDelete = array_diff($ids, [$dup->keep_id]);

            if (count($idsToDelete) > 0) {
                $this->command->line("  - School: {$dup->school_id}, Username: {$dup->username}");
                $this->command->line("    Keeping ID: {$dup->keep_id}, Deleting: " . implode(', ', $idsToDelete));

                if (!$this->dryRun) {
                    // Update username to make it unique before deletion
                    foreach ($idsToDelete as $id) {
                        DB::table('users')
                            ->where('id', $id)
                            ->update(['username' => "deleted_{$id}_{$dup->username}"]);
                    }

                    Log::info('user_username_duplicates_cleaned', [
                        'school_id' => $dup->school_id,
                        'username' => $dup->username,
                        'kept_id' => $dup->keep_id,
                        'modified_ids' => $idsToDelete,
                    ]);
                }

                $this->stats['users_username'] += count($idsToDelete);
            }
        }

        $this->command->info("  ✓ Cleaned up {$this->stats['users_username']} duplicate user usernames");
    }

    /**
     * Print summary statistics
     */
    private function printSummary(): void
    {
        $this->command->info('═══════════════════════════════════════');
        $this->command->info('CLEANUP SUMMARY');
        $this->command->info('═══════════════════════════════════════');
        
        $total = array_sum($this->stats);
        
        $this->command->table(
            ['Category', 'Records Cleaned'],
            [
                ['Duplicate Attendances', $this->stats['attendances']],
                ['Duplicate User Emails', $this->stats['users_email']],
                ['Duplicate User Usernames', $this->stats['users_username']],
                ['TOTAL', $total],
            ]
        );

        if ($total === 0) {
            $this->command->info('✅ No duplicates found. Database is clean!');
        } else {
            if ($this->dryRun) {
                $this->command->warn("⚠️  {$total} records would be cleaned (dry-run mode)");
                $this->command->line('');
                $this->command->info('To apply changes, run:');
                $this->command->line('  php artisan db:seed --class=CleanupDuplicatesSeeder');
            } else {
                $this->command->info("✅ {$total} duplicate records cleaned successfully");
            }
        }

        $this->command->line('');
        $this->command->info('Next step: Run data integrity migration');
        $this->command->line('  php artisan migrate');
    }
}
