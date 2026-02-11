<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

/**
 * TenantScopeBypassAuditService
 *
 * Audit logging service for tenant scope bypass operations.
 *
 * SECURITY CRITICAL:
 * - Logs all attempts to bypass tenant isolation
 * - Tracks who, when, what, and why
 * - Enables security monitoring and compliance
 *
 * USAGE:
 * - Call logBypass() before any withoutGlobalScope() operation
 * - Provide context about why bypass is needed
 * - Review logs regularly for unauthorized access attempts
 *
 * @version 1.0.0
 */
class TenantScopeBypassAuditService
{
    /**
     * Log tenant scope bypass attempt
     *
     * @param string $operation Operation being performed (e.g., 'allTenants', 'queryAllTenants')
     * @param string $model Model class being queried
     * @param array $context Additional context (e.g., filters, IDs)
     * @param string|null $reason Reason for bypass (optional)
     * @return void
     */
    public function logBypass(
        string $operation,
        string $model,
        array $context = [],
        ?string $reason = null
    ): void {
        $user = Auth::user();
        
        $logData = [
            'event' => 'tenant_scope_bypass',
            'operation' => $operation,
            'model' => $model,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'user_role' => $user?->role_type,
            'user_school_id' => $user?->school_id,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'context' => $context,
            'reason' => $reason,
            'timestamp' => now()->toIso8601String(),
            'backtrace' => $this->getCallerInfo(),
        ];

        // Log to dedicated security channel
        Log::channel('tenant_bypass')->warning('Tenant scope bypassed', $logData);

        // Also log to audit channel for compliance
        Log::channel('audit')->info('tenant_scope_bypass', $logData);
    }

    /**
     * Log unauthorized bypass attempt
     *
     * @param string $operation
     * @param string $model
     * @param array $context
     * @return void
     */
    public function logUnauthorizedAttempt(
        string $operation,
        string $model,
        array $context = []
    ): void {
        $user = Auth::user();
        
        $logData = [
            'event' => 'tenant_scope_bypass_unauthorized',
            'operation' => $operation,
            'model' => $model,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'user_role' => $user?->role_type,
            'user_school_id' => $user?->school_id,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
            'severity' => 'CRITICAL',
        ];

        // Log to security channel with CRITICAL severity
        Log::channel('tenant_bypass')->critical('UNAUTHORIZED tenant scope bypass attempt', $logData);

        // Also log to security_json for alerting
        Log::channel('security_json')->critical('unauthorized_tenant_bypass', $logData);
    }

    /**
     * Check if user is authorized to bypass tenant scope
     *
     * @return bool
     */
    public function isAuthorized(): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        // Only super_admin can bypass tenant scope
        return $user->role_type === 'super_admin';
    }

    /**
     * Get caller information for audit trail
     *
     * @return array
     */
    private function getCallerInfo(): array
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        
        $callers = [];
        foreach ($backtrace as $trace) {
            if (isset($trace['file']) && isset($trace['line'])) {
                // Skip this service file
                if (str_contains($trace['file'], 'TenantScopeBypassAuditService.php')) {
                    continue;
                }
                
                $callers[] = [
                    'file' => str_replace(base_path(), '', $trace['file']),
                    'line' => $trace['line'],
                    'function' => $trace['function'] ?? null,
                    'class' => $trace['class'] ?? null,
                ];
            }
        }
        
        return array_slice($callers, 0, 3); // Return top 3 callers
    }

    /**
     * Get bypass statistics for monitoring
     *
     * @param int $days Number of days to look back
     * @return array
     */
    public function getBypassStatistics(int $days = 7): array
    {
        // This would typically query a dedicated audit table
        // For now, return structure for implementation
        
        return [
            'period_days' => $days,
            'total_bypasses' => 0,
            'unauthorized_attempts' => 0,
            'by_user' => [],
            'by_model' => [],
            'by_operation' => [],
        ];
    }
}
