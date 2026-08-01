<?php

namespace App\Notifications\Channels;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (FCM) Notification Channel
 *
 * Sends push notifications to mobile devices via Firebase Cloud Messaging API v1.
 * Uses HTTP v1 API with OAuth2 authentication for secure message delivery.
 *
 * Prerequisites:
 * - FCM service account JSON configured in config/services.php
 * - Users have device_token stored in users table
 *
 * Usage in Notification class:
 *   public function via($notifiable): array
 *   {
 *       return [FcmChannel::class];
 *   }
 *
 *   public function toFcm($notifiable): array
 *   {
 *       return [
 *           'title' => 'Notification Title',
 *           'body' => 'Notification body text',
 *           'data' => ['key' => 'value'],
 *       ];
 *   }
 */
class FcmChannel
{
    /**
     * FCM API endpoint for sending messages
     */
    private const FCM_API_URL = 'https://fcm.googleapis.com/v1/projects/{project_id}/messages:send';

    /**
     * Send the notification via FCM.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        // Only send to User models with device_token
        if (! $notifiable instanceof User || ! $notifiable->device_token) {
            return;
        }

        /** @var array{title?: string, body?: string, data?: array} $message */
        $message = $notification->toFcm($notifiable);

        if (empty($message['title']) && empty($message['body'])) {
            Log::warning('FCM notification skipped - no title or body', [
                'user_id' => $notifiable->id,
                'notification' => get_class($notification),
            ]);
            return;
        }

        $this->sendPushNotification(
            $notifiable->device_token,
            $message['title'] ?? '',
            $message['body'] ?? '',
            $message['data'] ?? []
        );
    }

    /**
     * Send push notification via FCM HTTP v1 API
     */
    private function sendPushNotification(string $deviceToken, string $title, string $body, array $data = []): void
    {
        $projectId = config('services.fcm.project_id');
        $serverKey = config('services.fcm.server_key');

        if (! $projectId || ! $serverKey) {
            Log::warning('FCM not configured - missing project_id or server_key', [
                'has_project_id' => ! empty($projectId),
                'has_server_key' => ! empty($serverKey),
            ]);
            return;
        }

        $payload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => array_merge($data, ['click_action' => 'FLUTTER_NOTIFICATION_CLICK']),
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'attendance_notifications',
                        'priority' => 'high',
                        'default_sound' => true,
                    ],
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,
                        ],
                    ],
                ],
            ],
        ];

        try {
            $url = str_replace('{project_id}', $projectId, self::FCM_API_URL);

            $response = Http::withToken($serverKey)
                ->timeout(15)
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info('FCM push sent successfully', [
                    'title' => $title,
                    'device_token_prefix' => substr($deviceToken, 0, 8).'...',
                ]);
            } else {
                Log::warning('FCM push failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'device_token_prefix' => substr($deviceToken, 0, 8).'...',
                ]);

                // Handle invalid token (unregistered device)
                if ($response->status() === 404 || $response->json('error.code') === 'UNREGISTERED') {
                    Log::info('FCM token invalid, should be removed', [
                        'user_id' => null, // We don't have user here
                        'device_token_prefix' => substr($deviceToken, 0, 8).'...',
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('FCM push exception', [
                'error' => $e->getMessage(),
                'device_token_prefix' => substr($deviceToken, 0, 8).'...',
            ]);
        }
    }
}
