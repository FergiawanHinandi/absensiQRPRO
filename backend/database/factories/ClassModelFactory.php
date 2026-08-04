<?php

namespace Database\Factories;

use App\Models\ClassModel;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClassModelFactory extends Factory
{
    protected $model = ClassModel::class;

    public function definition(): array
    {
        return [
            'school_id' => \App\Models\School::factory(),
            'academic_year_id' => \App\Models\AcademicYear::factory(),
            'name' => $this->faker->regexify('[0-9]{2} [A-Z]{3} [1-3]'),
            'grade_level' => $this->faker->numberBetween(1, 12),
            'classroom' => $this->faker->word,
            'is_active' => true,
        ];
    }
}
