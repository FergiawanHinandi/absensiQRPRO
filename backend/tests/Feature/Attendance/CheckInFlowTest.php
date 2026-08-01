<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Application\Services\AttendanceApplicationService;
use App\Application\Services\DashboardQueryService;
use App\Events\StudentAttended;
use App\Models\Attendance;
use App\Models\School;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\User;
use App\ReadModels\AttendanceDailySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Integration Test: Full Check-In Flow
 * Tests the complete flow from controller to database to events to read model.
 * Flow:
 * Student scan → Controller → Application Service → Handler → Aggregate → DB
 *   → Event → Listener → Projector → Read Model Updated
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('attendance')]
class CheckInFlowTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Student $student;
    private Schedule $schedule;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Arrange: Create test data
        $this->school = School::factory()->create([
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'geofence_radius' => 100,
        ]);

        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
        ]);

        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'late_threshold' => 15,
        ]);

        $this->user = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => 'student',
        ]);
    }

    /**
     * IT-001: Full check-in flow: Controller → DB → Event → Summary
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_completes_full_check_in_flow(): void
    {
        // Arrange
        Event::fake([StudentAttended::class]);
        
        $service = app(AttendanceApplicationService::class);

        // Act
        $attendance = $service->checkIn([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now(),
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'source' => 'student_scan',
        ]);

        // Assert: Attendance inserted
        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => today()->format('Y-m-d'),
        ]);

        // Assert: Event dispatched
        Event::assertDispatched(StudentAttended::class, function ($event) {
            return $event->attendance->student_id === $this->student->id;
        });

        // Assert: Model returned
        $this->assertInstanceOf(Attendance::class, $attendance);
        $this->assertEquals($this->student->id, $attendance->student_id);
    }

    /**
     * IT-002: Event listener updates read model
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_updates_read_model_after_check_in(): void
    {
        // Arrange
        $service = app(AttendanceApplicationService::class);

        // Act
        $service->checkIn([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now(),
        ]);

        // Manually trigger projector (in real app, this happens via event listener)
        $projector = app(\App\ReadModels\Projectors\AttendanceSummaryProjector::class);
        $projector->projectForDate($this->school->id, today());

        // Assert: Read model updated
        $summary = AttendanceDailySummary::forSchool($this->school->id)
            ->forDate(today())
            ->schoolWide()
            ->first();

        $this->assertNotNull($summary);
        $this->assertEquals(1, $summary->total_students);
        $this->assertEquals(1, $summary->total_present);
    }

    /**
     * IT-003: Dashboard query uses read model
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_queries_dashboard_from_read_model(): void
    {
        // Arrange
        AttendanceDailySummary::factory()->create([
            'school_id' => $this->school->id,
            'attendance_date' => today(),
            'total_students' => 50,
            'total_present' => 45,
            'total_late' => 3,
            'total_absent' => 2,
            'attendance_rate' => 96.0,
        ]);

        $queryService = app(DashboardQueryService::class);

        // Act
        $summary = $queryService->getTodaySummary($this->school->id);

        // Assert
        $this->assertNotNull($summary);
        $this->assertEquals(50, $summary->total_students);
        $this->assertEquals(45, $summary->total_present);
        $this->assertEquals(3, $summary->total_late);
        $this->assertEquals(96.0, $summary->attendance_rate);
    }

    /**
     * IT-004: No duplicate attendance allowed
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_duplicate_check_in(): void
    {
        // Arrange
        $service = app(AttendanceApplicationService::class);

        // First check-in
        $service->checkIn([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now(),
        ]);

        // Act & Assert: Second check-in should fail
        $this->expectException(\Exception::class);
        
        $service->checkIn([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now(),
        ]);
    }

    /**
     * IT-005: Cache invalidation on attendance change
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_invalidates_cache_on_attendance_change(): void
    {
        // Arrange
        $queryService = app(DashboardQueryService::class);
        
        // Create initial summary
        AttendanceDailySummary::factory()->create([
            'school_id' => $this->school->id,
            'attendance_date' => today(),
            'total_present' => 10,
        ]);

        // First query (caches result)
        $summary1 = $queryService->getTodaySummary($this->school->id);
        $this->assertEquals(10, $summary1->total_present);

        // Act: Update summary
        AttendanceDailySummary::where('school_id', $this->school->id)
            ->where('attendance_date', today())
            ->update(['total_present' => 20]);

        // Clear cache
        $queryService->clearCache($this->school->id);

        // Assert: Second query gets fresh data
        $summary2 = $queryService->getTodaySummary($this->school->id);
        $this->assertEquals(20, $summary2->total_present);
    }

    /**
     * IT-006: Bulk check-in flow
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_bulk_check_in(): void
    {
        // Arrange
        $students = Student::factory()->count(10)->create([
            'school_id' => $this->school->id,
        ]);

        $service = app(AttendanceApplicationService::class);

        $bulkData = $students->map(fn($student) => [
            'student_id' => $student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now(),
        ])->toArray();

        // Act
        $results = $service->bulkCheckIn($bulkData);

        // Assert
        $this->assertCount(10, $results);
        $this->assertEquals(10, Attendance::count());
    }

    /**
     * IT-007: Check-out flow
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_completes_check_out_flow(): void
    {
        // Arrange
        $service = app(AttendanceApplicationService::class);

        $attendance = $service->checkIn([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now()->subHours(2),
        ]);

        // Act
        $updated = $service->checkOut($attendance->id, [
            'check_out_time' => now(),
            'latitude' => -6.2088,
            'longitude' => 106.8456,
        ]);

        // Assert
        $this->assertNotNull($updated->check_out_time);
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'check_out_time' => $updated->check_out_time,
        ]);
    }

    /**
     * IT-008: Correction request flow
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_completes_correction_request_flow(): void
    {
        // Arrange
        $service = app(AttendanceApplicationService::class);

        $attendance = $service->checkIn([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now(),
        ]);

        // Act
        $updated = $service->requestCorrection(
            attendanceId: $attendance->id,
            reason: 'Wrong status',
            requesterId: $this->user->id
        );

        // Assert
        $this->assertEquals('Wrong status', $updated->correction_reason);
        $this->assertEquals($this->user->id, $updated->correction_requested_by);
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'correction_reason' => 'Wrong status',
        ]);
    }

    /**
     * IT-009: API endpoint integration
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_check_in_via_api_endpoint(): void
    {
        // Arrange
        $this->actingAs($this->user, 'sanctum');

        // Act
        $response = $this->postJson('/api/attendance/check-in', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'latitude' => -6.2088,
            'longitude' => 106.8456,
        ]);

        // Assert
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'student_id',
                'schedule_id',
                'check_in_time',
            ],
        ]);

        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);
    }

    /**
     * IT-010: Weekly trend query
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_retrieves_weekly_trend_from_read_model(): void
    {
        // Arrange
        $queryService = app(DashboardQueryService::class);

        // Create summaries for last 7 days
        for ($i = 0; $i < 7; $i++) {
            AttendanceDailySummary::factory()->create([
                'school_id' => $this->school->id,
                'attendance_date' => today()->subDays($i),
                'total_present' => 40 + $i,
            ]);
        }

        // Act
        $trend = $queryService->getWeeklyTrend($this->school->id, 7);

        // Assert
        $this->assertCount(7, $trend);
        $this->assertEquals(40, $trend->first()->total_present);
    }
}
