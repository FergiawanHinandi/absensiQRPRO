<?php

namespace Tests\Feature;

use App\Domain\Attendance\Commands\ChangeAttendanceStatusCommand;
use App\Domain\Attendance\Handlers\ChangeAttendanceStatusHandler;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Change Attendance Status Test
 * 
 * Tests the ChangeAttendanceStatusCommand and Handler
 */
class ChangeAttendanceStatusTest extends TestCase
{
    use RefreshDatabase;
    
    protected School $school;
    protected Classroom $classroom;
    protected Schedule $schedule;
    protected Student $student;
    protected User $principal;
    protected User $teacher;
    protected Attendance $attendance;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data
        $this->school = School::factory()->create();
        $this->classroom = Classroom::factory()->create(['school_id' => $this->school->id]);
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        
        // Create users
        $this->principal = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => 'principal',
        ]);
        
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => 'teacher',
        ]);
        
        // Create attendance record
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->classroom->id,
            'attendance_date' => today(),
            'status' => 'present',
            'check_in_time' => now(),
        ]);
    }
    
    /** @test */
    public function it_changes_attendance_status_successfully()
    {
        Event::fake();
        
        $command = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'late',
            userId: $this->principal->id,
            reason: 'Student arrived late due to traffic'
        );
        
        $handler = app(ChangeAttendanceStatusHandler::class);
        
        $this->actingAs($this->principal);
        $result = $handler->handle($command);
        
        // Assert status changed
        $this->assertEquals('late', $result->status);
        
        // Assert audit log created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->principal->id,
            'action' => 'attendance.status_changed',
            'auditable_type' => Attendance::class,
            'auditable_id' => $this->attendance->id,
        ]);
        
        // Assert event dispatched
        Event::assertDispatched(\App\Domain\Attendance\Events\AttendanceStatusChanged::class);
    }
    
    /** @test */
    public function it_validates_command_data()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new ChangeAttendanceStatusCommand(
            attendanceId: -1, // Invalid ID
            newStatus: 'late',
            userId: $this->principal->id
        );
    }
    
    /** @test */
    public function it_rejects_invalid_status()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status');
        
        new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'invalid_status',
            userId: $this->principal->id
        );
    }
    
    /** @test */
    public function it_prevents_changing_to_same_status()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already marked as');
        
        $command = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'present', // Same as current
            userId: $this->principal->id
        );
        
        $handler = app(ChangeAttendanceStatusHandler::class);
        
        $this->actingAs($this->principal);
        $handler->handle($command);
    }
    
    /** @test */
    public function it_prevents_changing_old_attendance()
    {
        // Create old attendance (8 days ago)
        $oldAttendance = Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'class_id' => $this->classroom->id,
            'attendance_date' => today()->subDays(8),
            'status' => 'present',
            'check_in_time' => now()->subDays(8),
        ]);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('older than');
        
        $command = new ChangeAttendanceStatusCommand(
            attendanceId: $oldAttendance->id,
            newStatus: 'late',
            userId: $this->principal->id
        );
        
        $handler = app(ChangeAttendanceStatusHandler::class);
        
        $this->actingAs($this->principal);
        $handler->handle($command);
    }
    
    /** @test */
    public function it_prevents_cross_school_access()
    {
        // Create another school
        $otherSchool = School::factory()->create();
        $otherUser = User::factory()->create([
            'school_id' => $otherSchool->id,
            'role' => 'principal',
        ]);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unauthorized');
        
        $command = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'late',
            userId: $otherUser->id
        );
        
        $handler = app(ChangeAttendanceStatusHandler::class);
        
        $this->actingAs($otherUser);
        $handler->handle($command);
    }
    
    /** @test */
    public function it_creates_detailed_audit_log()
    {
        $reason = 'Student had medical appointment';
        
        $command = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'excused',
            userId: $this->principal->id,
            reason: $reason
        );
        
        $handler = app(ChangeAttendanceStatusHandler::class);
        
        $this->actingAs($this->principal);
        $handler->handle($command);
        
        // Check audit log details
        $auditLog = AuditLog::where('auditable_id', $this->attendance->id)->first();
        
        $this->assertNotNull($auditLog);
        $this->assertEquals($this->principal->id, $auditLog->user_id);
        $this->assertEquals('attendance.status_changed', $auditLog->action);
        
        // Check old values
        $oldValues = json_decode($auditLog->old_values, true);
        $this->assertEquals('present', $oldValues['status']);
        
        // Check new values
        $newValues = json_decode($auditLog->new_values, true);
        $this->assertEquals('excused', $newValues['status']);
        
        // Check metadata
        $metadata = json_decode($auditLog->metadata, true);
        $this->assertEquals($this->student->id, $metadata['student_id']);
        $this->assertEquals($reason, $metadata['reason']);
    }
    
    /** @test */
    public function it_handles_multiple_status_changes()
    {
        Event::fake();
        
        $handler = app(ChangeAttendanceStatusHandler::class);
        $this->actingAs($this->principal);
        
        // Change 1: present → late
        $command1 = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'late',
            userId: $this->principal->id
        );
        $result1 = $handler->handle($command1);
        $this->assertEquals('late', $result1->status);
        
        // Change 2: late → absent
        $command2 = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'absent',
            userId: $this->principal->id
        );
        $result2 = $handler->handle($command2);
        $this->assertEquals('absent', $result2->status);
        
        // Change 3: absent → excused
        $command3 = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'excused',
            userId: $this->principal->id
        );
        $result3 = $handler->handle($command3);
        $this->assertEquals('excused', $result3->status);
        
        // Assert 3 audit logs created
        $this->assertEquals(3, AuditLog::where('auditable_id', $this->attendance->id)->count());
        
        // Assert 3 events dispatched
        Event::assertDispatched(
            \App\Domain\Attendance\Events\AttendanceStatusChanged::class,
            3
        );
    }
    
    /** @test */
    public function it_uses_database_transaction()
    {
        // Force an error after status update but before audit log
        $this->expectException(\Exception::class);
        
        // Mock AuditLog to throw exception
        $this->mock(AuditLog::class, function ($mock) {
            $mock->shouldReceive('create')->andThrow(new \Exception('Audit log failed'));
        });
        
        $command = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'late',
            userId: $this->principal->id
        );
        
        $handler = app(ChangeAttendanceStatusHandler::class);
        
        $this->actingAs($this->principal);
        
        try {
            $handler->handle($command);
        } catch (\Exception $e) {
            // Verify attendance status was NOT changed (transaction rolled back)
            $this->attendance->refresh();
            $this->assertEquals('present', $this->attendance->status);
            
            throw $e;
        }
    }
    
    /** @test */
    public function principal_can_change_any_attendance()
    {
        $command = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'late',
            userId: $this->principal->id
        );
        
        $handler = app(ChangeAttendanceStatusHandler::class);
        
        $this->actingAs($this->principal);
        $result = $handler->handle($command);
        
        $this->assertEquals('late', $result->status);
    }
    
    /** @test */
    public function teacher_can_change_attendance_in_their_school()
    {
        $command = new ChangeAttendanceStatusCommand(
            attendanceId: $this->attendance->id,
            newStatus: 'late',
            userId: $this->teacher->id
        );
        
        $handler = app(ChangeAttendanceStatusHandler::class);
        
        $this->actingAs($this->teacher);
        $result = $handler->handle($command);
        
        $this->assertEquals('late', $result->status);
    }
}
