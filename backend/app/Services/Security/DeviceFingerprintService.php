<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * DeviceFingerprintService
 *
 * Generates secure device fingerprints for:
 * - Rate limiting per device
 * - Anti-joki (proxy attendance) detection
 * - Anomaly logging and security monitoring
 *
 * SECURITY DESIGN:
 * ================
 * 
 * 1. NEVER store raw device_id
 *    - Only SHA-256 hash is stored
 *    - Cannot be reversed to identify specific device
 *    - Protects user privacy
 *
 * 2. Multi-factor fingerprint
 *    - Combines multiple signals for stronger identification
 *    - Harder to spoof than single identifier
 *
 * 3. Salted hashing
 *    - Uses app key as salt
 *    - Prevents rainbow table attacks
 *    - Same device_id produces different hash in different installations
 *
 * @author Security Team
 * @version 1.0.0
 */
final class DeviceFingerprintService
{
    /**
     * Cache prefix for device fingerprints
     */
    private const CACHE_PREFIX = 'device_fp:';

    /**
     * Cache TTL for fingerprint data (24 hours)
     */
    private const CACHE_TTL = 86400;

    /**
     * Maximum allowed IP changes per device per day
     */
    private const MAX_IP_CHANGES = 5;

    /**
     * Salt for hashing (from app key)
     */
    private readonly string $salt;

    public function __construct()
    {
        $this->salt = config('app.key', 'default-salt');
    }

    /**
     * Generate a device fingerprint from request data
     *
     * COMPONENTS:
     * - device_id: Client-provided device identifier
     * - user_id: Authenticated user ID
     * - IP address: Request source IP
     * - User-Agent: Browser/app identifier
     *
     * All components are hashed together using SHA-256 with app key as salt.
     *
     * @param Request $request HTTP request
     * @param int|null $userId Authenticated user ID
     * @param string|null $deviceId Client-provided device ID
     * @return DeviceFingerprint
     */
    public function generate(Request $request, ?int $userId = null, ?string $deviceId = null): DeviceFingerprint
    {
        $rawDeviceId = $deviceId ?? $request->header('X-Device-ID') ?? '';
        $ipAddress = $request->ip() ?? '';
        $userAgent = $request->userAgent() ?? '';
        
        // Extract user ID from authenticated user if not provided
        if ($userId === null && $request->user()) {
            $userId = $request->user()->id;
        }

        // Generate component hashes (for partial matching)
        $deviceHash = $this->hashComponent('device', $rawDeviceId);
        $ipHash = $this->hashComponent('ip', $ipAddress);
        $uaHash = $this->hashComponent('ua', $this->normalizeUserAgent($userAgent));
        
        // Generate full fingerprint (composite hash)
        $fullFingerprint = $this->generateFullFingerprint(
            $rawDeviceId,
            $userId,
            $ipAddress,
            $userAgent
        );

        return new DeviceFingerprint(
            fingerprint: $fullFingerprint,
            deviceHash: $deviceHash,
            ipHash: $ipHash,
            userAgentHash: $uaHash,
            userId: $userId,
            timestamp: now()
        );
    }

    /**
     * Generate the full composite fingerprint
     *
     * Format: SHA256(salt + device_id + user_id + ip + normalized_ua)
     */
    private function generateFullFingerprint(
        string $deviceId,
        ?int $userId,
        string $ipAddress,
        string $userAgent
    ): string {
        $data = implode('|', [
            $this->salt,
            $deviceId,
            $userId ?? 'anonymous',
            $ipAddress,
            $this->normalizeUserAgent($userAgent),
        ]);

        return hash('sha256', $data);
    }

    /**
     * Hash a single component with salt
     */
    private function hashComponent(string $type, string $value): string
    {
        if (empty($value)) {
            return '';
        }

        return hash('sha256', "{$this->salt}:{$type}:{$value}");
    }

