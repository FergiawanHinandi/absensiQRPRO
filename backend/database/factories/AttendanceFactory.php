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
            // 'status' removed - use state machine methods instead
            'check_in_time' => now(),
            'is_manual' => false,
            'request_id' => $this->faker->uuid(),
        ];
    }
    
    /**
     * Create attendance with checked-in state
     */
    public function checkedIn(): static
    {
        return $this->afterCreating(function (\App\Models\Attendance $attendance) {
            $recordedBy = \App\Models\User::find(1) ?? \App\Models\User::factory()->create();
            $attendance->checkIn(
                recordedBy: $recordedBy,
                latitude: -6.2088,
                longitude: 106.8456,
                deviceId: 'factory-device'
            );
        });
    }
    
    /**
     * Create attendance with checked-out state
     */
    public function checkedOut(): static
    {
        return $this->afterCreating(function (\App\Models\Attendance $attendance) {
            $recordedBy = \App\Models\User::find(1) ?? \App\Models\User::factory()->create();
            $attendance->checkIn(
                recordedBy: $recordedBy,
                latitude: -6.2088,
                longitude: 106.8456,
                deviceId: 'factory-device'
            );
            $attendance->checkOut(
                recordedBy: $recordedBy,
                latitude: -6.2088,
                longitude: 106.8456,
                deviceId: 'factory-device'
            );
        });
    }
    
    /**
     * Create attendance with pending approval state
     */
    public function pendingApproval(): static
    {
        return $this->afterCreating(function (\App\Models\Attendance $attendance) {
            $recordedBy = \App\Models\User::find(1) ?? \App\Models\User::factory()->create();
            $attendance->checkIn(
                recordedBy: $recordedBy,
                latitude: -6.2088,
                longitude: 106.8456,
                deviceId: 'factory-device'
            );
            $attendance->checkOut(
                recordedBy: $recordedBy,
                latitude: -6.2088,
                longitude: 106.8456,
                deviceId: 'factory-device'
            );
            $attendance->requestCorrection(
                requestedBy: $recordedBy,
                reason: 'Factory test correction request'
            );
        });
    }
    
    /**
     * Create attendance with approved state
     */
    public function approved(): static
    {
        return $this->afterCreating(function (\App\Models\Attendance $attendance) {
            $recordedBy = \App\Models\User::find(1) ?? \App\Models\User::factory()->create();
            $approver = \App\Models\User::find(2) ?? \App\Models\User::factory()->create();
            
            $attendance->checkIn(
                recordedBy: $recordedBy,
                latitude: -6.2088,
                longitude: 106.8456,
                deviceId: 'factory-device'
            );
            $attendance->checkOut(
                recordedBy: $recordedBy,
                latitude: -6.2088,
                longitude: 106.8456,
                deviceId: 'factory-device'
            );
            $attendance->requestCorrection(
                requestedBy: $recordedBy,
                reason: 'Factory test correction request'
            );
            $attendance->approve(
                approver: $approver,
                notes: 'Factory test approval'
            );
        });
    }
    
    /**
     * Create attendance with rejected state
     */
    public function rejected(): static
    {
        return $this->afterCreating(function (\App\Models\Attendance $attendance) {
            $recordedBy = \App\Models\User::find(1) ?? \App\Models\User::factory()->create();
            $rejector = \App\Models\User::find(2) ?? \App\Models\User::factory()->create();
            
            $attendance->checkIn(
                recordedBy: $recordedBy,
                latitude: -6.2088,
                longitude: 106.8456,
                deviceId: 'factory-device'
            );
            $attendance->checkOut(
                recordedBy: $recordedBy,
                latitude: -6.2088,
                longitude: 106.8456,
                deviceId: 'factory-device'
            );
            $attendance->requestCorrection(
                requestedBy: $recordedBy,
                reason: 'Factory test correction request'
            );
            $attendance->reject(
                rejector: $rejector,
                reason: 'Factory test rejection'
            );
        });
    }
    
    /**
     * TESTING ONLY: Set state directly (bypasses state machine)
     * This should ONLY be used in tests that specifically test state machine enforcement
     * 
     * @param \App\Enums\AttendanceState|string $state
     */
    public function inState($state): static
    {
        return $this->afterCreating(function (\App\Models\Attendance $attendance) use ($state) {
            // Use internal method to bypass state machine for testing
            $stateEnum = $state instanceof \App\Enums\AttendanceState ? $state : \App\Enums\AttendanceState::from($state);
            $attendance->setStateInternal($stateEnum);
            $attendance->syncLegacyStatus();
            $attendance->save();
        });
    }
}
