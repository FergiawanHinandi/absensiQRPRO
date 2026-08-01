<?php

namespace App\Services;

use App\Jobs\SendSecurityAlertNotification;
use App\Models\ImmutableSecurityLog;
use App\Models\SecurityAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Immutable Security Log Service
 *
 * Provides tamper-proof audit trail using hash chaining (blockchain-style).
 * Each log entry contains a hash of the previous entry, forming an unbreakable chain.
 */
class ImmutableSecurityLogService
{
    /**
     * The algorithm used for hashing
     */
    private const HASH_ALGORITHM = 'sha256';

    /**
     * Write a new entry to the immutable security log.
     *
     * @param  string  $eventType  The type of security event
     * @param  string  $description  Human-readable description
     * @param  int|null  $userId  Associated user ID
     * @param  int|null  $schoolId  Associated school ID
     * @param  array  $metadata  Additional structured data
     * @return ImmutableSecurityLog The created log entry
     *
     * @throws \RuntimeException If hash chain integrity cannot be maintained
     */
    public function write(
        string $eventType,
        string $description,
        ?int $userId = null,
        ?int $schoolId = null,
        array $metadata = []
    ): ImmutableSecurityLog {
        return DB::transaction(function () use ($eventType, $description, $userId, $schoolId, $metadata) {
            // Lock the table to prevent race conditions
            // This ensures sequential ordering is maintained
            $lastRecord = ImmutableSecurityLog::lockForUpdate()
                ->orderByDesc('sequence_number')
                ->first();

            if (! $lastRecord) {
                throw new \RuntimeException(
                    'Immutable security log chain not initialized. Genesis block missing.'
                );
            }

            // Get request context
            $ipAddress = Request::ip();
            $userAgent = Request::userAgent();

            // Prepare the new record data
            $sequenceNumber = $lastRecord->sequence_number + 1;
            $previousHash = $lastRecord->current_hash;
            $createdAt = now();

            // Calculate the current hash
            $currentHash = $this->calculateHash(
                $eventType,
                $userId,
                $schoolId,
                $description,
                $metadata,
                $previousHash,
                $createdAt,
                $sequenceNumber
            );

            // Create the record
            $record = new ImmutableSecurityLog;
            $record->event_type = $eventType;
            $record->user_id = $userId;
            $record->school_id = $schoolId;
            $record->description = $description;
            $record->metadata = $metadata;
            $record->previous_hash = $previousHash;
            $record->current_hash = $currentHash;
            $record->ip_address = $ipAddress;
            $record->user_agent = $userAgent ? substr($userAgent, 0, 500) : null;
            $record->sequence_number = $sequenceNumber;
            $record->created_at = $createdAt;

            $record->save();

            // Verify the record was saved correctly
            if (! $record->verifyHash()) {
                Log::channel('security')->critical('Hash verification failed after insert', [
                    'sequence_number' => $sequenceNumber,
                    'expected_hash' => $currentHash,
                    'stored_hash' => $record->current_hash,
                ]);

                throw new \RuntimeException('Hash verification failed after insert. Data integrity compromised.');
            }

            return $record;
        }, 5); // 5 retries on deadlock
    }

    /**
     * Calculate SHA256 hash for a log entry.
     */
    public function calculateHash(
        string $eventType,
        ?int $userId,
        ?int $schoolId,
        string $description,
        array $metadata,
        string $previousHash,
        \DateTimeInterface $createdAt,
        int $sequenceNumber
    ): string {
        $hashInput = implode('|', [
            $eventType,
            $userId ?? '',
            $schoolId ?? '',
            $description,
            json_encode($metadata),
            $previousHash,
            $createdAt->format('Y-m-d H:i:s'), // Consistent format - no microseconds
            $sequenceNumber,
        ]);

        return hash(self::HASH_ALGORITHM, $hashInput);
    }

    /**
     * Verify the integrity of the entire hash chain.
     *
     * @param  callable|null  $progressCallback  Optional callback for progress updates
     * @return array Verification result with details
     */
    public function verifyChainIntegrity(?callable $progressCallback = null): array
    {
        $result = [
            'is_valid' => true,
            'total_records' => 0,
            'verified_records' => 0,
            'errors' => [],
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
        ];

        $query = ImmutableSecurityLog::ordered();
        $result['total_records'] = $query->count();

        $previousRecord = null;
        $chunkSize = 1000;

        $query->chunk($chunkSize, function ($records) use (&$result, &$previousRecord, $progressCallback) {
            foreach ($records as $record) {
                // Verify hash integrity
                if (! $record->verifyHash()) {
                    $result['is_valid'] = false;
                    $result['errors'][] = [
                        'type' => 'hash_mismatch',
                        'sequence_number' => $record->sequence_number,
                        'record_id' => $record->id,
                        'expected_hash' => $record->calculateHash(),
                        'stored_hash' => $record->current_hash,
                        'message' => "Hash mismatch at sequence #{$record->sequence_number}",
                    ];
                }

                // Verify chain link
                if (! $record->verifyChainLink($previousRecord)) {
                    $result['is_valid'] = false;
                    $result['errors'][] = [
                        'type' => 'chain_break',
                        'sequence_number' => $record->sequence_number,
                        'record_id' => $record->id,
                        'expected_previous' => $previousRecord?->current_hash,
                        'stored_previous' => $record->previous_hash,
                        'message' => "Chain break at sequence #{$record->sequence_number}",
                    ];
                }

                $result['verified_records']++;
                $previousRecord = $record;

                // Progress callback
                if ($progressCallback && $result['verified_records'] % 100 === 0) {
                    $progressCallback($result['verified_records'], $result['total_records']);
                }
            }
        });

        $result['completed_at'] = now()->toIso8601String();

        // Log the verification result
        $this->logVerificationResult($result);

        return $result;
    }

