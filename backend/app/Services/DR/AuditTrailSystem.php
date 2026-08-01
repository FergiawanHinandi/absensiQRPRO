<?php

namespace App\Services\DR;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DR Audit Trail Service
 *
 * Comprehensive, immutable audit logging for all DR operations.
 *
 * Spec: disaster-recovery-audit-improvements / tasks.md Task 13.3
 */
class AuditTrailSystem
{
    /**
     * Log a DR event to the immutable audit trail.
     */
    public function log(
        string   $eventType,
        string   $severity  = 'info',
        array    $details   = [],
        ?int     $operationId = null,
        ?int     $schoolId   = null
    ): void {
        try {
            DB::table('dr_audit_log')->insert([
                'operation_id' => $operationId,
                'school_id'    => $schoolId,
                'event_type'   => $eventType,
                'severity'     => $severity,
                'actor_type'   => $this->resolveActorType(),
                'actor_id'     => auth()->id(),
                'details'      => json_encode($details),
                'ip_address'   => request()?->ip(),
                'occurred_at'  => now(),
            ]);
        } catch (\Exception $e) {
            // Never let audit logging failure crash the application
            Log::critical('AuditTrailSystem: Failed to write audit log', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log backup started.
     */
    public function backupStarted(int $operationId, ?int $schoolId, array $context = []): void
    {
        $this->log('backup_started', 'info', $context, $operationId, $schoolId);
    }

    /**
     * Log backup completed.
     */
    public function backupCompleted(int $operationId, ?int $schoolId, array $context = []): void
    {
        $this->log('backup_completed', 'info', $context, $operationId, $schoolId);
    }

    /**
     * Log backup failed.
     */
    public function backupFailed(int $operationId, ?int $schoolId, string $error): void
    {
        $this->log('backup_failed', 'error', ['error' => $error], $operationId, $schoolId);
    }

    /**
     * Log restore started.
     */
    public function restoreStarted(int $operationId, ?int $schoolId, array $context = []): void
    {
        $this->log('restore_started', 'warning', $context, $operationId, $schoolId);
    }

    /**
     * Log restore completed.
     */
    public function restoreCompleted(int $operationId, ?int $schoolId, array $context = []): void
    {
        $this->log('restore_completed', 'info', $context, $operationId, $schoolId);
    }

    /**
     * Log a security violation (cross-tenant attempt etc.).
     */
    public function securityViolation(string $violation, array $context): void
    {
        $this->log('security_violation', 'critical', array_merge(['violation' => $violation], $context));
    }

    /**
     * Log DR drill execution.
     */
    public function drillExecuted(string $drillType, bool $success, array $metrics = []): void
    {
        $this->log('drill_executed', $success ? 'info' : 'warning', [
            'drill_type' => $drillType,
            'success'    => $success,
            'metrics'    => $metrics,
        ]);
    }

    /**
     * Query audit log with filters.
     */
    public function query(
        ?int    $schoolId  = null,
        ?string $eventType = null,
        ?string $severity  = null,
        int     $days      = 7
    ): array {
        $query = DB::table('dr_audit_log')
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at');

        if ($schoolId !== null) {
            $query->where('school_id', $schoolId);
        }
        if ($eventType) {
            $query->where('event_type', $eventType);
        }
        if ($severity) {
            $query->where('severity', $severity);
        }

        return $query->limit(500)->get()->toArray();
    }

    /**
     * Get recent audit logs (last 24 hours).
     * Convenience method for integration tests and monitoring.
     */
    public function getRecentLogs(int $hours = 24, int $limit = 100): array
    {
        try {
            $logs = DB::table('dr_audit_log')
                ->where('occurred_at', '>=', now()->subHours($hours))
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => $row->id ?? null,
                        'event' => $row->event_type,
                        'type' => $row->event_type,
                        'severity' => $row->severity,
                        'actor_type' => $row->actor_type,
                        'actor_id' => $row->actor_id,
                        'school_id' => $row->school_id,
                        'details' => json_decode($row->details ?? '{}', true),
                        'timestamp' => $row->occurred_at,
                        'occurred_at' => $row->occurred_at,
                    ];
                })
                ->toArray();

            return $logs;
        } catch (\Exception $e) {
            Log::error('AuditTrailSystem: Failed to get recent logs', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function resolveActorType(): string
    {
        if (auth()->check()) {
            return 'user';
        }
        if (app()->runningInConsole()) {
            return 'scheduler';
        }
        return 'system';
    }
}
