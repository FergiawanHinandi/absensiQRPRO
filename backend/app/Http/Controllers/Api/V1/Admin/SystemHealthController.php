<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * System Health Monitoring Controller
 *
 * Provides operational visibility for:
 * - Queue health (failed/pending jobs)
 * - Security events (rate limiting, anomalies)
 * - QR code security anomalies
 *
 * Access: Admin only with system:monitor ability
 *
 * @author AbsensiQRPro Team
 *
 * @version 1.0.0
 */
class SystemHealthController extends Controller
{
    /**
     * Get comprehensive system health metrics
     *
     * GET /api/v1/admin/system/health
     *
     * Returns:
     * - queue_failed_last_24h: Failed jobs in last 24 hours
     * - queue_pending: Jobs waiting to be processed
     * - rate_limit_blocks_last_hour: Rate limit violations
     * - qr_anomalies_last_24h: QR security anomalies detected
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function health()
    {
        try {
            // Get all metrics
            $metrics = [
                'queue_failed_last_24h' => $this->getFailedJobsLast24h(),
                'queue_pending' => $this->getPendingJobs(),
                'rate_limit_blocks_last_hour' => $this->getRateLimitBlocksLastHour(),
                'qr_anomalies_last_24h' => $this->getQrAnomaliesLast24h(),
            ];

            // Calculate overall health status
            $healthStatus = $this->calculateHealthStatus($metrics);

            // Generate alerts based on metrics
            $alerts = $this->generateAlerts($metrics);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'metrics' => $metrics,
                    'health_status' => $healthStatus,
                    'alerts' => $alerts,
                    'timestamp' => now()->toIso8601String(),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('System health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve system health metrics',
                'error' => app()->environment('local') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get detailed queue health metrics
     *
     * GET /api/v1/admin/system/health/queue
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function queueHealth()
    {
        try {
            $metrics = [
                'pending_jobs' => $this->getPendingJobs(),
                'failed_jobs_24h' => $this->getFailedJobsLast24h(),
                'failed_jobs_total' => $this->getTotalFailedJobs(),
                'queue_lag_seconds' => $this->getQueueLagSeconds(),
                'failed_jobs_by_queue' => $this->getFailedJobsByQueue(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $metrics,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve queue health',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get detailed security metrics
     *
     * GET /api/v1/admin/system/health/security
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function securityHealth()
    {
        try {
            $metrics = [
                'rate_limit_blocks_1h' => $this->getRateLimitBlocksLastHour(),
                'rate_limit_blocks_24h' => $this->getRateLimitBlocksLast24h(),
                'qr_anomalies_24h' => $this->getQrAnomaliesLast24h(),
                'failed_login_attempts_1h' => $this->getFailedLoginAttemptsLastHour(),
                'suspicious_activities_24h' => $this->getSuspiciousActivitiesLast24h(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $metrics,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve security health',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ========================================
    // PRIVATE HELPER METHODS - QUEUE METRICS
    // ========================================

    /**
     * Get count of failed jobs in last 24 hours
     */
    private function getFailedJobsLast24h(): int
    {
        return Cache::remember('system_health:failed_jobs_24h', 60, function () {
            return DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();
        });
    }

    /**
     * Get count of pending jobs in queue
     */
    private function getPendingJobs(): int
    {
        return Cache::remember('system_health:pending_jobs', 30, function () {
            return DB::table('jobs')->count();
        });
    }

