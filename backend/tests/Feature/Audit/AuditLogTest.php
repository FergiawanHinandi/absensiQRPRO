<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Models\School;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Audit Log Verification Test
 * Memastikan setiap operasi sensitif tercatat dengan benar di Audit Log.
 * Scenarios:
 * 1. Manual Override (Status Change)
 * 2. Tenant Bypass (Super Admin Access)
 * 3. Security Incident (Replay/Spoofing) - Log Verification
 */
#[\PHPUnit\Framework\Attributes\Group('audit')]
#[\PHPUnit\Framework\Attributes\Group('logging')]
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected $school;
    protected $teacher;
    protected $student;
    protected $superAdmin;
    protected $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->school = School::factory()->create();
        
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);
        
        $this->superAdmin = User::factory()->create([
            'role_type' => 'super_admin',
            // No school_id usually
        ]);

        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacher->id,
        ]);
    }

    /**
     * TEST 1: Manual Override Audit
     * Guru mengubah status kehadiran.
     */
    public function test_logs_manual_status_change()
    {
        // 1. Create Initial Attendance
        $attendance = Attendance::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'present',
            'attendance_date' => now()->toDateString(),
        ]);

        // 2. Teacher updates to 'sick' (Manual Override)
        // Assuming endpoint PUT /api/v1/attendance/{id} exists or similar
        // Or simulation via Service call if endpoint not strictly defined in context
        // Using Service simulation for reliability since we focus on 'Audit Log' logic
        
        // Simulating Service Call (since Controller logic delegates to Service)
        // Service should fire AuditLog creation.
        
        // For Feature Test consistency, let's use the generic "Manual Attendance" POST 
        // which performs "Update or Create"
        
        $payload = [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'sick', // Changing from Present -> Sick
            'notes' => 'Sakit perut'
        ];

        $this->actingAs($this->teacher)
            ->postJson('/api/v1/attendance/manual', $payload)
            ->assertSuccessful();

        // 3. Verify Audit Log Entry
        $log = AuditLog::where('module', 'Attendance')
            ->where('action', 'manual_entry') // Standard action name
            ->where('user_id', $this->teacher->id)
            ->latest()
            ->first();

        $this->assertNotNull($log, 'Audit Log entry missing for manual override!');
        
        // Verify Content
        $details = json_decode($log->description, true);
        $this->assertEquals($this->student->id, $details['student_id']);
        $this->assertEquals('sick', $details['status']);
        
        // Ensure Request ID exists (Context)
        // $this->assertArrayHasKey('request_id', $details); // If implemented in controller
    }

    /**
     * TEST 2: Tenant Bypass Audit (Super Admin)
     * Super Admin mengakses data sekolah tertentu.
     */
    public function test_logs_super_admin_tenant_bypass()
    {
        // 1. Super Admin access School A data
        // Explicit bypass via specific header or just by role virtue
        // Assuming Middleware logs this activity.
        
        // Simulating access to Dashboard or List
        $this->actingAs($this->superAdmin);
        
        // Trigger generic access that uses Tenant Scope Bypass
        // e.g. View School A Dashboard
        // Or Access Model directly if testing Scope logic
        
        // Let's use a specific endpoint that triggers the Log via Middleware 'log.superadmin'
        // GET /api/v1/admin/schools/{id}/dashboard (hypothetical)
        // Or just generic access
        
        // For unit testing the Service/Scope:
        \App\Services\TenantScopeBypassAuditService::logBypass(
            $this->superAdmin,
            $this->school->id,
            'Manual Test Bypass'
        );

        // Verify Log
        $log = AuditLog::where('action', 'tenant_bypass')
            ->where('user_id', $this->superAdmin->id)
            ->latest()
            ->first();

        $this->assertNotNull($log, 'Audit Log missing for Tenant Bypass!');
        $this->assertEquals('security', $log->severity); // Should be High/Security
        
        $details = json_decode($log->description, true);
        $this->assertEquals($this->school->id, $details['target_school_id']);
    }

    /**
     * TEST 3: Replay Attempt Logging
     * Replay attack harus dicatat (minimal di file log Warning).
     */
    public function test_logs_replay_attempt_incident()
    {
        $key = Str::uuid()->toString();
        
        // 1. First Request
        $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/v1/attendance/manual', [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => now()->toDateString(),
                'status' => 'present'
            ]);

        // 2. Replay Request
        // We capture Log facade here
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Replay attack detected') || 
                       str_contains($message, 'Duplicate request');
            });

        $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/v1/attendance/manual', [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => now()->toDateString(),
                'status' => 'present'
            ]);
            
        // Note: Middleware 'AttendanceSecurity' or 'Idempotency' usually logs warning
    }
}
