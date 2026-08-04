<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    public function definition(): array
    {
        return [
            'school_id' => \App\Models\School::factory(),
            'name' => $this->faker->word.' '.$this->faker->word,
            'code' => strtoupper($this->faker->bothify('???###')),
            'school_level' => $this->faker->randomElement(['SD', 'SMP', 'SMA', 'SMK']),
            'grade_level' => $this->faker->numberBetween(1, 12),
        ];
    }
}
