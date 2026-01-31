<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SecurityAlert;
use App\Services\ImmutableSecurityLogService;
use App\Services\SecurityAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * MobileSecurityController
 * 
 * Handles security event reporting from mobile applications.
 * Logs device security violations (root, emulator, tampering, SSL failures)
 * to the security audit trail for monitoring and incident response.
 */
class MobileSecurityController extends Controller
{
    /**
     * Severity mapping for mobile security events
     */
    private const SEVERITY_MAP = [
        'rooted_device' => 'high',
        'jailbroken_device' => 'high',
        'emulator_detected' => 'critical',
        'debug_mode' => 'medium',
        'app_signature_mismatch' => 'critical',
        'ssl_pinning_failure' => 'critical',
        'screen_recording' => 'medium',
        'developer_options' => 'low',
        'usb_debugging' => 'medium',
        'unknown_sources' => 'medium',
        'device_integrity_check' => 'low',
    ];

    /**
     * Event type descriptions for audit logs
     */
    private const EVENT_DESCRIPTIONS = [
        'rooted_device' => 'Rooted device detected during mobile app usage',
        'jailbroken_device' => 'Jailbroken iOS device detected during mobile app usage',
        'emulator_detected' => 'Emulator/simulator usage attempt detected',
        'debug_mode' => 'Debug mode detected on production build',
        'app_signature_mismatch' => 'App signature mismatch - possible tampering',
        'ssl_pinning_failure' => 'SSL certificate pinning failure - MITM attack suspected',
        'screen_recording' => 'Screen recording detected during sensitive operation',
        'developer_options' => 'Developer options enabled on device',
        'usb_debugging' => 'USB debugging enabled on device',
        'unknown_sources' => 'Installation from unknown sources enabled',
        'device_integrity_check' => 'Device integrity check performed',
    ];

    public function __construct(
        private readonly ImmutableSecurityLogService $securityLogService,
        private readonly SecurityAlertService $alertService
    ) {}

    /**
     * Report a single mobile security event
     * 
     * POST /api/v1/security/mobile-event
     */
    public function reportEvent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_type' => 'required|string|max:100',
            'severity' => 'required|in:low,medium,high,critical',
            'device_info' => 'required|array',
            'device_info.platform' => 'required|string|in:android,ios',
            'device_info.os_version' => 'required|string|max:50',
            'device_info.model' => 'required|string|max:100',
            'device_info.manufacturer' => 'required|string|max:100',
            'device_info.device_fingerprint' => 'required|string|max:100',
            'details' => 'nullable|array',
            'timestamp' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $user = Auth::user();

        // Determine severity (use provided or map from event type)
        $severity = $data['severity'];
        if (isset(self::SEVERITY_MAP[$data['event_type']])) {
            $severity = self::SEVERITY_MAP[$data['event_type']];
        }

        // Log to immutable security log
        $eventId = $this->logSecurityEvent($data, $user, $severity);

        // Create security alert for high/critical events
        if (in_array($severity, ['high', 'critical'])) {
            $this->createSecurityAlert($data, $user, $severity);
        }

