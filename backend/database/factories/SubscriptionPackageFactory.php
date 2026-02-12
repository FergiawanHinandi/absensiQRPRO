<?php

namespace Database\Factories;

use App\Models\SubscriptionPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionPackageFactory extends Factory
{
    protected $model = SubscriptionPackage::class;

    public function definition(): array
    {
        return [
            'name' => 'Standard Package',
            'price' => 500000,
            'billing_cycle' => 'monthly',
            'features' => json_encode([
                'max_students' => 500,
                'max_teachers' => 50,
                'max_classes' => 20,
                'storage_gb' => 10,
                'support' => 'Email',
            ]),
            'is_popular' => false,
            'is_active' => true,
        ];
    }

    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Basic Package',
            'price' => 250000,
            'features' => json_encode([
                'max_students' => 100,
                'max_teachers' => 10,
                'max_classes' => 10,
                'storage_gb' => 5,
                'support' => 'Email',
            ]),
        ]);
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Premium Package',
            'price' => 1000000,
            'is_popular' => true,
            'features' => json_encode([
                'max_students' => -1,
                'max_teachers' => -1,
                'max_classes' => -1,
                'storage_gb' => 200,
                'support' => '24/7 Dedicated Support',
            ]),
        ]);
    }
}
