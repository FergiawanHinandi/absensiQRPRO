<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceDailySummaryFactory extends Factory
{
    protected $model = \App\ReadModels\AttendanceDailySummary::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'attendance_date' => today(),
            'class_id' => null,
            'total_students' => 0,
            'total_present' => 0,
            'total_late' => 0,
            'total_absent' => 0,
            'total_excused' => 0,
            'attendance_rate' => 0,
            'last_updated_at' => now(),
        ];
    }
}
