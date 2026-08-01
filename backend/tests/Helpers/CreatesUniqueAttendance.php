<?php

namespace Tests\Helpers;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Carbon\Carbon;

/**
 * Trait for creating unique attendance records in tests
 * 
 * Handles the unique constraint on (student_id, schedule_id, attendance_date, school_id)
 * by ensuring each created attendance has a unique combination.
 * 
 * Usage:
 * ```php
 * use Tests\Helpers\CreatesUniqueAttendance;
 * 
 * class MyTest extends TestCase {
 *     use CreatesUniqueAttendance;
 *     
 *     public function test_something() {
 *         $attendance = $this->createUniqueAttendance();
 *     }
 * }
 * ```
 */
trait CreatesUniqueAttendance
{
    /**
     * Counter for generating unique dates
     */
    protected static int $attendanceDateCounter = 0;

    /**
     * Create a unique attendance record using firstOrCreate
     * 
     * This method ensures the attendance record respects the unique constraint
     * by using firstOrCreate and generating unique dates when needed.
     * 
     * @param array $overrides Override default values
     * @return Attendance
     */
    protected function createUniqueAttendance(array $overrides = []): Attendance
    {
        // Generate unique date if not provided
        if (!isset($overrides['attendance_date'])) {
            self::$attendanceDateCounter++;
            $overrides['attendance_date'] = now()
                ->addDays(self::$attendanceDateCounter)
                ->toDateString();
        }

        // Create default values
        $defaults = [
            'student_id' => $overrides['student_id'] ?? User::factory()->create([
                'role_type' => 'student',
                'school_id' => $overrides['school_id'] ?? School::factory()->create()->id,
            ])->id,
            'schedule_id' => $overrides['schedule_id'] ?? Schedule::factory()->create([
                'school_id' => $overrides['school_id'] ?? School::factory()->create()->id,
            ])->id,
            'school_id' => $overrides['school_id'] ?? School::factory()->create()->id,
            'status' => 'present',
            'check_in_time' => '07:00:00',
            'is_manual' => false,
            'attendance_type' => 'qr_scan',
        ];

        $data = array_merge($defaults, $overrides);

        // Extract unique constraint keys
        $uniqueKeys = [
            'student_id' => $data['student_id'],
            'schedule_id' => $data['schedule_id'],
            'attendance_date' => $data['attendance_date'],
            'school_id' => $data['school_id'],
        ];

        // Extract additional attributes
        $attributes = array_diff_key($data, $uniqueKeys);

        return Attendance::firstOrCreate($uniqueKeys, $attributes);
    }

    /**
     * Create multiple unique attendance records
     * 
     * @param int $count Number of records to create
     * @param array $overrides Override default values for all records
     * @return \Illuminate\Support\Collection<Attendance>
     */
    protected function createMultipleUniqueAttendances(int $count, array $overrides = []): \Illuminate\Support\Collection
    {
        $attendances = collect();

        for ($i = 0; $i < $count; $i++) {
            // Ensure unique date for each record
            $recordOverrides = array_merge($overrides, [
                'attendance_date' => now()->addDays(self::$attendanceDateCounter + $i + 1)->toDateString(),
            ]);

            $attendances->push($this->createUniqueAttendance($recordOverrides));
        }

        self::$attendanceDateCounter += $count;

        return $attendances;
    }

    /**
     * Create attendance with specific date (for testing date-based queries)
     * 
     * @param string|Carbon $date
     * @param array $overrides
     * @return Attendance
     */
    protected function createAttendanceOnDate($date, array $overrides = []): Attendance
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        return $this->createUniqueAttendance(array_merge($overrides, [
            'attendance_date' => $dateString,
        ]));
    }

    /**
     * Create attendance for a specific student on multiple dates
     * 
     * @param User $student
     * @param array $dates Array of date strings or Carbon instances
     * @param array $overrides
     * @return \Illuminate\Support\Collection<Attendance>
     */
    protected function createAttendancesForStudent(User $student, array $dates, array $overrides = []): \Illuminate\Support\Collection
    {
        $attendances = collect();

        foreach ($dates as $date) {
            $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

            $attendances->push($this->createUniqueAttendance(array_merge($overrides, [
                'student_id' => $student->id,
                'school_id' => $student->school_id,
                'attendance_date' => $dateString,
            ])));
        }

        return $attendances;
    }

    /**
     * Create attendance that intentionally violates the unique constraint
     * 
     * Use this for testing constraint enforcement.
     * Requires Attendance::unguard() to be called first.
     * 
     * @param Attendance $existing Existing attendance to duplicate
     * @return void
     * @throws \Illuminate\Database\QueryException
     */
    protected function createDuplicateAttendance(Attendance $existing): void
    {
        // This will throw QueryException due to unique constraint
        Attendance::create([
            'student_id' => $existing->student_id,
            'schedule_id' => $existing->schedule_id,
            'attendance_date' => $existing->attendance_date,
            'school_id' => $existing->school_id,
            'status' => $existing->status,
            'check_in_time' => $existing->check_in_time,
        ]);
    }

    /**
     * Reset the date counter (useful in setUp/tearDown)
     */
    protected function resetAttendanceDateCounter(): void
    {
        self::$attendanceDateCounter = 0;
    }
}
