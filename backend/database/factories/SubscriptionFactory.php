<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $startsAt = now();
        $expiresAt = $startsAt->copy()->addDays(30);

        return [
            'school_id' => School::factory(),
            'plan_type' => 'premium',
            'is_active' => true,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'max_students' => 500,
            'max_teachers' => 50,
            'features' => ['reports', 'analytics', 'api_access', 'whatsapp_notifications'],
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'starts_at' => now()->subDays(5),
            'expires_at' => now()->addDays(25),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'starts_at' => now()->subDays(35),
            'expires_at' => now()->subDays(5),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'starts_at' => now()->subDays(25),
            'expires_at' => now()->addDays(5),
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_type' => 'free',
            'max_students' => 50,
            'max_teachers' => 5,
            'features' => ['basic_attendance'],
        ]);
    }

    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_type' => 'basic',
            'max_students' => 200,
            'max_teachers' => 20,
            'features' => ['reports', 'basic_analytics'],
        ]);
    }

    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_type' => 'enterprise',
            'max_students' => 10000,
            'max_teachers' => 500,
            'features' => ['reports', 'analytics', 'api_access', 'whatsapp_notifications', 'custom_branding', 'priority_support'],
        ]);
    }
}
