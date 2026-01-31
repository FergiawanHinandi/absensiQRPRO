<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CheckAdminAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:check
                            {--role=school_admin : Role to check (school_admin, super_admin, etc)}
                            {--school= : Filter by school ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check admin accounts and their status (SECURE: CLI only)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $role = $this->option('role');
        $schoolId = $this->option('school');

        $this->info('🔍 Checking admin accounts...');
        $this->newLine();

        $query = User::query();

        // Filter by role
        if ($role === 'school_admin') {
            $query->whereHas('roles', function ($q) {
                $q->where('name', 'school_admin');
            });
        } elseif ($role === 'super_admin') {
            $query->where('role_type', 'super_admin');
        } else {
            $query->whereIn('role_type', ['school_admin', 'super_admin', 'admin']);
        }

        // Filter by school
        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        $admins = $query->with('school')->get();

        if ($admins->isEmpty()) {
            $this->warn('No admin accounts found with the specified criteria.');

            return Command::SUCCESS;
        }

        $this->info("Found {$admins->count()} admin account(s):");
        $this->newLine();

        $tableData = [];
        foreach ($admins as $admin) {
            $tableData[] = [
                'ID' => $admin->id,
                'Name' => $admin->name,
                'Email' => $admin->email,
                'Role' => $admin->role_type,
                'Active' => $admin->is_active ? '✅ Yes' : '❌ No',
                'School ID' => $admin->school_id ?? 'N/A',
                'School Name' => $admin->school?->name ?? 'N/A',
                'School Active' => $admin->school ? ($admin->school->is_active ? '✅' : '❌') : 'N/A',
            ];
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Active', 'School ID', 'School Name', 'School Active'],
            $tableData
        );

        // Log this action
        activity()
            ->causedBy(null)
            ->withProperties([
                'command' => 'admin:check',
                'role_filter' => $role,
                'school_filter' => $schoolId,
                'results_count' => $admins->count(),
            ])
            ->log('Admin accounts checked via CLI');

        return Command::SUCCESS;
    }
}