    /**
     * Log the verification result to the immutable log.
     */
    protected function logVerificationResult(array $result): void
    {
        try {
            $eventType = $result['is_valid']
                ? ImmutableSecurityLog::TYPE_INTEGRITY_CHECK
                : ImmutableSecurityLog::TYPE_TAMPERING_DETECTED;

            $description = $result['is_valid']
                ? "Hash chain integrity verified successfully ({$result['verified_records']} records)"
                : "SECURITY ALERT: Hash chain tampering detected! {$result['verified_records']}/{$result['total_records']} verified";

            $this->write(
                $eventType,
                $description,
                null,
                null,
                [
                    'is_valid' => $result['is_valid'],
                    'total_records' => $result['total_records'],
                    'verified_records' => $result['verified_records'],
                    'error_count' => count($result['errors']),
                    'started_at' => $result['started_at'],
                    'completed_at' => $result['completed_at'],
                ]
            );
        } catch (\Exception $e) {
            Log::channel('security')->error('Failed to log verification result', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle tampering detection - create alerts and notify admins.
     */
    public function handleTamperingDetected(array $verificationResult): void
    {
        Log::channel('security')->critical('SECURITY LOG TAMPERING DETECTED', $verificationResult);

        // Create critical security alert
        try {
            $alertService = app(SecurityAlertService::class);
            $alert = $alertService->createAlert(
                SecurityAlert::TYPE_BEHAVIOR_ANOMALY, // Use existing type
                SecurityAlert::SEVERITY_CRITICAL,
                '⚠️ CRITICAL: Security log tampering detected! Hash chain integrity compromised.',
                [
                    'error_count' => count($verificationResult['errors']),
                    'first_error' => $verificationResult['errors'][0] ?? null,
                    'verified_records' => $verificationResult['verified_records'],
                    'total_records' => $verificationResult['total_records'],
                ],
                null,
                null,
                null,
                null,
                true // Force notify
            );

            // Send immediate notifications to all super admins
            $this->notifySuperAdmins($verificationResult);

        } catch (\Exception $e) {
            Log::channel('security')->error('Failed to create tampering alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify all super admins about tampering detection.
     */
    protected function notifySuperAdmins(array $verificationResult): void
    {
        $superAdmins = \App\Models\User::where('role_type', 'super_admin')
            ->where('is_active', true)
            ->get();

        $message = "⚠️ SECURITY LOG TAMPERING DETECTED\n\n".
            "Verified: {$verificationResult['verified_records']}/{$verificationResult['total_records']} records\n".
            'Errors found: '.count($verificationResult['errors'])."\n\n".
            "IMMEDIATE ACTION REQUIRED!\n".
            'The audit log chain has been compromised.';

        foreach ($superAdmins as $admin) {
            // Create security alert for log tampering
            $alert = \App\Models\SecurityAlert::createAlert([
                'school_id' => null, // System-level alert
                'type' => 'log_tampering_detected',
                'severity' => 'critical',
                'description' => $message,
                'ip_address' => request()->ip(),
            ]);

            // Send notification using the alert object
            if ($alert->shouldNotify()) {
                SendSecurityAlertNotification::dispatch($alert)->onQueue('notifications');
            }
        }
    }

    /**
     * Export hashes for external backup.
     *
     * @param  string|null  $outputPath  Path to save the export
     * @return array Export data with hashes
     */
    public function exportHashes(?string $outputPath = null): array
    {
        $export = [
            'exported_at' => now()->toIso8601String(),
            'algorithm' => self::HASH_ALGORITHM,
            'chain' => [],
        ];

        ImmutableSecurityLog::ordered()
            ->select(['sequence_number', 'event_type', 'previous_hash', 'current_hash', 'created_at'])
            ->chunk(1000, function ($records) use (&$export) {
                foreach ($records as $record) {
                    $export['chain'][] = [
                        'seq' => $record->sequence_number,
                        'type' => $record->event_type,
                        'prev' => $record->previous_hash,
                        'curr' => $record->current_hash,
                        'at' => $record->created_at->toIso8601String(),
                    ];
                }
            });

        $export['total_records'] = count($export['chain']);
        $export['last_hash'] = end($export['chain'])['curr'] ?? null;

        // Calculate checksum of the export itself
        $export['export_checksum'] = hash(
            self::HASH_ALGORITHM,
            json_encode($export['chain'])
        );

        if ($outputPath) {
            file_put_contents($outputPath, json_encode($export, JSON_PRETTY_PRINT));
        }

        return $export;
    }

    // =========================================================================
    // CONVENIENCE METHODS FOR SPECIFIC EVENT TYPES
    // =========================================================================

    /**
     * Log a geofence violation attempt.
     */
    public function logGeofenceViolation(
        int $userId,
        int $schoolId,
        float $latitude,
        float $longitude,
        float $distanceFromSchool,
        array $additionalData = []
    ): ImmutableSecurityLog {
        return $this->write(
            ImmutableSecurityLog::TYPE_GEOFENCE_VIOLATION,
            "User attempted scan outside allowed geofence ({$distanceFromSchool}m from school)",
            $userId,
            $schoolId,
            array_merge([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_meters' => $distanceFromSchool,
            ], $additionalData)
        );
    }

    /**
     * Log a device mismatch attempt.
     */
    public function logDeviceMismatch(
        int $userId,
        int $schoolId,
        string $expectedDevice,
        string $actualDevice,
        array $additionalData = []
    ): ImmutableSecurityLog {
        return $this->write(
            ImmutableSecurityLog::TYPE_DEVICE_MISMATCH,
            'Device mismatch detected during attendance scan',
            $userId,
            $schoolId,
            array_merge([
                'expected_device' => $expectedDevice,
                'actual_device' => $actualDevice,
            ], $additionalData)
        );
    }

    /**
     * Log a QR replay attempt.
     */
    public function logQrReplayAttempt(
        int $userId,
        int $schoolId,
        string $qrPayload,
        int $originalTimestamp,
        array $additionalData = []
    ): ImmutableSecurityLog {
        return $this->write(
            ImmutableSecurityLog::TYPE_QR_REPLAY_ATTEMPT,
            'QR code replay attack detected',
            $userId,
            $schoolId,
            array_merge([
                'qr_payload_hash' => hash('sha256', $qrPayload),
                'original_timestamp' => $originalTimestamp,
                'replay_attempted_at' => now()->timestamp,
            ], $additionalData)
        );
    }

    /**
     * Log a behavior anomaly detection.
     */
    public function logBehaviorAnomaly(
        int $userId,
        int $schoolId,
        string $riskLevel,
        int $riskScore,
        array $triggeredFlags,
        array $additionalData = []
    ): ImmutableSecurityLog {
        return $this->write(
            ImmutableSecurityLog::TYPE_BEHAVIOR_ANOMALY,
            "Behavior anomaly detected: {$riskLevel} risk (score: {$riskScore})",
            $userId,
            $schoolId,
            array_merge([
                'risk_level' => $riskLevel,
                'risk_score' => $riskScore,
                'triggered_flags' => $triggeredFlags,
            ], $additionalData)
        );
    }

    /**
     * Log investigation report generation.
     */
    public function logInvestigationReport(
        int $generatedBy,
        int $teacherId,
        int $schoolId,
        int $reportId,
        string $riskLevel,
        array $additionalData = []
    ): ImmutableSecurityLog {
        return $this->write(
            ImmutableSecurityLog::TYPE_INVESTIGATION_REPORT,
            "Security investigation report generated for teacher #{$teacherId}",
            $generatedBy,
            $schoolId,
            array_merge([
                'report_id' => $reportId,
                'teacher_id' => $teacherId,
                'risk_level' => $riskLevel,
            ], $additionalData)
        );
    }

    /**
     * Log admin action.
     */
    public function logAdminAction(
        int $adminId,
        ?int $schoolId,
        string $action,
        string $description,
        array $additionalData = []
    ): ImmutableSecurityLog {
        return $this->write(
            ImmutableSecurityLog::TYPE_ADMIN_ACTION,
            $description,
            $adminId,
            $schoolId,
            array_merge([
                'action' => $action,
            ], $additionalData)
        );
    }

    /**
     * Get recent security logs for monitoring and testing.
     * Returns logs from the last N hours.
     */
    public function getRecentLogs(int $hours = 24, int $limit = 100): array
    {
        try {
            $logs = ImmutableSecurityLog::where('created_at', '>=', now()->subHours($hours))
                ->orderByDesc('sequence_number')
                ->limit($limit)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'event' => $log->event_type,
                        'type' => $log->event_type,
                        'description' => $log->description,
                        'user_id' => $log->user_id,
                        'school_id' => $log->school_id,
                        'metadata' => $log->metadata,
                        'sequence_number' => $log->sequence_number,
                        'timestamp' => $log->created_at,
                        'created_at' => $log->created_at,
                    ];
                })
                ->toArray();

            return $logs;
        } catch (\Exception $e) {
            Log::error('ImmutableSecurityLogService: Failed to get recent logs', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Log a security event (convenience method for tests).
     */
    public function logSecurityEvent(array $eventData): void
    {
        $this->write(
            $eventData['event'] ?? 'unknown_event',
            $eventData['description'] ?? $eventData['message'] ?? '',
            $eventData['user_id'] ?? null,
            $eventData['school_id'] ?? null,
            $eventData
        );
    }

}
