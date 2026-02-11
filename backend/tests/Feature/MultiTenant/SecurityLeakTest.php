<?php

namespace Tests\Feature\MultiTenant;

use App\Models\School;
use App\Models\User;
use App\Models\Schedule;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SecurityLeakTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_returns_404_when_accessing_other_tenant_record()
    {
        // 1. Setup Data for School A
        $schoolA = School::factory()->create();
        $teacherA = User::factory()->teacher()->create(['school_id' => $schoolA->id]);
        $scheduleA = Schedule::factory()->create(['school_id' => $schoolA->id]);

        // 2. Setup Data for School B
        $schoolB = School::factory()->create();
        $scheduleB = Schedule::factory()->create(['school_id' => $schoolB->id]);

        // 3. Login as Teacher A
        $this->actingAs($teacherA, 'sanctum');

        // 4. Try to access Schedule A (Should Succeed)
        // Adjust endpoint as necessary
        // $response = $this->getJson("/api/v1/teacher/schedule/{$scheduleA->id}");
        // $response->assertStatus(200);

        // 5. Try to access Schedule B (Should NOT BE FOUND - 404)
        // It must be 404, not 403, because for Teacher A, Schedule B "does not exist"
        $response = $this->getJson("/api/v1/teacher/schedule/{$scheduleB->id}");
        
        $response->assertStatus(404);
    }

    /** @test */
    public function eloquent_find_fails_for_other_tenant()
    {
        // 1. Setup Data
        $schoolA = School::factory()->create();
        $teacherA = User::factory()->teacher()->create(['school_id' => $schoolA->id]);
        
        $schoolB = School::factory()->create();
        $scheduleB = Schedule::factory()->create(['school_id' => $schoolB->id]);

        // 2. Login as Teacher A
        $this->actingAs($teacherA);

        // 3. Try to use manual Eloquent find()
        // This simulates a developer forgetting to check school_id manually,
        // relying on the Global Scope to filter it out.
        
        $found = Schedule::find($scheduleB->id);

        // 4. Expect NULL because global scope filters by user context
        $this->assertNull($found, 'SECURITY LEAK: Eloquent find() returned record from another tenant!');
    }

    /** @test */
    public function tenant_scope_auto_fills_correct_school_id()
    {
        $schoolA = School::factory()->create();
        $teacherA = User::factory()->teacher()->create(['school_id' => $schoolA->id]);
        $this->actingAs($teacherA);

        $schedule = Schedule::create([
            'teacher_id' => $teacherA->id,
            // school_id is missing intentionally
            'class_id' => 1,
            'subject_id' => 1,
            'is_active' => true
        ]);

        $this->assertEquals($schoolA->id, $schedule->school_id, 'SECURITY LEAK: Tenant Scope failed to auto-fill school_id');
    }
}
