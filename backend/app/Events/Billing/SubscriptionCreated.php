<?php

namespace App\Events\Billing;

use App\Events\DomainEvent;

class SubscriptionCreated extends DomainEvent
{
    protected function getEventType(): string
    {
        return 'billing.subscription.created.v1';
    }

    protected function getAggregateType(): string
    {
        return 'Subscription';
    }

    public static function fromSubscription($subscription): self
    {
        return new self([
            'aggregate_id' => $subscription->id,
            'school_id' => $subscription->school_id,
            'plan_id' => $subscription->plan_id,
            'billing_cycle' => $subscription->billing_cycle,
            'price' => $subscription->price,
            'currency' => $subscription->currency,
            'start_date' => $subscription->start_date?->toDateString(),
            'end_date' => $subscription->end_date?->toDateString(),
            'trial_period_days' => $subscription->trial_period_days,
            'status' => $subscription->status,
        ]);
    }
}
