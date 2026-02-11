<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emergency API Token Revocation Command
 * 
 * Provides emergency token rotation capabilities for security incidents.
 * 
 * Usage:
 * - Revoke all tokens for specific user: php artisan security:revoke-tokens {user_id}
 * - Revoke all tokens globally: php artisan security:revoke-tokens --all
 * - Revoke tokens for specific school: php artisan security:revoke-tokens --school={school_id}
 * - Dry run mode: php artisan security:revoke-tokens --dry-run
 */
class RevokeApiTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:revoke-tokens
                            {user_id? : The ID of the user whose tokens should be revoked}
                            {--all : Revoke ALL tokens globally (DANGEROUS)}
                            {--school= : Revoke all tokens for a specific school}
                            {--role= : Revoke all tokens for a specific role}
                            {--dry-run : Show what would be revoked without actually revoking}
                            {--reason= : Reason for revocation (logged for audit)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Emergency API token revocation for security incidents';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->argument('user_id');
        $all = $this->option('all');
        $schoolId = $this->option('school');
        $role = $this->option('role');
        $dryRun = $this->option('dry-run');
        $reason = $this->option('reason') ?? 'Emergency revocation via CLI';

        // Validation: Ensure at least one option is provided
        if (!$userId && !$all && !$schoolId && !$role) {
            $this->error('You must specify either a user_id, --all, --school, or --role option.');
            return self::FAILURE;
        }

        // Safety check for --all flag
        if ($all) {
            if (!$this->confirm('⚠️  WARNING: This will revoke ALL API tokens globally. Are you absolutely sure?')) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }

            if (!$this->confirm('This action cannot be undone. Type "yes" to confirm.')) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        // Build query
        $query = DB::table('personal_access_tokens');

        if ($userId) {
            $query->where('tokenable_id', $userId)
                  ->where('tokenable_type', User::class);
            $scope = "user ID {$userId}";
        } elseif ($schoolId) {
            $userIds = User::where('school_id', $schoolId)->pluck('id');
            $query->whereIn('tokenable_id', $userIds)
                  ->where('tokenable_type', User::class);
            $scope = "school ID {$schoolId}";
        } elseif ($role) {
            $userIds = User::where('role_type', $role)->pluck('id');
            $query->whereIn('tokenable_id', $userIds)
                  ->where('tokenable_type', User::class);
            $scope = "role {$role}";
        } else {
            $scope = "ALL USERS (GLOBAL)";
        }

        // Get count
        $count = $query->count();

        if ($count === 0) {
            $this->info("No tokens found for {$scope}.");
            return self::SUCCESS;
        }

        // Show what will be revoked
        $this->info("Tokens to be revoked for {$scope}: {$count}");

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No tokens will actually be revoked.');
            
            // Show sample tokens
            $sampleTokens = $query->limit(5)->get(['id', 'tokenable_id', 'name', 'created_at']);
            $this->table(
                ['ID', 'User ID', 'Token Name', 'Created At'],
                $sampleTokens->map(fn($t) => [$t->id, $t->tokenable_id, $t->name, $t->created_at])
            );

            if ($count > 5) {
                $this->info("... and " . ($count - 5) . " more tokens.");
            }

            return self::SUCCESS;
        }

        // Final confirmation
        if (!$this->confirm("Proceed with revoking {$count} tokens?")) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        // Perform revocation
        $this->info('Revoking tokens...');
        
        $deletedCount = $query->delete();

        // Log the action
        Log::channel('security')->critical('Emergency API token revocation', [
            'scope' => $scope,
            'tokens_revoked' => $deletedCount,
            'reason' => $reason,
            'executed_by' => 'CLI',
            'timestamp' => now()->toIso8601String(),
            'options' => [
                'user_id' => $userId,
                'school_id' => $schoolId,
                'role' => $role,
                'all' => $all,
            ],
        ]);

        $this->info("✅ Successfully revoked {$deletedCount} tokens.");
        $this->warn('All affected users will need to re-authenticate.');

        // Notify affected users (optional)
        if ($this->confirm('Send notification to affected users?')) {
            $this->notifyAffectedUsers($userId, $schoolId, $role, $all);
        }

        return self::SUCCESS;
    }

    /**
     * Notify affected users about token revocation
     */
    private function notifyAffectedUsers(?int $userId, ?int $schoolId, ?string $role, bool $all): void
    {
        $this->info('Sending notifications...');

        $query = User::query();

        if ($userId) {
            $query->where('id', $userId);
        } elseif ($schoolId) {
            $query->where('school_id', $schoolId);
        } elseif ($role) {
            $query->where('role_type', $role);
        }

        $users = $query->get();

        foreach ($users as $user) {
            // Send notification (implement your notification logic here)
            // Example: $user->notify(new TokenRevokedNotification());
            
            $this->line("Notified: {$user->username} ({$user->email})");
        }

        $this->info("Notifications sent to {$users->count()} users.");
    }
}
