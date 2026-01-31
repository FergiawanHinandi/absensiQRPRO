<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AcademicYear::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = $this->faker->numberBetween(2020, 2025);
        $endYear = $startYear + 1;

        return [
            'school_id' => School::factory(),
            'name' => "{$startYear}/{$endYear}",
            'start_date' => "{$startYear}-07-01",
            'end_date' => "{$endYear}-06-30",
            'is_active' => $this->faker->boolean(30),
            'semester' => $this->faker->randomElement(['1', '2']),
        ];
    }

    /**
     * Indicate that the academic year is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the academic year is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
