<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SecurityAlertService
 *
 * Implements threshold-based security alerting using Redis counters.
 *
 * DETECTED THREATS:
 * 1. Signature Forgery Storm (>20/5min): Possible key leakage or brute force
 * 2. Replay Attack Spike (>5/1min): Distributed replay attack attempt
 * 3. Device Spoofing (Immediate): device_mismatch checks
 */
class SecurityAlertService
{
    private const CACHE_PREFIX = 'sec_alert:';

    private const ALERT_COOLDOWN_PREFIX = 'sec_alert_sent:';

    /**
     * Security Threshold Configuration
     *
     * Format: [limit, window_seconds]
     */
    private const RULES = [
        'invalid_signature' => [20, 300], // 20 attempts per 5 mins
        'replay_attempt' => [5, 60],   // 5 attempts per 1 min
        'device_mismatch' => [1, 60],   // Immediate alert (1 per min)
    ];

    /**
     * Track a security event and trigger alert if threshold exceeded
     *
     * @param  string  $eventType  The type of event (key in RULES)
     * @param  string  $identifier  Context ID (IP, UserID, SchoolID, or 'global')
     * @param  array  $metadata  Additional context for the log
     */
    public function trackEvent(string $eventType, string $identifier = 'global', array $metadata = []): void
    {
        if (! isset(self::RULES[$eventType])) {
            Log::warning("Unknown security event type tracked: {$eventType}");

            return;
        }

        [$limit, $window] = self::RULES[$eventType];
        $key = self::CACHE_PREFIX."{$eventType}:{$identifier}";

        // 1. Increment counter atomically
        $count = Cache::increment($key);

        // 2. Set TTL on first hit
        if ($count === 1) {
            Cache::put($key, 1, $window);
        }

        // 3. Check Threshold
        if ($count > $limit) {
            $this->triggerAlert($eventType, $identifier, $count, $limit, $metadata);
        }
    }

    /**
     * Trigger alert if not on cooldown
     */
    private function triggerAlert(string $type, string $identifier, int $currentCount, int $limit, array $metadata): void
    {
        // Prevent alert flooding: Check Cooldown (e.g., alert once per 5 mins)
        // using a separate lock key
        $cooldownKey = self::ALERT_COOLDOWN_PREFIX."{$type}:{$identifier}";

        if (Cache::has($cooldownKey)) {
            return; // Already alerted recently
        }

        // Set cooldown (e.g. 5 minutes)
        Cache::put($cooldownKey, true, 300);

        // Construct Alert Payload
        $payload = [
            'event' => 'security.threshold_breach',
            'type' => $type,
            'identifier' => $identifier,
            'count' => $currentCount,
            'limit' => $limit,
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
            'server_env' => config('app.env'),
        ];

        // 4. Log as CRITICAL (Primary Alert Output)
        Log::channel('security_json')->critical(
            "SECURITY ALERT: Threshold exceeded for {$type}",
            $payload
        );

        // 5. External Notification (Optional Webhook/Email stub)
        $this->dispatchExternalAlert($payload);
    }

    /**
     * Dispatch to external channels (Slack, Email, PagerDuty)
     *
     * ARCH-02 FIX: Sebelumnya body kosong (stub). Sekarang membuat
     * Notification in-app untuk seluruh super_admin agar alert keamanan
     * terlihat di dashboard. Webhook/email eksternal tetap bisa ditambahkan
     * melalui queue job di masa depan.
     */
    private function dispatchExternalAlert(array $payload): void
    {
        try {
            $admins = \App\Models\User::where('role_type', 'super_admin')
                ->where('is_active', true)
                ->get();

            foreach ($admins as $admin) {
                \App\Models\Notification::create([
                    'user_id' => $admin->id,
                    'school_id' => null,
                    'title' => '🚨 Security Alert: '.ucwords(str_replace('_', ' ', $payload['type'] ?? 'unknown')),
                    'message' => sprintf(
                        'Threshold %s dilampaui (%s) — count %d/%d.',
                        $payload['type'] ?? '?',
                        $payload['identifier'] ?? '-',
                        $payload['count'] ?? 0,
                        $payload['limit'] ?? 0
                    ),
                    'type' => 'security',
                ]);
            }
        } catch (\Throwable $e) {
            // Jangan pernah melempar exception dari jalur alerting
            Log::channel('security_json')->warning('dispatchExternalAlert gagal', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
