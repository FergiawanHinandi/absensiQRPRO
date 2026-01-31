<?php

namespace App\Console\Commands;

use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteTestSchool extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'school:delete-test
                            {--id= : School ID to delete}
                            {--name= : School name to search and delete}
                            {--force : Skip confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete test/dummy school data (SECURE: CLI only with confirmation)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $schoolId = $this->option('id');
        $schoolName = $this->option('name');
        $force = $this->option('force');

        // Require at least one identifier
        if (! $schoolId && ! $schoolName) {
            $this->error('❌ Please provide either --id or --name option.');
            $this->info('Example: php artisan school:delete-test --name="Sekolah Dummy"');

            return Command::FAILURE;
        }

        // Find school
        $query = School::query();

        if ($schoolId) {
            $query->where('id', $schoolId);
        } elseif ($schoolName) {
            $query->where('name', 'LIKE', "%{$schoolName}%");
        }

        $schools = $query->get();

        if ($schools->isEmpty()) {
            $this->error('❌ No school found with the specified criteria.');

            return Command::FAILURE;
        }

        if ($schools->count() > 1) {
            $this->warn("⚠️  Found {$schools->count()} schools matching your criteria:");
            $this->table(
                ['ID', 'Name', 'Email', 'Active', 'Package'],
                $schools->map(fn ($s) => [
                    $s->id,
                    $s->name,
                    $s->email,
                    $s->is_active ? '✅' : '❌',
                    $s->package_type ?? 'N/A',
                ])
            );
            $this->error('Please specify a more specific criteria using --id.');

            return Command::FAILURE;
        }

        $school = $schools->first();

        // Display school info
        $this->info('📋 School Information:');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $school->id],
                ['Name', $school->name],
                ['Email', $school->email],
                ['Phone', $school->phone ?? 'N/A'],
                ['Active', $school->is_active ? '✅ Yes' : '❌ No'],
                ['Package', $school->package_type ?? 'N/A'],
                ['Created', $school->created_at->format('Y-m-d H:i:s')],
            ]
        );

        // Get related data counts
        $stats = [
            'users' => DB::table('users')->where('school_id', $school->id)->count(),
            'classes' => DB::table('classes')->where('school_id', $school->id)->count(),
            'schedules' => DB::table('schedules')->where('school_id', $school->id)->count(),
            'attendances' => DB::table('attendances')->where('school_id', $school->id)->count(),
            'qr_codes' => DB::table('qr_codes')->where('school_id', $school->id)->count(),
        ];

        $this->newLine();
        $this->warn('⚠️  Related data that will be deleted:');
        $this->table(
            ['Type', 'Count'],
            [
                ['Users (Students, Teachers, Admins)', $stats['users']],
                ['Classes', $stats['classes']],
                ['Schedules', $stats['schedules']],
                ['Attendance Records', $stats['attendances']],
                ['QR Codes', $stats['qr_codes']],
            ]
        );

        // Safety check - prevent deletion of production schools
        if ($stats['users'] > 100 || $stats['attendances'] > 1000) {
            $this->error('❌ SAFETY CHECK FAILED: This school has too much data to be a test school.');
            $this->error('   Users: '.$stats['users'].' | Attendances: '.$stats['attendances']);
            $this->error('   If you really need to delete this, please do it manually via database.');

            return Command::FAILURE;
        }

        // Confirmation
        if (! $force) {
            $this->newLine();
            $this->error('🚨 DANGER ZONE 🚨');
            $this->error('This action is IRREVERSIBLE and will delete ALL related data!');
            $this->newLine();

            if (! $this->confirm('Are you absolutely sure you want to delete this school?', false)) {
                $this->info('Operation cancelled.');

                return Command::SUCCESS;
            }

            // Double confirmation for extra safety
            $confirmName = $this->ask('Type the school name to confirm');
            if ($confirmName !== $school->name) {
                $this->error('❌ School name does not match. Operation cancelled.');

                return Command::FAILURE;
            }
        }

        // Delete school (cascade will handle related data)
        try {
            DB::beginTransaction();

            $this->info('🗑️  Deleting school and related data...');

            // Log before deletion
            activity()
                ->causedBy(null)
                ->withProperties([
                    'command' => 'school:delete-test',
                    'school_id' => $school->id,
                    'school_name' => $school->name,
                    'stats' => $stats,
                    'ip' => request()->ip() ?? 'CLI',
                ])
                ->log('Test school deleted via CLI command');

            $school->delete();

            DB::commit();

            $this->info("✅ School '{$school->name}' (ID: {$school->id}) has been deleted successfully.");
            $this->warn('🔐 This action has been logged for security audit.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();

            $this->error("❌ Failed to delete school: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error('Stack trace:');
                $this->line($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
