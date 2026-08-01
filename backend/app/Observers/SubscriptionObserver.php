<?php

namespace App\Observers;

use App\Http\Middleware\CheckActiveSubscription;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * Subscription Observer
 * 
 * Automatically clears subscription cache when subscription is updated
 * to prevent serving stale data and revenue leakage.
 * 
 * CRITICAL: Ensures cache invalidation on all subscription changes
 */
class SubscriptionObserver
{
    /**
     * Handle the Subscription "updated" event.
     * 
     * Clears cache when subscription is updated to ensure
     * middleware fetches fresh data on next request.
     */
    public function updated(Subscription $subscription): void
    {
        // Clear subscription cache for this school
        CheckActiveSubscription::clearCache($subscription->school_id);
        
        Log::info('Subscription updated - cache cleared', [
            'subscription_id' => $subscription->id,
            'school_id' => $subscription->school_id,
            'is_active' => $subscription->is_active,
            'expires_at' => $subscription->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Handle the Subscription "created" event.
     * 
     * Clears cache when new subscription is created to ensure
     * immediate access for newly subscribed schools.
     */
    public function created(Subscription $subscription): void
    {
        // Clear any existing cache for this school
        CheckActiveSubscription::clearCache($subscription->school_id);
        
        Log::info('Subscription created - cache cleared', [
            'subscription_id' => $subscription->id,
            'school_id' => $subscription->school_id,
            'plan_type' => $subscription->plan_type,
            'expires_at' => $subscription->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Handle the Subscription "deleted" event.
     * 
     * Clears cache when subscription is deleted to ensure
     * immediate access denial.
     */
    public function deleted(Subscription $subscription): void
    {
        // Clear subscription cache for this school
        CheckActiveSubscription::clearCache($subscription->school_id);
        
        Log::info('Subscription deleted - cache cleared', [
            'subscription_id' => $subscription->id,
            'school_id' => $subscription->school_id,
        ]);
    }
}
