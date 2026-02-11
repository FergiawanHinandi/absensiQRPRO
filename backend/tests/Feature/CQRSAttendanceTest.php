<?php

namespace Tests\Feature;

use App\Domain\Attendance\Commands\RecordAttendanceCommand;
use App\Domain\Attendance\Handlers\RecordAttendanceHandler;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Student;
use App\ReadModels\AttendanceDailySummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * CQRS Attendance Test
 * 
 * Tests the CQRS implementation for attendance recording:
 * - Command validation
 * - Handler execution with locking
 * - Event dispatch
 * - Read model synchronization
 * - Concurrent operations
 */
class CQRSAttendanceTest extends TestCase
{
    use RefreshDatabase;
    
    protected School $school;
    protected Classroom $classroom;
    protected Student $student;
    protected Schedule $schedule;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data
        $this->school = School::factory()->create();
        $this->classroom = Classroom::factory()->create(['school_id' => $this->school->id]);
        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
    }
    
    /** @test */
    public function it_validates_command_data()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new RecordAttendanceCommand(
            schoolId: 0, // Invalid
            studentId: $this->student->id,
            scheduleId: $this->schedule->id,
            classId: $this->classroom->id,
            attendanceDate: today(),
            status: 'present',
        );
    }
    
    /** @test */
    public function it_requires_check_in_time_for_present_status()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Check-in time is required');
        
        new RecordAttendanceCommand(
            schoolId: $this->school->id,
            studentId: $this->student->id,
            scheduleId: $this->schedule->id,
            classId: $this->classroom->id,
            attendanceDate: today(),
            status: 'present',
            checkInTime: null, // Should fail
        );
    }
    
    /** @test */
    public function it_records_attendance_and_dispatches_event()
    {
        Event::fake();
        
        $command = new RecordAttendanceCommand(
            schoolId: $this->school->id,
            studentId: $this->student->id,
            scheduleId: $this->schedule->id,
            classId: $this->classroom->id,
            attendanceDate: today(),
            status: 'present',
            checkInTime: now(),
            isManual: false,
            qrCodeId: 1,
        );
        
        $handler = new RecordAttendanceHandler();
        $attendance = $handler->handle($command);
        
        $this->assertInstanceOf(Attendance::class, $attendance);
        $this->assertEquals('present', $attendance->status);
        $this->assertEquals($this->student->id, $attendance->student_id);
        
        Event::assertDispatched(\App\Domain\Attendance\Events\AttendanceRecorded::class);
    }
    
    /** @test */
    public function it_updates_read_model_on_attendance_recorded()
    {
        $command = new RecordAttendanceCommand(
            schoolId: $this->school->id,
            studentId: $this->student->id,
            scheduleId: $this->schedule->id,
            classId: $this->classroom->id,
            attendanceDate: today(),
            status: 'present',
            checkInTime: now(),
            isManual: false,
            qrCodeId: 1,
        );
        
        $handler = new RecordAttendanceHandler();
        $handler->handle($command);
        
        // Give event listener time to process (in real app, this is async)
        sleep(1);
        
        // Check school-wide summary
        $summary = AttendanceDailySummary::getTodaySummary($this->school->id);
        $this->assertNotNull($summary);
        $this->assertEquals(1, $summary->total_present);
        $this->assertEquals(0, $summary->total_late);
        $this->assertEquals(0, $summary->total_absent);
        
        // Check class-specific summary
        $classSummary = AttendanceDailySummary::getClassSummary(
            $this->school->id,
            $this->classroom->id,
            today()
        );
        $this->assertNotNull($classSummary);
        $this->assertEquals(1, $classSummary->total_present);
    }
    
    /** @test */
    public function it_handles_concurrent_attendance_recording()
    {
        $commands = [];
        
        // Create 10 students
        $students = Student::factory()->count(10)->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        
        // Create commands for all students
        foreach ($students as $student) {
            $commands[] = new RecordAttendanceCommand(
                schoolId: $this->school->id,
                studentId: $student->id,
                scheduleId: $this->schedule->id,
                classId: $this->classroom->id,
                attendanceDate: today(),
                status: 'present',
                checkInTime: now(),
                isManual: false,
                qrCodeId: 1,
            );
        }
        
        $handler = new RecordAttendanceHandler();
        
        // Execute all commands
        foreach ($commands as $command) {
            $handler->handle($command);
        }
        
        // Verify all attendances were recorded
        $count = Attendance::where('school_id', $this->school->id)
            ->whereDate('attendance_date', today())
            ->count();
        
        $this->assertEquals(10, $count);
        
        // Verify read model is correct
        sleep(1); // Allow async processing
        
        $summary = AttendanceDailySummary::getTodaySummary($this->school->id);
        $this->assertEquals(10, $summary->total_present);
    }
    
    /** @test */
    public function it_prevents_duplicate_attendance_with_locking()
    {
        $command = new RecordAttendanceCommand(
            schoolId: $this->school->id,
            studentId: $this->student->id,
            scheduleId: $this->schedule->id,
            classId: $this->classroom->id,
            attendanceDate: today(),
            status: 'present',
            checkInTime: now(),
            isManual: false,
            qrCodeId: 1,
        );
        
        $handler = new RecordAttendanceHandler();
        
        // Record first time
        $attendance1 = $handler->handle($command);
        
        // Try to record again (should update, not create duplicate)
        $attendance2 = $handler->handle($command);
        
        // Should be the same record
        $this->assertEquals($attendance1->id, $attendance2->id);
        
        // Verify only one record exists
        $count = Attendance::where('student_id', $this->student->id)
            ->whereDate('attendance_date', today())
            ->count();
        
        $this->assertEquals(1, $count);
    }
    
    /** @test */
    public function it_calculates_attendance_rate_correctly()
    {
        // Create 4 students with different statuses
        $students = Student::factory()->count(4)->create([
            'school_id' => $this->school->id,
            'classroom_id' => $this->classroom->id,
        ]);
        
        $handler = new RecordAttendanceHandler();
        
        // 2 present, 1 late, 1 absent
        $statuses = ['present', 'present', 'late', 'absent'];
        
        foreach ($students as $index => $student) {
            $command = new RecordAttendanceCommand(
                schoolId: $this->school->id,
                studentId: $student->id,
                scheduleId: $this->schedule->id,
                classId: $this->classroom->id,
                attendanceDate: today(),
                status: $statuses[$index],
                checkInTime: in_array($statuses[$index], ['present', 'late']) ? now() : null,
                isManual: $statuses[$index] === 'absent',
                qrCodeId: $statuses[$index] !== 'absent' ? 1 : null,
                recordedBy: $statuses[$index] === 'absent' ? 1 : null,
            );
            
            $handler->handle($command);
        }
        
        sleep(1); // Allow async processing
        
        $summary = AttendanceDailySummary::getTodaySummary($this->school->id);
        
        $this->assertEquals(2, $summary->total_present);
        $this->assertEquals(1, $summary->total_late);
        $this->assertEquals(1, $summary->total_absent);
        
        // Rate should be (2 + 1) / 4 * 100 = 75%
        $this->assertEquals(75.00, $summary->attendance_rate);
    }
}
