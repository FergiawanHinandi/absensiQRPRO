<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class VerifyAndActivateAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounts:verify-activate
                            {--roles= : Comma-separated roles to verify (default: all official roles)}
                            {--school= : Filter by school ID}
                            {--dry-run : Show what would change without modifying data}
                            {--force : Skip confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify role consistency and activate inactive accounts safely';

    /**
     * Official role types supported by the application.
     */
    private const OFFICIAL_ROLES = [
        'super_admin',
        'admin',
        'school_admin',
        'principal',
        'vice_principal',
        'teacher',
        'homeroom_teacher',
        'staff',
        'student',
        'parent',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $roles = $this->parseRoles((string) $this->option('roles'));
        if ($roles === null) {
            $rolesOption = (string) $this->option('roles');
            $unknown = array_values(array_filter(array_map('trim', explode(',', $rolesOption))));
            $unknown = array_values(array_diff($unknown, self::OFFICIAL_ROLES));

            $this->error('Unknown role(s): '.implode(', ', $unknown));
            $this->line('Allowed roles: '.implode(', ', self::OFFICIAL_ROLES));

            return Command::FAILURE;
        }

        $schoolId = $this->option('school');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $usersQuery = User::query()
            ->with(['school', 'roles'])
            ->whereIn('role_type', $roles)
            ->orderBy('school_id')
            ->orderBy('role_type')
            ->orderBy('name');

        if ($schoolId !== null && $schoolId !== '') {
            $usersQuery->where('school_id', $schoolId);
        }

        $users = $usersQuery->get();

        $this->info('🔎 Verifying accounts and roles...');
        $this->line('Roles: '.implode(', ', $roles));
        $this->line('Total users found: '.$users->count());

        if ($users->isEmpty()) {
            $this->warn('No matching users found.');

            return Command::SUCCESS;
        }

        $summary = [];
        foreach ($roles as $role) {
            $roleUsers = $users->where('role_type', $role);
            $summary[] = [
                'Role' => $role,
                'Total' => $roleUsers->count(),
                'Inactive' => $roleUsers->where('is_active', false)->count(),
                'Locked' => $roleUsers->filter(fn (User $user) => filled($user->locked_until))->count(),
            ];
        }

        $this->newLine();
        $this->table(['Role', 'Total', 'Inactive', 'Locked'], $summary);

        $targets = $users->filter(function (User $user) {
            return ! $user->is_active
                || filled($user->locked_until)
                || (int) ($user->failed_login_attempts ?? 0) > 0
                || $this->missingRequiredRoles($user) !== [];
        });

        if ($targets->isEmpty()) {
            $this->info('✅ All matching accounts are already active and role-consistent.');

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('Accounts that will be updated: '.$targets->count());

        $previewRows = $targets->map(function (User $user) {
            $missingRoles = $this->missingRequiredRoles($user);

            return [
                'ID' => $user->id,
                'Name' => $user->name,
                'Role' => $user->role_type,
                'Active' => $user->is_active ? 'Yes' : 'No',
                'Locked' => filled($user->locked_until) ? 'Yes' : 'No',
                'Missing Roles' => $missingRoles === [] ? '-' : implode(', ', $missingRoles),
                'School' => $user->school?->name ?? 'N/A',
            ];
        })->all();

        $this->table(['ID', 'Name', 'Role', 'Active', 'Locked', 'Missing Roles', 'School'], $previewRows);

        if ($dryRun) {
            $this->warn('Dry run mode enabled. No changes were made.');

            return Command::SUCCESS;
        }

        if (! $force && ! $this->confirm('Proceed with activating and syncing these accounts?', false)) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        foreach ($roles as $role) {
            Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'sanctum',
            ]);
        }

        $activatedCount = 0;
        $roleSyncedCount = 0;

        DB::transaction(function () use ($targets, &$activatedCount, &$roleSyncedCount) {
            foreach ($targets as $user) {
                $requiredRoles = $this->requiredRolesForUser($user);
                $missingRoles = array_values(array_diff($requiredRoles, $user->roles->pluck('name')->all()));

                if ($missingRoles !== []) {
                    $user->assignRole($missingRoles);
                    $roleSyncedCount += count($missingRoles);
                }

                $wasInactive = ! $user->is_active;

                $user->forceFill([
                    'is_active' => true,
                    'failed_login_attempts' => 0,
                    'last_failed_login_at' => null,
                    'locked_until' => null,
                ])->save();

                if ($wasInactive) {
                    $activatedCount++;
                }

                activity()
                    ->causedBy(null)
                    ->performedOn($user)
                    ->withProperties([
                        'command' => 'accounts:verify-activate',
                        'role_type' => $user->role_type,
                        'user_id' => $user->id,
                        'school_id' => $user->school_id,
                        'activated' => $wasInactive,
                        'synced_roles' => $missingRoles,
                        'dry_run' => false,
                    ])
                    ->log('Account verified and activated via CLI');
            }
        });

        $this->newLine();
        $this->info("✅ Activated {$activatedCount} account(s).");
        $this->info("✅ Synced {$roleSyncedCount} missing role assignment(s).");

        return Command::SUCCESS;
    }

    /**
     * Parse role filter input.
     *
     * @return array<int, string>|null
     */
    private function parseRoles(string $rolesOption): ?array
    {
        if (trim($rolesOption) === '') {
            return self::OFFICIAL_ROLES;
        }

        $roles = array_values(array_filter(array_map('trim', explode(',', $rolesOption))));
        $unknown = array_values(array_diff($roles, self::OFFICIAL_ROLES));

        if ($unknown !== []) {
            return null;
        }

        return $roles;
    }

    /**
     * Determine required Spatie roles for a user.
     *
     * @return array<int, string>
     */
    private function requiredRolesForUser(User $user): array
    {
        $roles = [$user->role_type];

        if ($user->role_type === 'homeroom_teacher') {
            $roles[] = 'teacher';
        }

        return array_values(array_unique($roles));
    }

    /**
     * Get missing required roles for a user.
     *
     * @return array<int, string>
     */
    private function missingRequiredRoles(User $user): array
    {
        return array_values(array_diff($this->requiredRolesForUser($user), $user->roles->pluck('name')->all()));
    }
}