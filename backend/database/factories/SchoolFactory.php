<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company.' School',
            'npsn' => $this->faker->unique()->numerify('########'),
            'school_level' => $this->faker->randomElement(['SD', 'SMP', 'SMA', 'SMK']),
            'address' => $this->faker->address,
            'phone' => $this->faker->phoneNumber,
            'email' => $this->faker->unique()->safeEmail,
            'timezone' => 'Asia/Jakarta',
            'latitude' => $this->faker->latitude(-10, 5),
            'longitude' => $this->faker->longitude(95, 141),
            'radius_meters' => $this->faker->numberBetween(50, 200),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the school is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
