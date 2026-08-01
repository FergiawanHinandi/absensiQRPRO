<?php

declare(strict_types=1);

namespace App\Services\Alerting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Slack Alerting Service
 *
 * Sends alerts to Slack via Incoming Webhooks.
 * Configure SLACK_SECURITY_WEBHOOK_URL in .env to enable.
 *
 * Usage:
 *   SlackAlert::critical('Redis connection failed', ['host' => '127.0.0.1']);
 *   SlackAlert::warning('High memory usage', ['percent' => 85]);
 *   SlackAlert::info('System recovered', ['service' => 'redis']);
 */
class SlackAlert
{
    private static ?string $webhookUrl = null;
    private static bool $enabled = false;

    /**
     * Initialize the alerting service from environment config
     */
    public static function init(): void
    {
        self::$webhookUrl = config('alerting.slack_webhook_url', env('SLACK_SECURITY_WEBHOOK_URL'));
        self::$enabled = (bool) config('alerting.enabled', env('ALERTS_ENABLED', true));
    }

    /**
     * Send a critical alert (red notification)
     */
    public static function critical(string $message, array $context = []): bool
    {
        return self::send('danger', $message, $context, ':rotating_light:');
    }

    /**
     * Send a warning alert (yellow notification)
     */
    public static function warning(string $message, array $context = []): bool
    {
        return self::send('warning', $message, $context, ':warning:');
    }

    /**
     * Send an info alert (blue notification)
     */
    public static function info(string $message, array $context = []): bool
    {
        return self::send('good', $message, $context, ':information_source:');
    }

    /**
     * Send a success alert (green notification)
     */
    public static function success(string $message, array $context = []): bool
    {
        return self::send('good', $message, $context, ':white_check_mark:');
    }

    /**
     * Core send method
     */
    private static function send(string $color, string $message, array $context, string $emoji): bool
    {
        self::init();

        if (!self::$enabled || empty(self::$webhookUrl)) {
            Log::debug('Slack alert skipped (disabled or no webhook)', [
                'message' => $message,
                'color' => $color,
            ]);
            return false;
        }

        try {
            $appEnv = config('app.env', 'unknown');
            $appName = config('app.name', 'AbsensiQR');
            $hostname = gethostname() ?: 'unknown';

            $fields = [
                [
                    'title' => 'Environment',
                    'value' => $appEnv,
                    'short' => true,
                ],
                [
                    'title' => 'Server',
                    'value' => $hostname,
                    'short' => true,
                ],
            ];

            if (!empty($context)) {
                $contextStr = collect($context)
                    ->map(fn ($v, $k) => "*{$k}*: " . (is_array($v) ? json_encode($v) : (string) $v))
                    ->implode("\n");

                $fields[] = [
                    'title' => 'Context',
                    'value' => $contextStr,
                    'short' => false,
                ];
            }

            $payload = [
                'attachments' => [[
                    'color' => $color,
                    'title' => "{$emoji} {$appName} Alert",
                    'text' => $message,
                    'fields' => $fields,
                    'footer' => 'AbsensiQR Alerting System',
                    'ts' => now()->timestamp,
                ]],
            ];

            $response = Http::timeout(5)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(self::$webhookUrl, $payload);

            if ($response->successful()) {
                Log::info('Slack alert sent', ['message' => $message, 'color' => $color]);
                return true;
            }

            Log::warning('Slack alert failed', [
                'status' => $response->status(),
                'message' => $message,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('Slack alert exception', [
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
