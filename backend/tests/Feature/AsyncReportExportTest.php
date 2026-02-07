<?php

namespace Tests\Feature;

use App\Jobs\ExportAttendanceReport;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AsyncReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Skip rate limiting in testing environment is default behavior of CriticalRateLimiting
        // But for testing rate limits, we'd need to mock it. 
        // This test focuses on Async Job dispatching.
    }

    public function test_export_endpoint_dispatches_job()
    {
        Queue::fake();

        $school = School::factory()->create();
        $admin = User::factory()->create([
            'role_type' => 'school_admin',
            'school_id' => $school->id,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/reports/export', [
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->toDateString(),
            'format' => 'xlsx',
        ]);

        // Assert 202 Accepted or 200 OK depending on implementation
        // Assuming async export returns 202
        $response->assertStatus(202); 

        Queue::assertPushed(ExportAttendanceReport::class);
    }

    public function test_unauthorized_download()
    {
        $school = School::factory()->create();
        $userA = User::factory()->create(['school_id' => $school->id, 'role_type' => 'school_admin']);
        $userB = User::factory()->create(['school_id' => $school->id, 'role_type' => 'school_admin']);

        // Create export for User A
        $export = \App\Models\ReportExport::create([
            'user_id' => $userA->id,
            'school_id' => $school->id,
            'type' => 'attendance',
            'format' => 'xlsx',
            'status' => 'completed',
            'file_path' => 'dummy.xlsx',
            'file_name' => 'dummy.xlsx',
            'parameters' => []
        ]);

        // User B tries to download
        $response = $this->actingAs($userB)->getJson("/api/v1/reports/export/{$export->id}/download");

        $response->assertStatus(404);
    }

    public function test_rate_limit_applied()
    {
        Queue::fake();
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id, 'role_type' => 'school_admin']);

        $payload = [
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->toDateString(),
            'format' => 'xlsx',
        ];

        // Hit 5 times (limit)
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/v1/reports/export', $payload)->assertStatus(202);
        }

        // 6th time should fail
        $this->actingAs($user)->postJson('/api/v1/reports/export', $payload)->assertStatus(429);
    }

    public function test_can_download_own_export()
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id, 'role_type' => 'school_admin']);

        // Create dummy file
        \Illuminate\Support\Facades\Storage::disk('local')->put('exports/test.xlsx', 'content');

        $export = \App\Models\ReportExport::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'type' => 'attendance',
            'format' => 'xlsx',
            'status' => 'completed',
            'file_path' => 'exports/test.xlsx',
            'file_name' => 'test.xlsx',
            'file_size' => 1024,
            'parameters' => []
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/reports/export/{$export->id}/download");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
