<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RewardEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reward_eligibility_not_eligible_low_streak()
    {
        // Mock school and user
        // We need to be careful not to create real records if we are not using RefreshDatabase and wiping
        // Ideally we use in-memory sqlite or transaction rollback.
        // Assuming the environment is safe for testing (dev/local).

        // Let's create a temporary user in memory-ish or just create and delete.
        // Or better, just mock the attributes if possible? No, getRewardEligibleAttribute queries DB.

        // Let's use a known user or create one.
        // Since we don't have RefreshDatabase enabled in previous test, I will create and delete manually to be safe.

        $school = School::factory()->create();
        $user = User::factory()->create([
            'school_id' => $school->id,
            'current_streak' => 10, // Low streak
        ]);

        // Active Academic Year
        AcademicYear::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'start_date' => now()->subMonths(3),
            'end_date' => now()->addMonths(3),
        ]);

        // Even with high attendance, streak is low
        $this->assertFalse($user->reward_eligible);

        // Cleanup
        $user->delete();
        $school->delete();
    }

    public function test_reward_eligibility_eligible()
    {
        $school = School::factory()->create();
        $user = User::factory()->create([
            'school_id' => $school->id,
            'current_streak' => 35, // High streak
        ]);

        $startDate = now()->subMonths(1);

        // Active Academic Year
        AcademicYear::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'start_date' => $startDate,
            'end_date' => now()->addMonths(3),
        ]);

        // Create 20 days of attendance (all present)
        for ($i = 0; $i < 20; $i++) {
            Attendance::factory()->create([
                'student_id' => $user->id,
                'school_id' => $school->id,
                'attendance_date' => $startDate->copy()->addDays($i),
                'status' => 'present',
            ]);
        }

        // 100% attendance > 95%
        $this->assertTrue($user->reward_eligible);

        // Cleanup
        Attendance::where('student_id', $user->id)->delete();
        $user->delete();
        $school->delete();
    }

    public function test_reward_eligibility_not_eligible_low_attendance()
    {
        $school = School::factory()->create();
        $user = User::factory()->create([
            'school_id' => $school->id,
            'current_streak' => 40, // High streak
        ]);

        $startDate = now()->subMonths(1);

        // Active Academic Year
        AcademicYear::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'start_date' => $startDate,
            'end_date' => now()->addMonths(3),
        ]);

        // Create 20 days: 10 present, 10 absent
        for ($i = 0; $i < 10; $i++) {
            Attendance::factory()->create([
                'student_id' => $user->id,
                'school_id' => $school->id,
                'attendance_date' => $startDate->copy()->addDays($i),
                'status' => 'present',
            ]);
        }
        for ($i = 10; $i < 20; $i++) {
            Attendance::factory()->create([
                'student_id' => $user->id,
                'school_id' => $school->id,
                'attendance_date' => $startDate->copy()->addDays($i),
                'status' => 'absent', // Counted in total_days (distinct date) but not present_days
            ]);
        }

        // 50% attendance < 95%
        $this->assertFalse($user->reward_eligible);

        // Cleanup
        Attendance::where('student_id', $user->id)->delete();
        $user->delete();
        $school->delete();
    }
}
