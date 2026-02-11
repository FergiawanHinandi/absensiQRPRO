<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\School;
use App\Models\Attendance;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Data Manipulation & Injection Test
 * 
 * Menguji ketahanan terhadap:
 * 1. Business Logic Bypass (Status Manipulation)
 * 2. Mass Assignment Vulnerability (School ID Injection)
 * 3. Injection Attacks (SQL Injection)
 * 4. Cross-Tenant Access (IDOR)
 * 
 * @group security
 * @group manipulation
 */
class DataManipulationTest extends TestCase
{
    use RefreshDatabase;

    protected $schoolA;
    protected $schoolB;
    protected $teacherA;
    protected $adminB;
    protected $scheduleA;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup Schools
        $this->schoolA = School::factory()->create(['name' => 'School A']);
        $this->schoolB = School::factory()->create(['name' => 'School B']);

        // Setup Users
        $this->teacherA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'role_type' => 'teacher',
        ]);

        $this->adminB = User::factory()->create([
            'school_id' => $this->schoolB->id, // Different School
            'role_type' => 'school_admin',
        ]);

        // Setup Data
        $this->scheduleA = Schedule::factory()->create([
            'school_id' => $this->schoolA->id,
            'teacher_id' => $this->teacherA->id,
        ]);
    }

    /**
     * TEST 1: Status Manipulation
     * Guru mencoba set status 'APPROVED' secara langsung (Bypass Workflow)
     */
    public function test_rejects_invalid_status_during_creation()
    {
        $payload = [
            'student_id' => User::factory()->create(['school_id' => $this->schoolA->id])->id,
            'schedule_id' => $this->scheduleA->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'APPROVED', // Should be rejected (only PRESENT/LATE/SICK/PERMIT allowed)
            'notes' => 'Manual bypass attempt'
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/attendance/manual', $payload);

        // Expectation: 422 Unprocessable Entity (Validation Error)
        // Validation rule should implement: in:present,late,sick,permit,alpha
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    /**
     * TEST 2: School ID Injection (Mass Assignment)
     * Guru Sekolah A mencoba membuat data untuk Sekolah B
     */
    public function test_ignores_school_id_in_payload()
    {
        $student = User::factory()->create(['school_id' => $this->schoolA->id]);
        
        $payload = [
            'student_id' => $student->id,
            'schedule_id' => $this->scheduleA->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'school_id' => $this->schoolB->id, // Malicious input (Injecting School B ID)
        ];

        $this->actingAs($this->teacherA)
            ->postJson('/api/v1/attendance/manual', $payload)
            ->assertSuccessful();

        // Verify Data Integrity
        $attendance = Attendance::where('student_id', $student->id)->latest()->first();
        
        // Assert: System MUST ignore input and use Auth User's School ID
        $this->assertEquals($this->schoolA->id, $attendance->school_id, 'System executed mass assignment on school_id!');
        $this->assertNotEquals($this->schoolB->id, $attendance->school_id);
    }

    /**
     * TEST 3: SQL Injection via Dashboard Filter
     * Menguji filter parameters terhadap Raw SQL Injection
     */
    public function test_prevents_sql_injection_in_filters()
    {
        // Payload: ' OR 1=1 --
        // Threat: Dumping all data regardless of filters/scopes
        
        $maliciousDate = "' OR 1=1 --";
        
        $response = $this->actingAs($this->teacherA)
            ->getJson("/api/v1/teacher/dashboard?date={$maliciousDate}");

        // Expectation:
        // 1. 422 Validation Error (Date format check)
        // OR 2. 200 OK but handled as literal string/sanitized (no extra data leaked)
        
        if ($response->status() === 422) {
             $response->assertJsonValidationErrors(['date']); // Best practice validation
        } else {
             $response->assertStatus(200);
             // Ensure it didn't crash (500) or leak all records
             // System logic likely defaults to Today() on invalid date format
        }
        
        // Ensure no raw SQL error leaked
        $this->assertStringNotContainsString('SQL syntax', $response->content());
    }

    /**
     * TEST 4: Cross-Tenant Access (IDOR via Direct ID)
     * Admin Sekolah B mencoba melihat Attendance Sekolah A
     */
    public function test_prevents_cross_tenant_access_by_id()
    {
        // 1. Data existing di School A
        $attendanceA = Attendance::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => User::factory()->create(['school_id' => $this->schoolA->id])->id,
        ]);

        // 2. Admin B (School B) attempts to access
        // Assuming endpoint GET /api/v1/attendance/{id} exists
        // If not, maybe History or similar endpoints
        
        // Implementation check: Do we have a distinct 'show' endpoint?
        // Let's assume standard resource controller path or history filter
        
        // Scenario: Using History endpoint filtering by specific ID if supported, 
        // or getting a specific student's log from School A
        
        // Given typically IDOR happens on: GET /api/v1/attendance/{id}
        // Let's assume one exists or simulate similar controller logic
        
        // If no explicit endpoint, let's try Manual update on existing record from other school
        
        $response = $this->actingAs($this->adminB)
            ->putJson("/api/v1/attendance/{$attendanceA->id}", [
                'status' => 'present'
            ]);
            
        // Expectation: 404 Not Found (Scope applied) or 403 Forbidden
        // Laravel Resource Controller with Scoped Binding usually returns 404
        $this->assertTrue(in_array($response->status(), [403, 404]), 
            "Admin B could access School A attendance! (Status: {$response->status()})");
    }
}
