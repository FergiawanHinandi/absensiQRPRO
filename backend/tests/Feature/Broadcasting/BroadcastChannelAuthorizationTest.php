<?php

namespace Tests\Feature\Broadcasting;

use App\Broadcasting\ChannelAuthorization;
use App\Models\ClassModel;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Broadcast Channel Authorization Tests
 * 
 * Tests multi-tenant isolation and security for all broadcast channels.
 * 
 * Security scenarios tested:
 * - Cross-school access attempts
 * - Invalid/non-existent resource IDs
 * - Deleted/soft-deleted records
 * - Role-based access control
 * - Ownership validation
 * - Super admin bypass
 */
class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;
    protected School $schoolB;
    protected User $teacherA;
    protected User $teacherB;
    protected User $adminA;
    protected User $superAdmin;
    protected User $studentA;
    protected User $parentA;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two schools for cross-tenant testing
        $this->schoolA = School::factory()->create(['name' => 'School A']);
        $this->schoolB = School::factory()->create(['name' => 'School B']);

        // Create users for School A
        $this->teacherA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role_type' => 'teacher',
        ]);
        $this->teacherA->assignRole('teacher');

        $this->adminA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role_type' => 'admin',
        ]);
        $this->adminA->assignRole('school_admin');

        $this->studentA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role_type' => 'student',
        ]);
        $this->studentA->assignRole('student');

        $this->parentA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role_type' => 'parent',
        ]);
        $this->parentA->assignRole('parent');

        // Link parent to student
        $this->parentA->children()->attach($this->studentA->id, [
            'relationship' => 'father',
            'is_primary' => true,
        ]);

        // Create user for School B
        $this->teacherB = User::factory()->create([
            'school_id' => $this->schoolB->id,
            'role_type' => 'teacher',
        ]);
        $this->teacherB->assignRole('teacher');

        // Create super admin (no school_id)
        $this->superAdmin = User::factory()->create([
            'school_id' => null,
            'role_type' => 'super_admin',
        ]);
        $this->superAdmin->assignRole('super_admin');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_can_access_own_school_attendance_session()
    {
        $schedule = Schedule::factory()->create([
            'school_id' => $this->schoolA->id,
            'teacher_id' => $this->teacherA->id,
        ]);

        $result = ChannelAuthorization::authorizeAttendanceSession($this->teacherA, $schedule->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_cannot_access_other_school_attendance_session()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $scheduleB = Schedule::factory()->create([
            'school_id' => $this->schoolB->id,
            'teacher_id' => $this->teacherB->id,
        ]);

        $result = ChannelAuthorization::authorizeAttendanceSession($this->teacherA, $scheduleB->id);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function super_admin_can_access_any_school_attendance_session()
    {
        $schedule = Schedule::factory()->create([
            'school_id' => $this->schoolA->id,
            'teacher_id' => $this->teacherA->id,
        ]);

        $result = ChannelAuthorization::authorizeAttendanceSession($this->superAdmin, $schedule->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function authorization_fails_for_non_existent_session()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = ChannelAuthorization::authorizeAttendanceSession($this->teacherA, 99999);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function authorization_fails_for_invalid_session_id()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = ChannelAuthorization::authorizeAttendanceSession($this->teacherA, -1);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_can_access_own_channel()
    {
        $result = ChannelAuthorization::authorizeStudentChannel($this->studentA, $this->studentA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parent_can_access_child_channel()
    {
        $result = ChannelAuthorization::authorizeStudentChannel($this->parentA, $this->studentA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_can_access_student_channel_same_school()
    {
        $result = ChannelAuthorization::authorizeStudentChannel($this->teacherA, $this->studentA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_cannot_access_student_channel_different_school()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $studentB = User::factory()->create([
            'school_id' => $this->schoolB->id,
            'role_type' => 'student',
        ]);

        $result = ChannelAuthorization::authorizeStudentChannel($this->teacherA, $studentB->id);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parent_can_only_access_own_channel()
    {
        $result = ChannelAuthorization::authorizeParentChannel($this->parentA, $this->parentA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parent_cannot_access_other_parent_channel()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $parentB = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role_type' => 'parent',
        ]);
        $parentB->assignRole('parent');

        $result = ChannelAuthorization::authorizeParentChannel($this->parentA, $parentB->id);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_can_access_own_channel()
    {
        $result = ChannelAuthorization::authorizeTeacherChannel($this->teacherA, $this->teacherA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_access_teacher_channel_same_school()
    {
        $result = ChannelAuthorization::authorizeTeacherChannel($this->adminA, $this->teacherA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_access_teacher_channel_different_school()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = ChannelAuthorization::authorizeTeacherChannel($this->adminA, $this->teacherB->id);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function user_can_access_own_school_channel()
    {
        $result = ChannelAuthorization::authorizeSchoolChannel($this->teacherA, $this->schoolA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function user_cannot_access_other_school_channel()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = ChannelAuthorization::authorizeSchoolChannel($this->teacherA, $this->schoolB->id);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_access_own_school_security_channel()
    {
        $result = ChannelAuthorization::authorizeAdminSecurityChannel($this->adminA, $this->schoolA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_access_other_school_security_channel()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = ChannelAuthorization::authorizeAdminSecurityChannel($this->adminA, $this->schoolB->id);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function super_admin_can_access_any_school_security_channel()
    {
        $result = ChannelAuthorization::authorizeAdminSecurityChannel($this->superAdmin, $this->schoolA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_can_access_class_channel_same_school()
    {
        $class = ClassModel::factory()->create([
            'school_id' => $this->schoolA->id,
        ]);

        $result = ChannelAuthorization::authorizeClassChannel($this->teacherA, $class->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_cannot_access_class_channel_different_school()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $classB = ClassModel::factory()->create([
            'school_id' => $this->schoolB->id,
        ]);

        $result = ChannelAuthorization::authorizeClassChannel($this->teacherA, $classB->id);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_access_system_health_channel()
    {
        $result = ChannelAuthorization::authorizeSystemHealthChannel($this->adminA);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_cannot_access_system_health_channel()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = ChannelAuthorization::authorizeSystemHealthChannel($this->teacherA);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function user_can_access_own_user_channel()
    {
        $result = ChannelAuthorization::authorizeUserChannel($this->teacherA, $this->teacherA->id);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function user_cannot_access_other_user_channel()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = ChannelAuthorization::authorizeUserChannel($this->teacherA, $this->teacherB->id);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function authorization_logs_unauthorized_attempts()
    {
        Log::shouldReceive('channel')
            ->with('security')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->once()
            ->with('Unauthorized broadcast channel access attempt', \Mockery::on(function ($context) {
                return isset($context['user_id']) &&
                       isset($context['channel_type']) &&
                       isset($context['reason']) &&
                       $context['reason'] === 'school_mismatch';
            }));

        ChannelAuthorization::authorizeSchoolChannel($this->teacherA, $this->schoolB->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function deleted_schedule_returns_false()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $schedule = Schedule::factory()->create([
            'school_id' => $this->schoolA->id,
            'teacher_id' => $this->teacherA->id,
        ]);

        $schedule->delete(); // Soft delete

        $result = ChannelAuthorization::authorizeAttendanceSession($this->teacherA, $schedule->id);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_without_role_cannot_access_teacher_channel()
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = ChannelAuthorization::authorizeTeacherChannel($this->studentA, $this->teacherA->id);

        $this->assertFalse($result);
    }
}
