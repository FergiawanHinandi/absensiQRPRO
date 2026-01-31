<?php

namespace Database\Factories;

use App\Models\Attendance;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        return [
            'school_id' => 1,
            'student_id' => 1,
            'schedule_id' => 1,
            'attendance_date' => now()->toDateString(),
            'status' => $this->faker->randomElement(['present', 'late', 'absent', 'sick', 'permit']),
            'check_in_time' => now(),
            'is_manual' => false,
        ];
    }
}
