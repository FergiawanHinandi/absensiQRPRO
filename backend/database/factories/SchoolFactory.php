<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\EloquentFactories\Factory<\App\Models\School>
 */
class SchoolFactory extends Factory
{
    protected $model = \App\Models\School::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' School',
            'npsn' => (string) $this->faker->unique()->numberBetween(10000000, 99999999),
            'school_level' => $this->faker->randomElement(['SD', 'SMP', 'SMA', 'SMK']),
            'address' => $this->faker->address(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),
            'timezone' => 'Asia/Jakarta',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 100,
            'is_active' => true,
            'package_type' => 'basic',
            'max_students' => 500,
            'max_teachers' => 50,
            'max_classes' => 20,
        ];
    }
}
