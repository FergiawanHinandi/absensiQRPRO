<?php

namespace App\Listeners;

use App\Events\HighErrorRateDetected;
use App\Events\SlowResponseDetected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Listener that forwards observability alerts to external monitoring systems
 * 
 * Configure webhook URLs in config/services.php:
 * 
 * 'monitoring' => [
 *     'slack_webhook' => env('MONITORING_SLACK_WEBHOOK'),
 *     'pagerduty_key' => env('MONITORING_PAGERDUTY_KEY'),
 * ]
 */
class SendMonitoringAlert implements ShouldQueue
{
    /**
     * Handle the event (generic handler for queued events)
     */
    public function handle($event): void
    {
        if ($event instanceof SlowResponseDetected) {
            $this->handleSlowResponse($event);
        } elseif ($event instanceof HighErrorRateDetected) {
            $this->handleHighErrorRate($event);
        }
    }

    /**
     * Handle slow response events
     */
    public function handleSlowResponse(SlowResponseDetected $event): void
    {
        $payload = $event->toAlertPayload();
        $this->sendAlerts($payload);
    }

    /**
     * Handle high error rate events
     */
    public function handleHighErrorRate(HighErrorRateDetected $event): void
    {
        $payload = $event->toAlertPayload();
        $this->sendAlerts($payload);
    }

    /**
     * Send alerts to configured channels
     */
    private function sendAlerts(array $payload): void
    {
        // Slack webhook
        $slackWebhook = config('services.monitoring.slack_webhook');
        if ($slackWebhook) {
            $this->sendSlackAlert($slackWebhook, $payload);
        }

        // PagerDuty
        $pagerdutyKey = config('services.monitoring.pagerduty_key');
        if ($pagerdutyKey) {
            $this->sendPagerDutyAlert($pagerdutyKey, $payload);
        }

        // Log for audit
        Log::channel('system')->info('Monitoring alert sent', [
            'alert_type' => $payload['alert_type'],
            'severity' => $payload['severity'],
            'channels' => array_filter([
                'slack' => !empty($slackWebhook),
                'pagerduty' => !empty($pagerdutyKey),
            ]),
        ]);
    }

    /**
     * Send Slack alert
     */
    private function sendSlackAlert(string $webhook, array $payload): void
    {
        try {
            $emoji = $payload['severity'] === 'critical' ? '🚨' : '⚠️';
            $color = $payload['severity'] === 'critical' ? 'danger' : 'warning';

            Http::post($webhook, [
                'attachments' => [
                    [
                        'color' => $color,
                        'title' => $emoji . ' ' . ucfirst($payload['alert_type']),
                        'text' => $payload['message'],
                        'fields' => [
                            [
                                'title' => 'Severity',
                                'value' => ucfirst($payload['severity']),
                                'short' => true,
                            ],
                            [
                                'title' => 'Environment',
                                'value' => config('app.env'),
                                'short' => true,
                            ],
                            [
                                'title' => 'Timestamp',
                                'value' => $payload['timestamp'],
                                'short' => true,
                            ],
                        ],
                        'footer' => config('app.name') . ' Monitoring',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::channel('system')->error('Failed to send Slack alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send PagerDuty alert
     */
    private function sendPagerDutyAlert(string $routingKey, array $payload): void
    {
        try {
            $severity = $payload['severity'] === 'critical' ? 'critical' : 'warning';

            Http::post('https://events.pagerduty.com/v2/enqueue', [
                'routing_key' => $routingKey,
                'event_action' => 'trigger',
                'dedup_key' => $payload['alert_type'] . '-' . substr(md5($payload['timestamp']), 0, 8),
                'payload' => [
                    'summary' => $payload['message'],
                    'severity' => $severity,
                    'source' => config('app.url'),
                    'component' => 'api',
                    'group' => 'performance',
                    'class' => $payload['alert_type'],
                    'custom_details' => $payload,
                ],
            ]);
        } catch (\Exception $e) {
            Log::channel('system')->error('Failed to send PagerDuty alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Subscribe to events
     */
    public function subscribe($events): array
    {
        return [
            SlowResponseDetected::class => 'handleSlowResponse',
            HighErrorRateDetected::class => 'handleHighErrorRate',
        ];
    }
}
