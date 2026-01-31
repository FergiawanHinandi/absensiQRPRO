<?php

namespace Database\Factories;

use App\Models\TeacherDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherDeviceFactory extends Factory
{
    protected $model = TeacherDevice::class;

    public function definition(): array
    {
        return [
            'teacher_id' => User::factory()->state(['role_type' => 'teacher']),
            'school_id' => null, // Will be set from teacher
            'device_id' => $this->faker->uuid(),
            'device_name' => $this->faker->randomElement(['iPhone 15', 'Samsung Galaxy S24', 'Xiaomi 14']),
            'platform' => $this->faker->randomElement(['ios', 'android']),
            'device_model' => $this->faker->word(),
            'is_approved' => true,
            'approved_by' => null,
            'approved_at' => now(),
            'last_used_at' => null,
        ];
    }

    /**
     * Device is pending approval
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /**
     * Device is approved
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }
}
