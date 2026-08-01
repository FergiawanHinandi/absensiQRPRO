<?php

namespace App\Core\Services\RateLimit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rate Limit Logger
 *
 * Logs violations, bypasses, and monitoring events.
 * Stores violations in database for analysis + alerting.
 *
 * Spec: critical-rate-limiting / tasks.md Task 10
 */
class RateLimitLogger
{
    private string $logChannel;
    private bool   $storeViolations;

    public function __construct()
    {
        $this->logChannel      = config('rate-limiting.monitoring.log_channel', 'security');
        $this->storeViolations = config('rate-limiting.monitoring.store_violations', true);
    }

    /** Log a rate limit violation. */
    public function logViolation(
        Request $request,
        string  $endpointKey,
        string  $rateLimitKey,
        array   $config,
        int     $count
    ): void {
        $user = $request->user();

        $context = [
            'endpoint'     => $endpointKey,
            'key'          => $rateLimitKey,
            'description'  => $config['description'],
            'max_attempts' => $config['max_attempts'],
            'count'        => $count,
            'ip'           => $request->ip(),
            'user_id'      => $user?->id,
            'school_id'    => $user?->school_id,
            'user_agent'   => $request->userAgent(),
            'url'          => $request->fullUrl(),
            'method'       => $request->method(),
            'device_id'    => $request->header('X-Device-ID'),
            'timestamp'    => now()->toIso8601String(),
            'severity'     => $this->getSeverity($endpointKey),
        ];

        Log::channel($this->logChannel)->warning("Rate limit violated: {$endpointKey}", $context);

        // Extra security alert for brute-force-sensitive endpoints
        if (in_array($endpointKey, ['login', 'password_reset'])) {
            Log::channel($this->logChannel)->critical(
                'SECURITY: Brute-force attempt detected',
                array_merge($context, ['potential_attack' => true])
            );
        }

        if ($this->storeViolations) {
            $this->storeViolationRecord($context);
        }
    }

    /** Log an admin bypass event. */
    public function logBypass(Request $request, string $endpointKey): void
    {
        if (!config('rate-limiting.bypass.log_bypasses', true)) {
            return;
        }

        $user = $request->user();

        Log::channel('security')->info('Rate limit bypass applied', [
            'endpoint'  => $endpointKey,
            'user_id'   => $user?->id,
            'role'      => $user?->role_type,
            'school_id' => $user?->school_id,
            'ip'        => $request->ip(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /** Store violation in database for audit and monitoring analysis. */
    private function storeViolationRecord(array $context): void
    {
        try {
            DB::table('rate_limit_violations')->insert([
                'endpoint_key'   => $context['endpoint'],
                'rate_limit_key' => $context['key'],
                'ip_address'     => $context['ip'],
                'user_id'        => $context['user_id'],
                'school_id'      => $context['school_id'],
                'attempt_count'  => $context['count'],
                'max_attempts'   => $context['max_attempts'],
                'user_agent'     => substr($context['user_agent'] ?? '', 0, 500),
                'url'            => substr($context['url'] ?? '', 0, 500),
                'severity'       => $context['severity'],
                'created_at'     => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('RateLimitLogger: Failed to store violation', ['error' => $e->getMessage()]);
        }
    }

    private function getSeverity(string $endpointKey): string
    {
        return match ($endpointKey) {
            'login', 'password_reset' => 'critical',
            'qr_scan', 'export'       => 'high',
            default                   => 'medium',
        };
    }

    /** Get violation statistics for monitoring dashboard. */
    public function getStats(int $hours = 24): array
    {
        try {
            return DB::table('rate_limit_violations')
                ->select('endpoint_key', DB::raw('COUNT(*) as total'), 'severity')
                ->where('created_at', '>=', now()->subHours($hours))
                ->groupBy('endpoint_key', 'severity')
                ->orderByDesc('total')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }
}