        // Log to security channel
        Log::channel('security')->warning('Mobile security event reported', [
            'event_type' => $data['event_type'],
            'severity' => $severity,
            'user_id' => $user?->id,
            'device_fingerprint' => $data['device_info']['device_fingerprint'],
            'platform' => $data['device_info']['platform'],
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Security event recorded',
            'event_id' => $eventId,
        ]);
    }

    /**
     * Report multiple mobile security events in batch
     * 
     * POST /api/v1/security/mobile-events/batch
     */
    public function reportBatch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'events' => 'required|array|min:1|max:50',
            'events.*.event_type' => 'required|string|max:100',
            'events.*.severity' => 'required|in:low,medium,high,critical',
            'events.*.device_info' => 'required|array',
            'events.*.device_info.platform' => 'required|string|in:android,ios',
            'events.*.device_info.os_version' => 'required|string|max:50',
            'events.*.device_info.model' => 'required|string|max:100',
            'events.*.device_info.manufacturer' => 'required|string|max:100',
            'events.*.device_info.device_fingerprint' => 'required|string|max:100',
            'events.*.details' => 'nullable|array',
            'events.*.timestamp' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        $events = $validator->validated()['events'];
        $processedCount = 0;
        $alertsCreated = 0;

        foreach ($events as $eventData) {
            // Determine severity
            $severity = $eventData['severity'];
            if (isset(self::SEVERITY_MAP[$eventData['event_type']])) {
                $severity = self::SEVERITY_MAP[$eventData['event_type']];
            }

            // Log to immutable security log
            $this->logSecurityEvent($eventData, $user, $severity);
            $processedCount++;

            // Create alert for high/critical
            if (in_array($severity, ['high', 'critical'])) {
                $this->createSecurityAlert($eventData, $user, $severity);
                $alertsCreated++;
            }
        }

        Log::channel('security')->info('Mobile security batch processed', [
            'user_id' => $user?->id,
            'events_processed' => $processedCount,
            'alerts_created' => $alertsCreated,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Processed {$processedCount} events",
            'data' => [
                'processed_count' => $processedCount,
                'alerts_created' => $alertsCreated,
            ],
        ]);
    }

    /**
     * Report device integrity check result (called on login)
     * 
     * POST /api/v1/security/device-integrity
     */
    public function reportDeviceIntegrity(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_fingerprint' => 'required|string|max:100',
            'platform' => 'required|string|in:android,ios',
            'os_version' => 'required|string|max:50',
            'model' => 'required|string|max:100',
            'is_secure' => 'required|boolean',
            'risk_level' => 'required|in:none,low,medium,high,critical',
            'violation_count' => 'required|integer|min:0',
            'violations' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $user = Auth::user();

        // Check if this device fingerprint matches stored device binding
        $deviceBindingValid = $this->validateDeviceBinding($user, $data['device_fingerprint']);

        // Log integrity check
        $this->securityLogService->log(
            eventType: 'device_integrity_check',
            eventCategory: 'mobile_security',
            severity: $data['is_secure'] ? 'info' : 'warning',
            actorType: 'user',
            actorId: $user?->id,
            targetType: 'device',
            targetId: $data['device_fingerprint'],
            description: $data['is_secure'] 
                ? 'Device passed integrity check'
                : "Device failed integrity check (risk: {$data['risk_level']})",
            metadata: [
                'platform' => $data['platform'],
                'os_version' => $data['os_version'],
                'model' => $data['model'],
                'is_secure' => $data['is_secure'],
                'risk_level' => $data['risk_level'],
                'violation_count' => $data['violation_count'],
                'device_binding_valid' => $deviceBindingValid,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        // Create alert if device is not secure
        if (!$data['is_secure'] && in_array($data['risk_level'], ['high', 'critical'])) {
            $this->alertService->create(
                type: 'insecure_device',
                severity: $data['risk_level'],
                message: "Insecure device login attempt from {$data['platform']} device",
                userId: $user?->id,
                metadata: [
                    'device_fingerprint' => $data['device_fingerprint'],
                    'platform' => $data['platform'],
                    'model' => $data['model'],
                    'violations' => $data['violations'] ?? [],
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Device integrity recorded',
            'data' => [
                'device_binding_valid' => $deviceBindingValid,
                'requires_admin_approval' => !$deviceBindingValid && $user?->role_type === 'teacher',
            ],
        ]);
    }

    /**
     * Log security event to immutable log
     */
    private function logSecurityEvent(array $data, $user, string $severity): string
    {
        $eventType = $data['event_type'];
        $description = self::EVENT_DESCRIPTIONS[$eventType] ?? "Mobile security event: {$eventType}";

        return $this->securityLogService->log(
            eventType: "mobile_{$eventType}",
            eventCategory: 'mobile_security',
            severity: $this->mapSeverityToLogLevel($severity),
            actorType: $user ? 'user' : 'anonymous',
            actorId: $user?->id,
            targetType: 'device',
            targetId: $data['device_info']['device_fingerprint'],
            description: $description,
            metadata: [
                'platform' => $data['device_info']['platform'],
                'os_version' => $data['device_info']['os_version'],
                'model' => $data['device_info']['model'],
                'manufacturer' => $data['device_info']['manufacturer'],
                'details' => $data['details'] ?? null,
                'reported_at' => $data['timestamp'],
            ],
            ipAddress: request()->ip(),
            userAgent: request()->userAgent()
        );
    }

    /**
     * Create security alert for serious violations
     */
    private function createSecurityAlert(array $data, $user, string $severity): void
    {
        $eventType = $data['event_type'];
        $platform = $data['device_info']['platform'];
        $model = $data['device_info']['model'];

        $this->alertService->create(
            type: $eventType,
            severity: $severity,
            message: self::EVENT_DESCRIPTIONS[$eventType] ?? "Mobile security violation: {$eventType}",
            userId: $user?->id,
            metadata: [
                'device_fingerprint' => $data['device_info']['device_fingerprint'],
                'platform' => $platform,
                'model' => $model,
                'os_version' => $data['device_info']['os_version'],
                'details' => $data['details'] ?? null,
            ]
        );
    }

    /**
     * Map severity string to log level
     */
    private function mapSeverityToLogLevel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'high' => 'error',
            'medium' => 'warning',
            'low' => 'info',
            default => 'info',
        };
    }

    /**
     * Validate device fingerprint against stored binding
     */
    private function validateDeviceBinding($user, string $deviceFingerprint): bool
    {
        if (!$user) {
            return false;
        }

        // Check if user has a stored device binding
        // This would typically be in a user_devices or teacher_devices table
        // For now, we'll check the user's metadata or a dedicated field
        
        $storedFingerprint = $user->device_fingerprint ?? null;
        
        if (!$storedFingerprint) {
            // No binding yet - this is the first device
            return true;
        }

        return $storedFingerprint === $deviceFingerprint;
    }
}
