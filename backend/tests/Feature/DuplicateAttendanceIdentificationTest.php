<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Attendance;
use App\Models\School;
use App\Models\Student;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DuplicateAttendanceIdentificationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected Student $student;
    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->student = Student::factory()->create(['school_id' => $this->school->id]);
        $this->schedule = Schedule::factory()->create(['school_id' => $this->school->id]);
    }


    #[Test]
    public function it_identifies_no_duplicates_when_database_is_clean(): void
    {
        // Create unique attendance records
        Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
        ]);

        Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-02', // Different date
            'school_id' => $this->school->id,
        ]);

        $exitCode = Artisan::call('attendance:identify-duplicates');

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('No duplicate', Artisan::output());
    }


    #[Test]
    public function it_identifies_duplicate_attendance_records(): void
    {
        // Create duplicate records
        $baseData = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
        ];

        Attendance::factory()->create(array_merge($baseData, [
            'created_at' => now()->subHours(2),
        ]));

        Attendance::factory()->create(array_merge($baseData, [
            'created_at' => now()->subHour(),
        ]));

        Attendance::factory()->create(array_merge($baseData, [
            'created_at' => now(),
        ]));

        $exitCode = Artisan::call('attendance:identify-duplicates');

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Found 1 duplicate groups', $output);
        $this->assertStringContainsString('affecting 3 records', $output);
        $this->assertStringContainsString('Records to clean: 2', $output);
    }


    #[Test]
    public function it_groups_duplicates_by_school(): void
    {
        $school2 = School::factory()->create();
        $student2 = Student::factory()->create(['school_id' => $school2->id]);
        $schedule2 = Schedule::factory()->create(['school_id' => $school2->id]);

        // School 1 duplicates
        $baseData1 = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
        ];

        Attendance::factory()->count(2)->create($baseData1);

        // School 2 duplicates
        $baseData2 = [
            'student_id' => $student2->id,
            'schedule_id' => $schedule2->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $school2->id,
        ];

        Attendance::factory()->count(3)->create($baseData2);

        $exitCode = Artisan::call('attendance:identify-duplicates');

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Breakdown by School', $output);
        $this->assertStringContainsString((string)$this->school->id, $output);
        $this->assertStringContainsString((string)$school2->id, $output);
    }


    #[Test]
    public function it_filters_duplicates_by_school(): void
    {
        $school2 = School::factory()->create();
        $student2 = Student::factory()->create(['school_id' => $school2->id]);
        $schedule2 = Schedule::factory()->create(['school_id' => $school2->id]);

        // School 1 duplicates
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
        ]);

        // School 2 duplicates
        Attendance::factory()->count(2)->create([
            'student_id' => $student2->id,
            'schedule_id' => $schedule2->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $school2->id,
        ]);

        $exitCode = Artisan::call('attendance:identify-duplicates', [
            '--school' => $this->school->id,
        ]);

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString("Filtering by school_id: {$this->school->id}", $output);
        $this->assertStringContainsString('Found 1 duplicate groups', $output);
    }


    #[Test]
    public function it_shows_oldest_record_to_keep(): void
    {
        $oldest = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
            'created_at' => now()->subDays(2),
        ]);

        $middle = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
            'created_at' => now()->subDay(),
        ]);

        $newest = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
            'created_at' => now(),
        ]);

        $exitCode = Artisan::call('attendance:identify-duplicates');

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString("Keep: ID {$oldest->id}", $output);
        $this->assertStringContainsString("Delete: IDs {$middle->id}, {$newest->id}", $output);
    }


    #[Test]
    public function it_ignores_soft_deleted_records(): void
    {
        // Create duplicates
        $baseData = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
        ];

        $record1 = Attendance::factory()->create($baseData);
        $record2 = Attendance::factory()->create($baseData);
        
        // Soft delete one
        $record2->delete();

        $exitCode = Artisan::call('attendance:identify-duplicates');

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('No duplicate', Artisan::output());
    }


    #[Test]
    public function it_exports_duplicates_to_csv(): void
    {
        // Create duplicates
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
        ]);

        $exitCode = Artisan::call('attendance:identify-duplicates', [
            '--export' => 'csv',
        ]);

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Exported to:', $output);
        $this->assertStringContainsString('.csv', $output);
    }


    #[Test]
    public function it_exports_duplicates_to_json(): void
    {
        // Create duplicates
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
        ]);

        $exitCode = Artisan::call('attendance:identify-duplicates', [
            '--export' => 'json',
        ]);

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Exported to:', $output);
        $this->assertStringContainsString('.json', $output);
    }


    #[Test]
    public function it_shows_cleanup_strategy(): void
    {
        // Create duplicates
        Attendance::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => '2026-03-01',
            'school_id' => $this->school->id,
        ]);

        $exitCode = Artisan::call('attendance:identify-duplicates');

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Recommended Cleanup Strategy', $output);
        $this->assertStringContainsString('Keep the OLDEST record', $output);
        $this->assertStringContainsString('Soft delete all newer', $output);
        $this->assertStringContainsString('Log all cleanup actions', $output);
    }
}
