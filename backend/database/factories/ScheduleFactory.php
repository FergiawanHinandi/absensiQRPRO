<?php

namespace Database\Factories;

use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    public function definition(): array
    {
        return [
            'school_id' => \App\Models\School::factory(),
            'class_id' => \App\Models\ClassModel::factory(),
            'academic_year_id' => \App\Models\AcademicYear::factory(),
            'subject_id' => \App\Models\Subject::factory(),
            'teacher_id' => \App\Models\User::factory()->state(['role_type' => 'teacher']),
            'day_of_week' => $this->faker->numberBetween(1, 7),
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'room' => $this->faker->optional()->word,
        ];
    }
}
