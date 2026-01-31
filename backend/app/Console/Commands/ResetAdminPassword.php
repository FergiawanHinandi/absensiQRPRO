<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ResetAdminPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:reset-password
                            {email : Email of the admin account}
                            {--password= : New password (will prompt if not provided)}
                            {--force : Skip confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset admin password securely (SECURE: CLI only with confirmation)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $password = $this->option('password');
        $force = $this->option('force');

        // Validate email
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            $this->error('Invalid email format.');

            return Command::FAILURE;
        }

        // Find user
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("❌ User with email '{$email}' not found.");

            return Command::FAILURE;
        }

        // Check if user is admin
        if (! in_array($user->role_type, ['school_admin', 'super_admin', 'admin'])) {
            $this->error("❌ User '{$email}' is not an admin (role: {$user->role_type}).");

            return Command::FAILURE;
        }

        // Display user info
        $this->info('📋 User Information:');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $user->id],
                ['Name', $user->name],
                ['Email', $user->email],
                ['Role', $user->role_type],
                ['School ID', $user->school_id ?? 'N/A'],
                ['School Name', $user->school?->name ?? 'N/A'],
                ['Active', $user->is_active ? '✅ Yes' : '❌ No'],
            ]
        );

        // Get password if not provided
        if (! $password) {
            $password = $this->secret('Enter new password');
            $passwordConfirm = $this->secret('Confirm new password');

            if ($password !== $passwordConfirm) {
                $this->error('❌ Passwords do not match.');

                return Command::FAILURE;
            }
        }

        // Validate password strength
        if (strlen($password) < 8) {
            $this->error('❌ Password must be at least 8 characters long.');

            return Command::FAILURE;
        }

        // Confirmation
        if (! $force) {
            $this->warn('⚠️  WARNING: You are about to reset the password for this admin account.');
            if (! $this->confirm('Do you want to continue?', false)) {
                $this->info('Operation cancelled.');

                return Command::SUCCESS;
            }
        }

        // Reset password
        try {
            $user->password = Hash::make($password);
            $user->save();

            $this->info("✅ Password successfully reset for '{$email}'");

            // Log this critical action
            activity()
                ->causedBy(null)
                ->performedOn($user)
                ->withProperties([
                    'command' => 'admin:reset-password',
                    'email' => $email,
                    'user_id' => $user->id,
                    'role' => $user->role_type,
                    'school_id' => $user->school_id,
                    'ip' => request()->ip() ?? 'CLI',
                ])
                ->log('Admin password reset via CLI command');

            $this->newLine();
            $this->warn('🔐 IMPORTANT: This action has been logged for security audit.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Failed to reset password: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
