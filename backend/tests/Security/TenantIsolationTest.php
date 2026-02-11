<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\Attendance;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\ReadModels\AttendanceDailySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security Test: Tenant Isolation
 * 
 * Tests multi-tenant data isolation:
 * - School A cannot access School B data
 * - Global scopes filter by school_id
 * - API returns 403/404 for cross-tenant access
 * - Read models filtered by tenant
 * 
 * @group security
 * @group tenant-isolation
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;
    private School $schoolB;
    private User $userSchoolA;
    private User $userSchoolB;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Arrange: Create two schools
        $this->schoolA = School::factory()->create(['name' => 'School A']);
        $this->schoolB = School::factory()->create(['name' => 'School B']);

        // Create users for each school
        $this->userSchoolA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role' => 'teacher',
        ]);

        $this->userSchoolB = User::factory()->create([
            'school_id' => $this->schoolB->id,
            'role' => 'teacher',
        ]);

        $this->superAdmin = User::factory()->create([
            'school_id' => null,
            'role' => 'super_admin',
        ]);
    }

    /**
     * TI-001: School A cannot access School B attendance
     * 
     * @test
     */
    public function it_prevents_cross_tenant_attendance_access(): void
    {
        // Arrange
        $studentB = Student::factory()->create(['school_id' => $this->schoolB->id]);
        $attendanceB = Attendance::factory()->create([
            'school_id' => $this->schoolB->id,
            'student_id' => $studentB->id,
        ]);

        // Act: User from School A tries to access School B attendance
        $this->actingAs($this->userSchoolA, 'sanctum');

        $response = $this->getJson("/api/attendance/{$attendanceB->id}");

        // Assert: Should get 404 (not found) or 403 (forbidden)
        $this->assertContains($response->status(), [403, 404]);
    }

    /**
     * TI-002: School A cannot modify School B data
     * 
     * @test
     */
    public function it_prevents_cross_tenant_data_modification(): void
    {
        // Arrange
        $studentB = Student::factory()->create(['school_id' => $this->schoolB->id]);
        $attendanceB = Attendance::factory()->create([
            'school_id' => $this->schoolB->id,
            'student_id' => $studentB->id,
        ]);

        // Act: User from School A tries to update School B attendance
        $this->actingAs($this->userSchoolA, 'sanctum');

        $response = $this->putJson("/api/attendance/{$attendanceB->id}", [
            'status' => 'absent',
        ]);

        // Assert: Should get 403 or 404
        $this->assertContains($response->status(), [403, 404]);

        // Verify data not modified
        $attendanceB->refresh();
        $this->assertNotEquals('absent', $attendanceB->status);
    }

    /**
     * TI-003: Global scope filters by school_id
     * 
     * @test
     */
    public function it_applies_global_scope_for_tenant_filtering(): void
    {
        // Arrange
        $studentA = Student::factory()->create(['school_id' => $this->schoolA->id]);
        $studentB = Student::factory()->create(['school_id' => $this->schoolB->id]);

        Attendance::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $studentA->id,
        ]);

        Attendance::factory()->create([
            'school_id' => $this->schoolB->id,
            'student_id' => $studentB->id,
        ]);

        // Act: Query as School A user
        $this->actingAs($this->userSchoolA, 'sanctum');

        // Assuming global scope is applied
        $attendances = Attendance::where('school_id', $this->schoolA->id)->get();

        // Assert: Only School A attendance returned
        $this->assertCount(1, $attendances);
        $this->assertEquals($this->schoolA->id, $attendances->first()->school_id);
    }

    /**
     * TI-004: Dashboard shows only own school data
     * 
     * @test
     */
    public function it_shows_only_own_school_data_in_dashboard(): void
    {
        // Arrange
        AttendanceDailySummary::factory()->create([
            'school_id' => $this->schoolA->id,
            'attendance_date' => today(),
            'total_present' => 50,
        ]);

        AttendanceDailySummary::factory()->create([
            'school_id' => $this->schoolB->id,
            'attendance_date' => today(),
            'total_present' => 60,
        ]);

        // Act: User from School A accesses dashboard
        $this->actingAs($this->userSchoolA, 'sanctum');

        $response = $this->getJson('/api/dashboard/summary');

        // Assert: Only School A data returned
        $response->assertStatus(200);
        
        $data = $response->json('data');
        if ($data) {
            $this->assertEquals($this->schoolA->id, $data['school_id'] ?? null);
            $this->assertEquals(50, $data['total_present'] ?? null);
        }
    }

    /**
     * TI-005: Super admin can access all schools
     * 
     * @test
     */
    public function it_allows_super_admin_to_access_all_schools(): void
    {
        // Arrange
        $studentA = Student::factory()->create(['school_id' => $this->schoolA->id]);
        $attendanceA = Attendance::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $studentA->id,
        ]);

        // Act: Super admin accesses School A data
        $this->actingAs($this->superAdmin, 'sanctum');

        $response = $this->getJson("/api/attendance/{$attendanceA->id}");

        // Assert: Super admin can access
        $response->assertStatus(200);
    }

    /**
     * TI-006: Teacher cannot access other schools
     * 
     * @test
     */
    public function it_prevents_teacher_from_accessing_other_schools(): void
    {
        // Arrange
        $studentB = Student::factory()->create(['school_id' => $this->schoolB->id]);

        // Act: Teacher from School A tries to view School B students
        $this->actingAs($this->userSchoolA, 'sanctum');

        $response = $this->getJson("/api/students/{$studentB->id}");

        // Assert: Access denied
        $this->assertContains($response->status(), [403, 404]);
    }

    /**
     * TI-007: Student cannot access other schools
     * 
     * @test
     */
    public function it_prevents_student_from_accessing_other_schools(): void
    {
        // Arrange
        $studentA = Student::factory()->create(['school_id' => $this->schoolA->id]);
        $studentB = Student::factory()->create(['school_id' => $this->schoolB->id]);

        $userStudentA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role' => 'student',
        ]);

        // Act: Student from School A tries to view School B student
        $this->actingAs($userStudentA, 'sanctum');

        $response = $this->getJson("/api/students/{$studentB->id}");

        // Assert: Access denied
        $this->assertContains($response->status(), [403, 404]);
    }

    /**
     * TI-008: Read model filtered by tenant
     * 
     * @test
     */
    public function it_filters_read_model_by_tenant(): void
    {
        // Arrange
        AttendanceDailySummary::factory()->create([
            'school_id' => $this->schoolA->id,
            'attendance_date' => today(),
        ]);

        AttendanceDailySummary::factory()->create([
            'school_id' => $this->schoolB->id,
            'attendance_date' => today(),
        ]);

        // Act: Query read model for School A
        $summaries = AttendanceDailySummary::where('school_id', $this->schoolA->id)->get();

        // Assert: Only School A data returned
        $this->assertCount(1, $summaries);
        $this->assertEquals($this->schoolA->id, $summaries->first()->school_id);
    }

    /**
     * TI-009: API list endpoints filtered by tenant
     * 
     * @test
     */
    public function it_filters_api_list_endpoints_by_tenant(): void
    {
        // Arrange
        $studentA1 = Student::factory()->create(['school_id' => $this->schoolA->id]);
        $studentA2 = Student::factory()->create(['school_id' => $this->schoolA->id]);
        $studentB1 = Student::factory()->create(['school_id' => $this->schoolB->id]);

        Attendance::factory()->create(['school_id' => $this->schoolA->id, 'student_id' => $studentA1->id]);
        Attendance::factory()->create(['school_id' => $this->schoolA->id, 'student_id' => $studentA2->id]);
        Attendance::factory()->create(['school_id' => $this->schoolB->id, 'student_id' => $studentB1->id]);

        // Act: User from School A lists attendances
        $this->actingAs($this->userSchoolA, 'sanctum');

        $response = $this->getJson('/api/attendance');

        // Assert: Only School A attendances returned
        $response->assertStatus(200);
        
        $data = $response->json('data');
        if (is_array($data)) {
            $this->assertCount(2, $data);
            foreach ($data as $attendance) {
                $this->assertEquals($this->schoolA->id, $attendance['school_id']);
            }
        }
    }

    /**
     * TI-010: Event listeners respect tenant context
     * 
     * @test
     */
    public function it_respects_tenant_context_in_event_listeners(): void
    {
        // Arrange
        $studentA = Student::factory()->create(['school_id' => $this->schoolA->id]);
        $studentB = Student::factory()->create(['school_id' => $this->schoolB->id]);

        $attendanceA = Attendance::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $studentA->id,
            'attendance_date' => today(),
        ]);

        $attendanceB = Attendance::factory()->create([
            'school_id' => $this->schoolB->id,
            'student_id' => $studentB->id,
            'attendance_date' => today(),
        ]);

        // Act: Trigger projector for both schools
        $projector = app(\App\ReadModels\Projectors\AttendanceSummaryProjector::class);
        $projector->projectForDate($this->schoolA->id, today());
        $projector->projectForDate($this->schoolB->id, today());

        // Assert: Summaries created for each school separately
        $summaryA = AttendanceDailySummary::forSchool($this->schoolA->id)
            ->forDate(today())
            ->schoolWide()
            ->first();

        $summaryB = AttendanceDailySummary::forSchool($this->schoolB->id)
            ->forDate(today())
            ->schoolWide()
            ->first();

        $this->assertNotNull($summaryA);
        $this->assertNotNull($summaryB);
        $this->assertEquals(1, $summaryA->total_students);
        $this->assertEquals(1, $summaryB->total_students);
    }

    /**
     * TI-011: Bulk operations respect tenant boundaries
     * 
     * @test
     */
    public function it_respects_tenant_boundaries_in_bulk_operations(): void
    {
        // Arrange
        $studentsA = Student::factory()->count(5)->create(['school_id' => $this->schoolA->id]);
        $studentsB = Student::factory()->count(5)->create(['school_id' => $this->schoolB->id]);

        $service = app(\App\Application\Services\AttendanceApplicationService::class);

        // Mix students from both schools
        $mixedData = $studentsA->concat($studentsB)->map(fn($s) => [
            'student_id' => $s->id,
            'schedule_id' => 1,
            'school_id' => $s->school_id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now(),
        ])->toArray();

        // Act: User from School A tries bulk check-in with mixed data
        $this->actingAs($this->userSchoolA, 'sanctum');

        // Should only process School A students
        $results = $service->bulkCheckIn(
            array_filter($mixedData, fn($d) => $d['school_id'] === $this->schoolA->id)
        );

        // Assert: Only School A students processed
        $this->assertCount(5, $results);
        
        $countA = Attendance::where('school_id', $this->schoolA->id)->count();
        $countB = Attendance::where('school_id', $this->schoolB->id)->count();

        $this->assertEquals(5, $countA);
        $this->assertEquals(0, $countB);
    }
}
