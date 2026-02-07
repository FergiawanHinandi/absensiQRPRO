<?php

namespace App\Jobs;

use App\Models\SecurityAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Send Security Alert Notification
 *
 * Dispatches security alert notifications to:
 * - Telegram Bot API
 * - Slack Webhook
 *
 * Only processes HIGH and CRITICAL severity alerts.
 */
class SendSecurityAlertNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 30;

    public function __construct(
        public SecurityAlert $alert
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Skip if already notified
        if ($this->alert->notification_sent) {
            return;
        }

        $message = $this->formatMessage();

        $telegramSent = false;
        $slackSent = false;

        // Try Telegram
        if ($this->shouldSendTelegram()) {
            $telegramSent = $this->sendTelegram($message);
        }

        // Try Slack
        if ($this->shouldSendSlack()) {
            $slackSent = $this->sendSlack($message);
        }

        // Mark as notified if at least one channel succeeded
        if ($telegramSent || $slackSent) {
            $this->alert->markNotificationSent();

            Log::channel('security')->info('Security alert notification sent', [
                'alert_id' => $this->alert->id,
                'telegram' => $telegramSent,
                'slack' => $slackSent,
            ]);
        } else {
            Log::channel('security')->error('Failed to send security alert notification', [
                'alert_id' => $this->alert->id,
            ]);
        }
    }

    /**
     * Format the notification message
     */
    private function formatMessage(): array
    {
        $alert = $this->alert;
        $school = $alert->school;
        $user = $alert->relatedUser;

        $emoji = $alert->getSeverityEmoji();
        $severityLabel = strtoupper($alert->severity);
        $eventTitle = $alert->getEventTitle();

        // Plain text for Telegram
        $text = "{$emoji} SECURITY ALERT ({$severityLabel})\n\n";
        $text .= '📍 School: '.($school?->name ?? 'N/A')."\n";
        $text .= '👤 User: '.($user?->name ?? 'N/A')."\n";
        $text .= "⚠️ Event: {$eventTitle}\n";
        $text .= "📝 Details: {$alert->description}\n";
        $text .= '🕐 Time: '.$alert->created_at->format('H:i d M Y')."\n";

        if ($alert->ip_address) {
            $text .= "🌐 IP: {$alert->ip_address}\n";
        }

        // Slack blocks format
        $slackBlocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => "{$emoji} Security Alert ({$severityLabel})",
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*School:*\n".($school?->name ?? 'N/A'),
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*User:*\n".($user?->name ?? 'N/A'),
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Event:*\n{$eventTitle}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Time:*\n".$alert->created_at->format('H:i d M Y'),
                    ],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Details:*\n{$alert->description}",
                ],
            ],
        ];

        if ($alert->ip_address) {
            $slackBlocks[] = [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "🌐 IP: {$alert->ip_address}",
                    ],
                ],
            ];
        }

        return [
            'text' => $text,
            'slack_blocks' => $slackBlocks,
        ];
    }

    /**
     * Check if Telegram is configured
     */
    private function shouldSendTelegram(): bool
    {
        return ! empty(config('services.telegram.bot_token'))
            && ! empty(config('services.telegram.security_chat_id'));
    }

    /**
     * Check if Slack is configured
     */
    private function shouldSendSlack(): bool
    {
        return ! empty(config('services.slack.security_webhook_url'));
    }

    /**
     * Send notification via Telegram
     */
    private function sendTelegram(array $message): bool
    {
        try {
            $botToken = config('services.telegram.bot_token');
            $chatId = config('services.telegram.security_chat_id');

            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message['text'],
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::channel('security')->error('Telegram notification failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::channel('security')->error('Telegram notification exception', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send notification via Slack
     */
    private function sendSlack(array $message): bool
    {
        try {
            $webhookUrl = config('services.slack.security_webhook_url');

            $response = Http::timeout(10)
                ->post($webhookUrl, [
                    'blocks' => $message['slack_blocks'],
                    'text' => $message['text'], // Fallback
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::channel('security')->error('Slack notification failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::channel('security')->error('Slack notification exception', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('security')->error('SendSecurityAlertNotification job failed', [
            'alert_id' => $this->alert->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
