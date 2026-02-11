<?php

declare(strict_types=1);

namespace App\Application\Handlers\Subscription;

use App\Application\Commands\Subscription\ExtendSubscriptionCommand;
use App\Domain\Subscription\Events\SubscriptionExtended;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

class ExtendSubscriptionHandler
{
    public function handle(ExtendSubscriptionCommand $command): Subscription
    {
        $subscription = Subscription::where('school_id', $command->schoolId)
            ->where('is_active', true)
            ->firstOrFail();

        $previousExpiresAt = $subscription->expires_at->toIso8601String();

        $subscription->expires_at = $subscription->expires_at->addDays($command->extensionDays);
        $subscription->save();

        Log::channel('audit')->info('Subscription extended', [
            'school_id' => $command->schoolId,
            'subscription_id' => $subscription->id,
            'extension_days' => $command->extensionDays,
            'reason' => $command->reason,
            'previous_expires_at' => $previousExpiresAt,
            'new_expires_at' => $subscription->expires_at->toIso8601String(),
            'actor_id' => $command->actorId,
        ]);

        // Dispatch domain event
        SubscriptionExtended::dispatch(
            subscriptionId: $subscription->id,
            schoolId: $command->schoolId,
            planType: $subscription->plan_type ?? 'basic',
            extensionDays: $command->extensionDays,
            previousExpiresAt: $previousExpiresAt,
            newExpiresAt: $subscription->expires_at->toIso8601String(),
            reason: $command->reason,
            actorId: $command->actorId,
        );

        // Invalidate cached subscription data
        cache()->forget("school_sub_{$command->schoolId}");

        return $subscription;
    }
}