    /**
     * Get total failed jobs (all time)
     */
    private function getTotalFailedJobs(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /**
     * Get queue lag in seconds (oldest pending job age)
     */
    private function getQueueLagSeconds(): int
    {
        $oldestJob = DB::table('jobs')
            ->orderBy('created_at', 'asc')
            ->first();

        if (! $oldestJob) {
            return 0;
        }

        return now()->timestamp - $oldestJob->created_at;
    }

    /**
     * Get failed jobs grouped by queue name
     */
    private function getFailedJobsByQueue(): array
    {
        return DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as count'))
            ->where('failed_at', '>=', now()->subDay())
            ->groupBy('queue')
            ->get()
            ->pluck('count', 'queue')
            ->toArray();
    }

    // ========================================
    // PRIVATE HELPER METHODS - SECURITY METRICS
    // ========================================

    /**
     * Get rate limit blocks in last hour
     *
     * Uses activity_log table to count rate limit violations
     */
    private function getRateLimitBlocksLastHour(): int
    {
        return Cache::remember('system_health:rate_limit_blocks_1h', 60, function () {
            // Check if activity_log table exists
            if (! $this->tableExists('activity_log')) {
                return 0;
            }

            return DB::table('activity_log')
                ->where('description', 'LIKE', '%rate limit%')
                ->where('created_at', '>=', now()->subHour())
                ->count();
        });
    }

    /**
     * Get rate limit blocks in last 24 hours
     */
    private function getRateLimitBlocksLast24h(): int
    {
        return Cache::remember('system_health:rate_limit_blocks_24h', 300, function () {
            if (! $this->tableExists('activity_log')) {
                return 0;
            }

            return DB::table('activity_log')
                ->where('description', 'LIKE', '%rate limit%')
                ->where('created_at', '>=', now()->subDay())
                ->count();
        });
    }

    /**
     * Get QR code security anomalies in last 24 hours
     *
     * Anomalies include:
     * - Invalid HMAC signatures
     * - Expired QR codes
     * - Cross-school QR attempts
     * - Inactive student scans
     */
    private function getQrAnomaliesLast24h(): int
    {
        return Cache::remember('system_health:qr_anomalies_24h', 300, function () {
            if (! $this->tableExists('activity_log')) {
                return 0;
            }

            // Count QR-related security events
            return DB::table('activity_log')
                ->where(function ($query) {
                    $query->where('description', 'LIKE', '%QR%anomaly%')
                        ->orWhere('description', 'LIKE', '%Invalid HMAC%')
                        ->orWhere('description', 'LIKE', '%Expired QR%')
                        ->orWhere('description', 'LIKE', '%Cross-school%')
                        ->orWhere('description', 'LIKE', '%Inactive student%');
                })
                ->where('created_at', '>=', now()->subDay())
                ->count();
        });
    }

    /**
     * Get failed login attempts in last hour
     */
    private function getFailedLoginAttemptsLastHour(): int
    {
        return Cache::remember('system_health:failed_logins_1h', 60, function () {
            if (! $this->tableExists('activity_log')) {
                return 0;
            }

            return DB::table('activity_log')
                ->where('description', 'LIKE', '%failed login%')
                ->where('created_at', '>=', now()->subHour())
                ->count();
        });
    }

    /**
     * Get suspicious activities in last 24 hours
     */
    private function getSuspiciousActivitiesLast24h(): int
    {
        return Cache::remember('system_health:suspicious_24h', 300, function () {
            if (! $this->tableExists('activity_log')) {
                return 0;
            }

            return DB::table('activity_log')
                ->where(function ($query) {
                    $query->where('description', 'LIKE', '%suspicious%')
                        ->orWhere('description', 'LIKE', '%unauthorized%')
                        ->orWhere('description', 'LIKE', '%blocked%');
                })
                ->where('created_at', '>=', now()->subDay())
                ->count();
        });
    }

    // ========================================
    // PRIVATE HELPER METHODS - HEALTH CALCULATION
    // ========================================

    /**
     * Calculate overall health status based on metrics
     *
     * @return string 'healthy', 'degraded', or 'critical'
     */
    private function calculateHealthStatus(array $metrics): string
    {
        $criticalThresholds = [
            'queue_failed_last_24h' => 50,
            'queue_pending' => 1000,
            'rate_limit_blocks_last_hour' => 100,
            'qr_anomalies_last_24h' => 50,
        ];

        $warningThresholds = [
            'queue_failed_last_24h' => 10,
            'queue_pending' => 500,
            'rate_limit_blocks_last_hour' => 20,
            'qr_anomalies_last_24h' => 10,
        ];

        // Check for critical conditions
        foreach ($criticalThresholds as $metric => $threshold) {
            if ($metrics[$metric] >= $threshold) {
                return 'critical';
            }
        }

        // Check for warning conditions
        foreach ($warningThresholds as $metric => $threshold) {
            if ($metrics[$metric] >= $threshold) {
                return 'degraded';
            }
        }

        return 'healthy';
    }

    /**
     * Generate alerts based on metrics
     */
    private function generateAlerts(array $metrics): array
    {
        $alerts = [];

        // Queue alerts
        if ($metrics['queue_failed_last_24h'] > 50) {
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'queue',
                'message' => "Critical: {$metrics['queue_failed_last_24h']} jobs failed in last 24 hours",
                'action' => 'Check logs and failed_jobs table immediately',
            ];
        } elseif ($metrics['queue_failed_last_24h'] > 10) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'queue',
                'message' => "{$metrics['queue_failed_last_24h']} jobs failed in last 24 hours",
                'action' => 'Review failed jobs and retry if applicable',
            ];
        }

        if ($metrics['queue_pending'] > 1000) {
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'queue',
                'message' => "High pending jobs: {$metrics['queue_pending']}",
                'action' => 'Scale queue workers or check for stuck jobs',
            ];
        } elseif ($metrics['queue_pending'] > 500) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'queue',
                'message' => "Elevated pending jobs: {$metrics['queue_pending']}",
                'action' => 'Monitor queue processing rate',
            ];
        }

        // Security alerts
        if ($metrics['rate_limit_blocks_last_hour'] > 100) {
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'security',
                'message' => "High rate limit blocks: {$metrics['rate_limit_blocks_last_hour']} in last hour",
                'action' => 'Possible DDoS attack - review logs and consider IP blocking',
            ];
        } elseif ($metrics['rate_limit_blocks_last_hour'] > 20) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'security',
                'message' => "{$metrics['rate_limit_blocks_last_hour']} rate limit blocks in last hour",
                'action' => 'Monitor for unusual activity patterns',
            ];
        }

        if ($metrics['qr_anomalies_last_24h'] > 50) {
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'security',
                'message' => "High QR anomalies: {$metrics['qr_anomalies_last_24h']} in last 24 hours",
                'action' => 'Investigate potential QR code security breach',
            ];
        } elseif ($metrics['qr_anomalies_last_24h'] > 10) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'security',
                'message' => "{$metrics['qr_anomalies_last_24h']} QR anomalies detected in last 24 hours",
                'action' => 'Review QR security logs',
            ];
        }

        // If no alerts, system is healthy
        if (empty($alerts)) {
            $alerts[] = [
                'severity' => 'info',
                'category' => 'system',
                'message' => 'All systems operational',
                'action' => null,
            ];
        }

        return $alerts;
    }

    /**
     * Check if a database table exists
     */
    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get backup system status
     *
     * GET /api/v1/admin/system/backup-status
     *
     * Returns backup health information including:
     * - Last backup timestamp
     * - Backup age in hours
     * - Health status (healthy/warning/critical)
     * - Storage disk information
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function backupStatus()
    {
        try {
            $backupName = config('backup.backup.name', 'AbsensiQRPro');
            $disks = config('backup.backup.destination.disks', ['local']);

            $statuses = [];
            $overallStatus = 'healthy';
            $oldestBackup = null;
            $newestBackup = null;

            foreach ($disks as $diskName) {
                try {
                    $destination = \Spatie\Backup\BackupDestination\BackupDestination::create($diskName, $backupName);
                    $newest = $destination->newestBackup();

                    $diskStatus = [
                        'disk' => $diskName,
                        'status' => 'healthy',
                        'backup_count' => $destination->backups()->count(),
                        'total_size_bytes' => $destination->usedStorage(),
                        'total_size_human' => \Spatie\Backup\Helpers\Format::humanReadableSize($destination->usedStorage()),
                        'last_backup_at' => null,
                        'backup_age_hours' => null,
                    ];

                    if ($newest) {
                        $ageHours = $newest->date()->diffInHours(now());
                        $diskStatus['last_backup_at'] = $newest->date()->toIso8601String();
                        $diskStatus['backup_age_hours'] = $ageHours;

                        // Update overall timestamps
                        if (! $newestBackup || $newest->date()->gt($newestBackup)) {
                            $newestBackup = $newest->date();
                        }

                        // Determine status based on age
                        if ($ageHours > 48) {
                            $diskStatus['status'] = 'critical';
                            $overallStatus = 'critical';
                        } elseif ($ageHours > 26) {
                            $diskStatus['status'] = 'warning';
                            if ($overallStatus !== 'critical') {
                                $overallStatus = 'warning';
                            }
                        }
                    } else {
                        $diskStatus['status'] = 'critical';
                        $overallStatus = 'critical';
                    }

                    $statuses[] = $diskStatus;

                } catch (\Exception $e) {
                    Log::warning("Could not check backup status for disk: {$diskName}", [
                        'error' => $e->getMessage(),
                    ]);

                    $statuses[] = [
                        'disk' => $diskName,
                        'status' => 'unknown',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'last_backup_at' => $newestBackup?->toIso8601String(),
                    'backup_age_hours' => $newestBackup ? $newestBackup->diffInHours(now()) : null,
                    'status' => $overallStatus,
                    'storage_disks' => $statuses,
                    'encryption_enabled' => ! empty(config('backup.encryption.key')),
                    'retention_policy' => [
                        'daily_backups_days' => config('backup.cleanup.default_strategy.keep_daily_backups_for_days'),
                        'weekly_backups_weeks' => config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks'),
                        'monthly_backups_months' => config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'),
                    ],
                    'checked_at' => now()->toIso8601String(),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Backup status check failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve backup status',
                'error' => app()->environment('local') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