    /**
     * Normalize user agent for consistent hashing
     *
     * Removes version numbers that change frequently
     * but keeps browser/app family identification
     */
    private function normalizeUserAgent(string $userAgent): string
    {
        // Extract major identifiers, ignore minor version changes
        $patterns = [
            '/AbsensiQR\/[\d.]+/' => 'AbsensiQR',
            '/Android\s+[\d.]+/' => 'Android',
            '/iPhone\s+OS\s+[\d_]+/' => 'iPhone',
            '/Chrome\/[\d.]+/' => 'Chrome',
            '/Safari\/[\d.]+/' => 'Safari',
            '/Firefox\/[\d.]+/' => 'Firefox',
        ];

        $normalized = $userAgent;
        foreach ($patterns as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized);
        }

        return $normalized;
    }

    /**
     * Check for device anomalies
     *
     * Detects suspicious patterns that might indicate:
     * - Device spoofing
     * - Proxy attendance (joki)
     * - Account sharing
     *
     * @param DeviceFingerprint $fingerprint Current fingerprint
     * @param int $userId User to check
     * @return DeviceAnomalyResult
     */
    public function detectAnomalies(DeviceFingerprint $fingerprint, int $userId): DeviceAnomalyResult
    {
        $anomalies = [];
        $riskScore = 0;

        // 1. Check for multiple IPs from same device
        $ipAnomalies = $this->checkIpAnomalies($fingerprint, $userId);
        if ($ipAnomalies) {
            $anomalies = array_merge($anomalies, $ipAnomalies);
            $riskScore += count($ipAnomalies) * 20;
        }

        // 2. Check for device used by multiple users
        $multiUserAnomalies = $this->checkMultiUserDevice($fingerprint);
        if ($multiUserAnomalies) {
            $anomalies = array_merge($anomalies, $multiUserAnomalies);
            $riskScore += count($multiUserAnomalies) * 30;
        }

        // 3. Check for rapid device changes
        $deviceChangeAnomalies = $this->checkRapidDeviceChanges($userId);
        if ($deviceChangeAnomalies) {
            $anomalies = array_merge($anomalies, $deviceChangeAnomalies);
            $riskScore += count($deviceChangeAnomalies) * 25;
        }

        // Cap risk score at 100
        $riskScore = min(100, $riskScore);

        return new DeviceAnomalyResult(
            hasAnomalies: !empty($anomalies),
            anomalies: $anomalies,
            riskScore: $riskScore,
            recommendation: $this->getRecommendation($riskScore)
        );
    }

    /**
     * Check for IP anomalies
     */
    private function checkIpAnomalies(DeviceFingerprint $fingerprint, int $userId): array
    {
        $anomalies = [];
        $cacheKey = self::CACHE_PREFIX . "ip_history:{$userId}";
        
        // Get IP history
        $ipHistory = Cache::get($cacheKey, []);
        
        // Add current IP
        $currentIp = $fingerprint->ipHash;
        if (!empty($currentIp) && !in_array($currentIp, $ipHistory)) {
            $ipHistory[] = $currentIp;
            
            // Check for too many IPs
            if (count($ipHistory) > self::MAX_IP_CHANGES) {
                $anomalies[] = [
                    'type' => 'excessive_ip_changes',
                    'severity' => 'MEDIUM',
                    'details' => sprintf(
                        'User used %d different IPs today (threshold: %d)',
                        count($ipHistory),
                        self::MAX_IP_CHANGES
                    ),
                ];
            }
            
            // Update cache
            Cache::put($cacheKey, $ipHistory, self::CACHE_TTL);
        }

        return $anomalies;
    }

    /**
     * Check if device is used by multiple users
     */
    private function checkMultiUserDevice(DeviceFingerprint $fingerprint): array
    {
        $anomalies = [];
        
        if (empty($fingerprint->deviceHash)) {
            return $anomalies;
        }

        $cacheKey = self::CACHE_PREFIX . "device_users:{$fingerprint->deviceHash}";
        $userList = Cache::get($cacheKey, []);
        
        if ($fingerprint->userId && !in_array($fingerprint->userId, $userList)) {
            $userList[] = $fingerprint->userId;
            
            if (count($userList) > 1) {
                $anomalies[] = [
                    'type' => 'multi_user_device',
                    'severity' => 'HIGH',
                    'details' => sprintf(
                        'Device used by %d different users (possible joki)',
                        count($userList)
                    ),
                    'user_count' => count($userList),
                ];
            }
            
            Cache::put($cacheKey, $userList, self::CACHE_TTL);
        }

        return $anomalies;
    }

    /**
     * Check for rapid device changes by user
     */
    private function checkRapidDeviceChanges(int $userId): array
    {
        $anomalies = [];
        $cacheKey = self::CACHE_PREFIX . "user_devices:{$userId}";
        
        $deviceHistory = Cache::get($cacheKey, []);
        
        // If more than 3 different devices in a day, flag it
        if (count($deviceHistory) > 3) {
            $anomalies[] = [
                'type' => 'rapid_device_changes',
                'severity' => 'MEDIUM',
                'details' => sprintf(
                    'User used %d different devices today',
                    count($deviceHistory)
                ),
            ];
        }

        return $anomalies;
    }

    /**
     * Record device usage for a user
     */
    public function recordUsage(DeviceFingerprint $fingerprint, int $userId): void
    {
        // Record device for user
        $deviceKey = self::CACHE_PREFIX . "user_devices:{$userId}";
        $devices = Cache::get($deviceKey, []);
        if (!in_array($fingerprint->deviceHash, $devices)) {
            $devices[] = $fingerprint->deviceHash;
            Cache::put($deviceKey, $devices, self::CACHE_TTL);
        }

        // Record user for device
        if (!empty($fingerprint->deviceHash)) {
            $userKey = self::CACHE_PREFIX . "device_users:{$fingerprint->deviceHash}";
            $users = Cache::get($userKey, []);
            if (!in_array($userId, $users)) {
                $users[] = $userId;
                Cache::put($userKey, $users, self::CACHE_TTL);
            }
        }

        // Record IP for user
        if (!empty($fingerprint->ipHash)) {
            $ipKey = self::CACHE_PREFIX . "ip_history:{$userId}";
            $ips = Cache::get($ipKey, []);
            if (!in_array($fingerprint->ipHash, $ips)) {
                $ips[] = $fingerprint->ipHash;
                Cache::put($ipKey, $ips, self::CACHE_TTL);
            }
        }
    }

    /**
     * Get rate limit key for fingerprint
     *
     * Used for device-based rate limiting
     */
    public function getRateLimitKey(DeviceFingerprint $fingerprint): string
    {
        // Use fingerprint hash for rate limiting
        return "rate_limit:device:{$fingerprint->fingerprint}";
    }

    /**
     * Get recommendation based on risk score
     */
    private function getRecommendation(int $riskScore): string
    {
        return match (true) {
            $riskScore >= 80 => 'BLOCK - High risk of fraud, require verification',
            $riskScore >= 50 => 'CHALLENGE - Request additional verification',
            $riskScore >= 30 => 'MONITOR - Log for review but allow',
            default => 'ALLOW - Normal behavior',
        };
    }

    /**
     * Log security event with fingerprint context
     */
    public function logSecurityEvent(
        DeviceFingerprint $fingerprint,
        string $event,
        array $context = []
    ): void {
        $logData = array_merge([
            'event' => $event,
            'fingerprint' => substr($fingerprint->fingerprint, 0, 16) . '...',
            'device_hash' => $fingerprint->deviceHash ? substr($fingerprint->deviceHash, 0, 16) . '...' : null,
            'ip_hash' => $fingerprint->ipHash ? substr($fingerprint->ipHash, 0, 16) . '...' : null,
            'ua_hash' => $fingerprint->userAgentHash ? substr($fingerprint->userAgentHash, 0, 16) . '...' : null,
            'user_id' => $fingerprint->userId,
            'timestamp' => $fingerprint->timestamp->toIso8601String(),
        ], $context);

        Log::channel('security_json')->info("Device Security Event: {$event}", $logData);
    }
}
