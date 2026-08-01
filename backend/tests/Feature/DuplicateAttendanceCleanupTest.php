<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Attendance;
use App\Models\School;
use App\Models\Student;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DuplicateAttendanceCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected Student $student;
    protected Schedule $schedule;
    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->school = School::factory()->create();
        $this->student = Student::factory()->create(['school_id' => $this->school->id]);
        $this->schedule = Schedule::factory()->create(['school_id' => $this->school->id]);
        $this->teacher = User::factory()->create(['school_id' => $this->school->id]);
    }


    #[Test]
    public function it_identifies_duplicate_attendance_records()
    {
        // Create duplicates
        $date = today();
        Attendance::factory()->count(3)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
        ]);

        // Run identification
        $exitCode = Artisan::call('attendance:identify-duplicates');

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Found 1 duplicate groups', $output);
        $this->assertStringContainsString('affecting 3 records', $output);
    }


    #[Test]
    public function it_keeps_oldest_record_and_deletes_newer_duplicates()
    {
        // Create duplicates with different timestamps
        $date = today();
        $oldest = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
            'created_at' => now()->subHours(3),
        ]);

        $middle = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
            'created_at' => now()->subHours(2),
        ]);

        $newest = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
            'created_at' => now()->subHours(1),
        ]);

        // Run cleanup with force flag
        $exitCode = Artisan::call('attendance:cleanup-duplicates', ['--force' => true]);

        $this->assertEquals(0, $exitCode);

        // Verify oldest is kept
        $this->assertDatabaseHas('attendances', [
            'id' => $oldest->id,
            'deleted_at' => null,
        ]);

        // Verify newer ones are soft deleted
        $this->assertSoftDeleted('attendances', ['id' => $middle->id]);
        $this->assertSoftDeleted('attendances', ['id' => $newest->id]);
    }


    #[Test]
    public function it_handles_multiple_duplicate_groups()
    {
        $date = today();

        // Group 1: Student 1, Schedule 1
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
        ]);

        // Group 2: Student 1, Schedule 1, different date
        Attendance::factory()->count(3)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date->copy()->addDay(),
            'school_id' => $this->school->id,
        ]);

        // Run cleanup
        $exitCode = Artisan::call('attendance:cleanup-duplicates', ['--force' => true]);

        $this->assertEquals(0, $exitCode);

        // Verify only 2 records remain (1 per group)
        $remaining = Attendance::whereNull('deleted_at')->count();
        $this->assertEquals(2, $remaining);

        // Verify 3 records were deleted (1 from group 1, 2 from group 2)
        $deleted = Attendance::onlyTrashed()->count();
        $this->assertEquals(3, $deleted);
    }


    #[Test]
    public function it_filters_by_school_id()
    {
        $school2 = School::factory()->create();
        $student2 = Student::factory()->create(['school_id' => $school2->id]);
        $schedule2 = Schedule::factory()->create(['school_id' => $school2->id]);

        $date = today();

        // School 1 duplicates
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
        ]);

        // School 2 duplicates
        Attendance::factory()->count(2)->create([
            'student_id' => $student2->id,
            'schedule_id' => $schedule2->id,
            'attendance_date' => $date,
            'school_id' => $school2->id,
        ]);

        // Run cleanup for school 1 only
        $exitCode = Artisan::call('attendance:cleanup-duplicates', [
            '--school' => $this->school->id,
            '--force' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        // Verify school 1 duplicates cleaned
        $school1Remaining = Attendance::where('school_id', $this->school->id)
            ->whereNull('deleted_at')
            ->count();
        $this->assertEquals(1, $school1Remaining);

        // Verify school 2 duplicates untouched
        $school2Remaining = Attendance::where('school_id', $school2->id)
            ->whereNull('deleted_at')
            ->count();
        $this->assertEquals(2, $school2Remaining);
    }


    #[Test]
    public function it_performs_dry_run_without_making_changes()
    {
        $date = today();
        Attendance::factory()->count(3)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
        ]);

        // Run dry run
        $exitCode = Artisan::call('attendance:cleanup-duplicates', ['--dry-run' => true]);

        $this->assertEquals(0, $exitCode);

        // Verify no records were deleted
        $remaining = Attendance::whereNull('deleted_at')->count();
        $this->assertEquals(3, $remaining);

        $deleted = Attendance::onlyTrashed()->count();
        $this->assertEquals(0, $deleted);

        // Verify output mentions dry run
        $output = Artisan::output();
        $this->assertStringContainsString('DRY RUN', $output);
    }


    #[Test]
    public function it_logs_cleanup_actions()
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Duplicate attendance record deleted'
                    && isset($context['id'])
                    && isset($context['reason'])
                    && $context['reason'] === 'duplicate_cleanup';
            });

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Duplicate attendance cleanup completed'
                    && isset($context['summary']);
            });

        $date = today();
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
        ]);

        Artisan::call('attendance:cleanup-duplicates', ['--force' => true]);
    }


    #[Test]
    public function it_saves_cleanup_log_to_file()
    {
        $date = today();
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
        ]);

        Artisan::call('attendance:cleanup-duplicates', ['--force' => true]);

        // Check that log file was created
        $logFiles = glob(storage_path('logs/duplicate-cleanup-*.json'));
        $this->assertNotEmpty($logFiles);

        // Verify log content
        $logContent = json_decode(file_get_contents($logFiles[0]), true);
        $this->assertArrayHasKey('executed_at', $logContent);
        $this->assertArrayHasKey('summary', $logContent);
        $this->assertArrayHasKey('details', $logContent);
        $this->assertEquals(1, $logContent['summary']['duplicate_groups_processed']);
        $this->assertEquals(1, $logContent['summary']['records_kept']);
        $this->assertEquals(1, $logContent['summary']['records_deleted']);

        // Cleanup
        unlink($logFiles[0]);
    }


    #[Test]
    public function it_handles_no_duplicates_gracefully()
    {
        // Create unique attendance records
        Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => today(),
            'school_id' => $this->school->id,
        ]);

        Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => today()->addDay(),
            'school_id' => $this->school->id,
        ]);

        $exitCode = Artisan::call('attendance:cleanup-duplicates', ['--force' => true]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('No duplicate attendance records found', $output);
    }


    #[Test]
    public function it_respects_soft_deletes_and_ignores_already_deleted_records()
    {
        $date = today();

        // Create duplicates
        $record1 = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
            'created_at' => now()->subHours(3),
        ]);

        $record2 = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
            'created_at' => now()->subHours(2),
        ]);

        // Soft delete one record manually
        $record2->delete();

        // Run cleanup
        $exitCode = Artisan::call('attendance:cleanup-duplicates', ['--force' => true]);

        $this->assertEquals(0, $exitCode);

        // Should find no duplicates since one is already deleted
        $output = Artisan::output();
        $this->assertStringContainsString('No duplicate attendance records found', $output);
    }


    #[Test]
    public function it_displays_breakdown_by_school()
    {
        $school2 = School::factory()->create();
        $student2 = Student::factory()->create(['school_id' => $school2->id]);
        $schedule2 = Schedule::factory()->create(['school_id' => $school2->id]);

        $date = today();

        // School 1: 2 duplicates
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
        ]);

        // School 2: 3 duplicates
        Attendance::factory()->count(3)->create([
            'student_id' => $student2->id,
            'schedule_id' => $schedule2->id,
            'attendance_date' => $date,
            'school_id' => $school2->id,
        ]);

        $exitCode = Artisan::call('attendance:cleanup-duplicates', ['--dry-run' => true]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();

        // Verify breakdown table is displayed
        $this->assertStringContainsString('Breakdown by School', $output);
        $this->assertStringContainsString((string)$this->school->id, $output);
        $this->assertStringContainsString((string)$school2->id, $output);
    }


    #[Test]
    public function it_rolls_back_transaction_on_error()
    {
        $date = today();
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => $date,
            'school_id' => $this->school->id,
        ]);

        // Mock an error during cleanup
        $this->mock(Attendance::class)
            ->shouldReceive('delete')
            ->andThrow(new \Exception('Simulated error'));

        $exitCode = Artisan::call('attendance:cleanup-duplicates', ['--force' => true]);

        // Command should fail
        $this->assertEquals(1, $exitCode);

        // Verify no records were deleted (transaction rolled back)
        $remaining = Attendance::whereNull('deleted_at')->count();
        $this->assertEquals(2, $remaining);
    }
}
