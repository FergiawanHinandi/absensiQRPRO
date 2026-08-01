<?php

namespace App\Services\DR;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

/**
 * Alert Manager for Disaster Recovery Events
 *
 * Multi-channel alerting: email, Slack, log.
 * Includes escalation logic and cooldown to prevent alert storms.
 *
 * Spec: disaster-recovery-audit-improvements / tasks.md Task 6.1
 */
class AlertManager
{
    private const COOLDOWN_PREFIX = 'dr_alert_cooldown:';

    /**
     * Alert severity levels.
     */
    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_ERROR    = 'error';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Send a DR alert through all configured channels.
     */
    public function alert(
        string $severity,
        string $eventType,
        string $message,
        array  $context = []
    ): void {
        // Check cooldown to prevent alert storm
        if ($this->isInCooldown($eventType, $severity)) {
            Log::debug("AlertManager: Suppressed (cooldown) — {$eventType}");
            return;
        }

        $alert = [
            'severity'   => $severity,
            'event_type' => $eventType,
            'message'    => $message,
            'context'    => $context,
            'timestamp'  => now()->toIso8601String(),
            'env'        => app()->environment(),
        ];

        // Always log
        $this->logAlert($alert);

        // Send via configured channels
        $channels = config('disaster_recovery.monitoring.alert_channels', ['log']);

        foreach ($channels as $channel) {
            match ($channel) {
                'mail'  => $this->sendEmail($alert),
                'slack' => $this->sendSlack($alert),
                'log'   => null, // Already done above
                default => null,
            };
        }

        // Store alert for tracking
        $this->storeAlert($alert);

        // Set cooldown
        $this->setCooldown($eventType, $severity);
    }

    /**
     * Convenience methods for each severity.
     */
    public function info(string $eventType, string $message, array $context = []): void
    {
        $this->alert(self::SEVERITY_INFO, $eventType, $message, $context);
    }

    public function warning(string $eventType, string $message, array $context = []): void
    {
        $this->alert(self::SEVERITY_WARNING, $eventType, $message, $context);
    }

    public function error(string $eventType, string $message, array $context = []): void
    {
        $this->alert(self::SEVERITY_ERROR, $eventType, $message, $context);
    }

    public function critical(string $eventType, string $message, array $context = []): void
    {
        $this->alert(self::SEVERITY_CRITICAL, $eventType, $message, $context);
    }

    /**
     * Log to DR audit log table.
     */
    private function storeAlert(array $alert): void
    {
        try {
            DB::table('dr_audit_log')->insert([
                'event_type'  => $alert['event_type'],
                'severity'    => $alert['severity'],
                'actor_type'  => 'system',
                'details'     => json_encode($alert),
                'occurred_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('AlertManager: Failed to store alert', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Log the alert to Laravel log.
     */
    private function logAlert(array $alert): void
    {
        $level = match ($alert['severity']) {
            self::SEVERITY_CRITICAL => 'critical',
            self::SEVERITY_ERROR    => 'error',
            self::SEVERITY_WARNING  => 'warning',
            default                 => 'info',
        };

        Log::channel('dr_audit')->{$level}(
            "[DR-ALERT] {$alert['event_type']}: {$alert['message']}",
            $alert['context']
        );
    }

    /**
     * Send email alert.
     */
    private function sendEmail(array $alert): void
    {
        $emails = config('disaster_recovery.monitoring.notify_emails', []);
        if (empty($emails)) {
            return;
        }

        try {
            $subject = "[{$alert['severity']}] DR Alert: {$alert['event_type']}";
            $body    = "Disaster Recovery Alert\n\n"
                     . "Severity: {$alert['severity']}\n"
                     . "Event: {$alert['event_type']}\n"
                     . "Message: {$alert['message']}\n"
                     . "Time: {$alert['timestamp']}\n"
                     . "Environment: {$alert['env']}\n\n"
                     . "Context:\n" . json_encode($alert['context'], JSON_PRETTY_PRINT);

            foreach ($emails as $email) {
                if (filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                    Mail::raw($body, fn ($m) => $m->to($email)->subject($subject));
                }
            }
        } catch (\Exception $e) {
            Log::error('AlertManager: Email send failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send Slack alert.
     */
    private function sendSlack(array $alert): void
    {
        $webhook = config('disaster_recovery.monitoring.slack_webhook');
        if (!$webhook) {
            return;
        }

        $emoji = match ($alert['severity']) {
            self::SEVERITY_CRITICAL => ':rotating_light:',
            self::SEVERITY_ERROR    => ':x:',
            self::SEVERITY_WARNING  => ':warning:',
            default                 => ':information_source:',
        };

        try {
            Http::timeout(10)->post($webhook, [
                'text' => "{$emoji} *DR Alert [{$alert['severity']}]* — `{$alert['event_type']}`",
                'attachments' => [[
                    'color'  => match ($alert['severity']) {
                        self::SEVERITY_CRITICAL => 'danger',
                        self::SEVERITY_ERROR    => 'danger',
                        self::SEVERITY_WARNING  => 'warning',
                        default                 => 'good',
                    },
                    'text'   => $alert['message'],
                    'footer' => "AbsensiQRPro DR | {$alert['timestamp']}",
                    'fields' => array_map(fn ($k, $v) => [
                        'title' => $k,
                        'value' => is_scalar($v) ? (string) $v : json_encode($v),
                        'short' => true,
                    ], array_keys($alert['context']), $alert['context']),
                ]],
            ]);
        } catch (\Exception $e) {
            Log::error('AlertManager: Slack send failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if this event type is in cooldown.
     */
    private function isInCooldown(string $eventType, string $severity): bool
    {
        // Critical alerts are never suppressed
        if ($severity === self::SEVERITY_CRITICAL) {
            return false;
        }

        $key = self::COOLDOWN_PREFIX . md5($eventType . $severity);
        return cache()->has($key);
    }

    /**
     * Set cooldown for alert type.
     * Cooldown = 5 minutes for error, 15 minutes for warnings.
     */
    private function setCooldown(string $eventType, string $severity): void
    {
        $ttl = match ($severity) {
            self::SEVERITY_ERROR   => 300,   // 5 min
            self::SEVERITY_WARNING => 900,   // 15 min
            default                => 60,    // 1 min
        };

        $key = self::COOLDOWN_PREFIX . md5($eventType . $severity);
        cache()->put($key, true, $ttl);
    }

    /**
     * Send alert (alias for alert method for backward compatibility).
     */
    public function sendAlert(array $alertData): void
    {
        $this->alert(
            $alertData['severity'] ?? self::SEVERITY_INFO,
            $alertData['type'] ?? 'unknown',
            $alertData['message'] ?? '',
            $alertData['context'] ?? []
        );
    }

    /**
     * Get pending alerts from the audit log.
     * Returns recent alerts from the last hour.
     */
    public function getPendingAlerts(): array
    {
        try {
            $alerts = DB::table('dr_audit_log')
                ->where('event_type', 'LIKE', '%alert%')
                ->where('occurred_at', '>=', now()->subHour())
                ->orderBy('occurred_at', 'desc')
                ->limit(100)
                ->get()
                ->map(function ($row) {
                    $details = json_decode($row->details, true) ?? [];
                    return [
                        'type' => $row->event_type,
                        'severity' => $row->severity,
                        'message' => $details['message'] ?? '',
                        'context' => $details['context'] ?? [],
                        'timestamp' => $row->occurred_at,
                    ];
                })
                ->toArray();

            return $alerts;
        } catch (\Exception $e) {
            Log::error('AlertManager: Failed to get pending alerts', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
