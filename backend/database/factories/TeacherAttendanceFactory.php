<?php

namespace Database\Factories;

use App\Models\TeacherAttendance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherAttendanceFactory extends Factory
{
    protected $model = TeacherAttendance::class;

    public function definition(): array
    {
        return [
            'teacher_id' => User::factory()->state(['role_type' => 'teacher']),
            'school_id' => null, // Will be set from teacher
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'check_in_time' => now(),
            'check_out_time' => null,
            'lat_in' => -6.2088,
            'lng_in' => 106.8456,
            'accuracy_in' => 10.0,
            'device_id_in' => $this->faker->uuid(),
            'distance_in' => 25.5,
            'is_manual' => false,
        ];
    }

    /**
     * Attendance with check-out
     */
    public function checkedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'check_out_time' => now()->addHours(8),
            'lat_out' => -6.2088,
            'lng_out' => 106.8456,
            'accuracy_out' => 10.0,
            'device_id_out' => $attributes['device_id_in'] ?? $this->faker->uuid(),
            'distance_out' => 30.0,
        ]);
    }

    /**
     * Manual attendance entry
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => true,
            'lat_in' => null,
            'lng_in' => null,
            'device_id_in' => null,
        ]);
    }

    /**
     * Late status
     */
    public function late(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'late',
        ]);
    }
}
