<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RefreshToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CleanupExpiredTokens
 * 
 * Removes expired and revoked refresh tokens and personal access tokens
 * from the database to maintain performance and comply with data retention.
 */
class CleanupExpiredTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tokens:cleanup 
        {--days=30 : Days to keep revoked tokens for audit trail}
        {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup expired and old revoked tokens from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysToKeep = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $this->info('Starting token cleanup...');
        
        if ($dryRun) {
            $this->warn('DRY RUN - No tokens will be deleted');
        }
        
        $stats = [
            'refresh_tokens_expired' => 0,
            'refresh_tokens_revoked' => 0,
            'access_tokens_expired' => 0,
        ];
        
        // Cleanup expired refresh tokens (keep for audit based on days)
        $expiredRefreshQuery = RefreshToken::where('expires_at', '<', now()->subDays($daysToKeep));
        $stats['refresh_tokens_expired'] = $expiredRefreshQuery->count();
        
        if (!$dryRun && $stats['refresh_tokens_expired'] > 0) {
            $expiredRefreshQuery->delete();
        }
        
        // Cleanup old revoked refresh tokens (keep revoked ones for audit)
        $revokedRefreshQuery = RefreshToken::whereNotNull('revoked_at')
            ->where('revoked_at', '<', now()->subDays($daysToKeep));
        $stats['refresh_tokens_revoked'] = $revokedRefreshQuery->count();
        
        if (!$dryRun && $stats['refresh_tokens_revoked'] > 0) {
            $revokedRefreshQuery->delete();
        }
        
        // Cleanup expired access tokens (personal_access_tokens)
        $expiredAccessQuery = DB::table('personal_access_tokens')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subDays($daysToKeep));
        $stats['access_tokens_expired'] = $expiredAccessQuery->count();
        
        if (!$dryRun && $stats['access_tokens_expired'] > 0) {
            $expiredAccessQuery->delete();
        }
        
        // Output stats
        $this->table(
            ['Token Type', 'Count'],
            [
                ['Expired Refresh Tokens', $stats['refresh_tokens_expired']],
                ['Revoked Refresh Tokens', $stats['refresh_tokens_revoked']],
                ['Expired Access Tokens', $stats['access_tokens_expired']],
            ]
        );
        
        $totalDeleted = array_sum($stats);
        
        if ($dryRun) {
            $this->info("Would delete {$totalDeleted} tokens");
        } else {
            $this->info("Deleted {$totalDeleted} tokens");
            
            // Log cleanup activity
            Log::channel('security')->info('Token cleanup completed', [
                'stats' => $stats,
                'retention_days' => $daysToKeep,
            ]);
        }
        
        return self::SUCCESS;
    }
}
