<?php

namespace App\Listeners;

class SendGamificationNotification
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if ($event instanceof \App\Events\BadgeAwarded) {
            \Illuminate\Support\Facades\Log::info("Notification: Badge Earned - {$event->user->name} - {$event->badgeName}");
            
            $fcmToken = $event->user->fcm_token ?? null;
            if ($fcmToken) {
                try {
                    \Illuminate\Support\Facades\Http::withHeaders([
                        'Authorization' => 'key=' . env('FCM_SERVER_KEY'),
                        'Content-Type' => 'application/json',
                    ])->post('https://fcm.googleapis.com/fcm/send', [
                        'to' => $fcmToken,
                        'notification' => [
                            'title' => 'New Badge Earned!',
                            'body' => "Congratulations! You earned the {$event->badgeName} badge.",
                        ],
                        'data' => [
                            'type' => 'badge',
                            'slug' => $event->badgeSlug ?? '',
                        ],
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("FCM Send Failed: " . $e->getMessage());
                }
            }
        }

        if ($event instanceof \App\Events\RewardEligibilityLost) {
            \Illuminate\Support\Facades\Log::info("Notification: Eligibility Lost - {$event->user->name} - {$event->reason}");
            
            $fcmToken = $event->user->fcm_token ?? null;
            if ($fcmToken) {
                try {
                    \Illuminate\Support\Facades\Http::withHeaders([
                        'Authorization' => 'key=' . env('FCM_SERVER_KEY'),
                        'Content-Type' => 'application/json',
                    ])->post('https://fcm.googleapis.com/fcm/send', [
                        'to' => $fcmToken,
                        'notification' => [
                            'title' => 'Reward Eligibility Update',
                            'body' => "Alert: You have lost your reward eligibility. Reason: {$event->reason}",
                        ],
                        'data' => [
                            'type' => 'eligibility_lost',
                            'action' => 'check_dashboard',
                        ],
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("FCM Send Failed: " . $e->getMessage());
                }
            }
        }
    }
}
