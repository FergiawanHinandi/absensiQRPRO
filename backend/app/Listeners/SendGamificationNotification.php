<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendGamificationNotification
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if ($event instanceof \App\Events\BadgeAwarded) {
            // Logic to send notification for new badge
            // Example Payload:
            // {
            //   "title": "New Badge Earned!",
            //   "body": "Congratulations! You earned the {$event->badgeName} badge.",
            //   "data": { "type": "badge", "slug": "{$event->badgeSlug}" }
            // }
            
            \Illuminate\Support\Facades\Log::info("Notification: Badge Earned - {$event->user->name} - {$event->badgeName}");
            // TODO: Call Notification Service/FCM here
        }

        if ($event instanceof \App\Events\RewardEligibilityLost) {
            // Logic to send warning notification
            // Example Payload:
            // {
            //   "title": "Reward Eligibility Update",
            //   "body": "Alert: You have lost your reward eligibility. Reason: {$event->reason}",
            //   "data": { "type": "eligibility_lost", "action": "check_dashboard" }
            // }

            \Illuminate\Support\Facades\Log::info("Notification: Eligibility Lost - {$event->user->name} - {$event->reason}");
            // TODO: Call Notification Service/FCM here
        }
    }
}
