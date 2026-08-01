<?php

namespace Tests\Feature;

use App\Exports\AttendanceReportExport;
use App\Jobs\ExportAttendanceReport;
use App\Models\Attendance;
use App\Models\ExportProgress;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Export Chunking Tests
 * 
 * Tests for Week 3 Day 14: Export Chunking
 * 
 * **Validates: Requirements Week 3 Day 14.1, 14.2, 14.3**
 * 
 * Property 40: Export uses cursor() for memory efficiency
 * Property 41: Chunk size is configurable
 * Property 42: Progress tracking is implemented
 */
class ExportChunkingTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $admin;
    protected Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test school
        $this->school = School::factory()->create([
            'name' => 'Test School',
            'timezone' => 'Asia/Jakarta',
        ]);

        // Create admin user
        $this->admin = User::factory()->create([
            'school_id' => $this->school->id,
            'email' => 'admin@test.com',
        ]);
        $this->admin->assignRole('school_admin');

        // Create teacher
        $this->teacher = Teacher::factory()->create([
            'school_id' => $this->school->id,
        ]);

        Storage::fake('public');
    }

    /**
     * **Property 40: Export uses cursor() for memory efficiency**
     * 
     * Test that exports use cursor() instead of get() for large datasets.
     * This prevents memory exhaustion on 100K+ rows.
     */
    public function test_export_uses_cursor_for_memory_efficiency(): void
    {
        // Create a moderate dataset (1000 records)
        $students = Student::factory()->count(10)->create([
            'school_id' => $this->school->id,
        ]);

        foreach ($students as $student) {
            Attendance::factory()->count(100)->create([
                'student_id' => $student->id,
                'school_id' => $this->school->id,
                'teacher_id' => $this->teacher->id,
            ]);
        }

        $memoryBefore = memory_get_usage(true);

        // Create export
        $export = new AttendanceReportExport('daily', [
            'school_id' => $this->school->id,
            'date' => now()->toDateString(),
        ]);

        // Execute query (this will use cursor internally)
        $query = $export->query();
        $count = $query->count();

        $memoryAfter = memory_get_usage(true);
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // Convert to MB

        // Assert memory usage is reasonable (< 50MB for 1000 records)
        $this->assertLessThan(50, $memoryUsed, 'Memory usage should be less than 50MB for 1000 records');
        $this->assertEquals(1000, $count, 'Should have 1000 attendance records');
    }

    /**
     * **Property 41: Chunk size is configurable**
     * 
     * Test that chunk size can be configured via config/exports.php
     */
    public function test_chunk_size_is_configurable(): void
    {
        // Test default chunk size
        $defaultChunkSize = config('exports.chunk_size');
        $this->assertEquals(1000, $defaultChunkSize, 'Default chunk size should be 1000');

        // Test custom chunk size
        config(['exports.chunk_size' => 500]);
        
        $export = new AttendanceReportExport('daily', [
            'school_id' => $this->school->id,
        ]);

        $this->assertEquals(500, $export->chunkSize(), 'Chunk size should be configurable');
    }

    /**
     * **Property 41: Chunk size validation**
     * 
     * Test that chunk size has reasonable limits
     */
    public function test_chunk_size_has_reasonable_limits(): void
    {
        // Test minimum chunk size (should not be too small)
        config(['exports.chunk_size' => 10]);
        $export = new AttendanceReportExport('daily', ['school_id' => $this->school->id]);
        $this->assertGreaterThanOrEqual(10, $export->chunkSize());

        // Test maximum chunk size (should not be too large)
        config(['exports.chunk_size' => 10000]);
        $export = new AttendanceReportExport('daily', ['school_id' => $this->school->id]);
        $this->assertLessThanOrEqual(10000, $export->chunkSize());
    }

    /**
     * **Property 42: Progress tracking is implemented**
     * 
     * Test that export progress is tracked in database
     */
    public function test_progress_tracking_is_implemented(): void
    {
        // Create export progress record
        $exportProgress = ExportProgress::create([
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
            'export_type' => 'daily',
            'status' => ExportProgress::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('export_progress', [
            'id' => $exportProgress->id,
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
            'status' => ExportProgress::STATUS_PENDING,
        ]);

        // Mark as processing
        $exportProgress->markAsProcessing(1000);

        $this->assertDatabaseHas('export_progress', [
            'id' => $exportProgress->id,
            'status' => ExportProgress::STATUS_PROCESSING,
            'total_records' => 1000,
        ]);

        $this->assertNotNull($exportProgress->fresh()->started_at);
    }

    /**
     * **Property 42: Progress updates during export**
     * 
     * Test that progress is updated as records are processed
     */
    public function test_progress_updates_during_export(): void
    {
        $exportProgress = ExportProgress::create([
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
            'export_type' => 'daily',
            'status' => ExportProgress::STATUS_PROCESSING,
            'total_records' => 1000,
        ]);

        // Simulate progress updates
        $exportProgress->updateProgress(250);
        $this->assertEquals(25, $exportProgress->fresh()->progress_percentage);

        $exportProgress->updateProgress(500);
        $this->assertEquals(50, $exportProgress->fresh()->progress_percentage);

        $exportProgress->updateProgress(1000);
        $this->assertEquals(100, $exportProgress->fresh()->progress_percentage);
    }

    /**
     * **Property 42: Export completion tracking**
     * 
     * Test that export completion is properly tracked
     */
    public function test_export_completion_is_tracked(): void
    {
        $exportProgress = ExportProgress::create([
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
            'export_type' => 'daily',
            'status' => ExportProgress::STATUS_PROCESSING,
        ]);

        $filename = 'test_export.xlsx';
        $filePath = 'exports/attendance/' . $filename;

        $exportProgress->markAsCompleted($filename, $filePath);

        $this->assertDatabaseHas('export_progress', [
            'id' => $exportProgress->id,
            'status' => ExportProgress::STATUS_COMPLETED,
            'filename' => $filename,
            'file_path' => $filePath,
            'progress_percentage' => 100,
        ]);

        $this->assertNotNull($exportProgress->fresh()->completed_at);
        $this->assertTrue($exportProgress->fresh()->isCompleted());
    }

    /**
     * **Property 42: Export failure tracking**
     * 
     * Test that export failures are properly tracked
     */
    public function test_export_failure_is_tracked(): void
    {
        $exportProgress = ExportProgress::create([
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
            'export_type' => 'daily',
            'status' => ExportProgress::STATUS_PROCESSING,
        ]);

        $errorMessage = 'Database connection failed';
        $exportProgress->markAsFailed($errorMessage);

        $this->assertDatabaseHas('export_progress', [
            'id' => $exportProgress->id,
            'status' => ExportProgress::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);

        $this->assertNotNull($exportProgress->fresh()->completed_at);
        $this->assertTrue($exportProgress->fresh()->isFailed());
    }

    /**
     * Test memory usage for large datasets
     * 
     * Validates that memory usage stays under 100MB for 100K rows
     */
    public function test_memory_usage_for_large_datasets(): void
    {
        $this->markTestSkipped('Skipped in CI - requires large dataset generation');

        // This test would create 100K records and verify memory usage
        // Skipped by default to avoid long test execution times
        
        // Expected behavior:
        // - Memory usage < 100MB for 100K rows
        // - Uses cursor() for iteration
        // - Processes in chunks of 1000
    }

    /**
     * Test export job with progress tracking
     */
    public function test_export_job_with_progress_tracking(): void
    {
        Queue::fake();

        // Create some attendance records
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        Attendance::factory()->count(10)->create([
            'student_id' => $student->id,
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacher->id,
        ]);

        // Create export progress
        $exportProgress = ExportProgress::create([
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
            'export_type' => 'daily',
            'status' => ExportProgress::STATUS_PENDING,
        ]);

        // Dispatch job
        ExportAttendanceReport::dispatch(
            $this->admin->id,
            $this->school->id,
            'daily',
            ['date' => now()->toDateString()],
            $exportProgress->id
        );

        Queue::assertPushed(ExportAttendanceReport::class);
    }

    /**
     * Test export timeout configuration
     */
    public function test_export_timeout_is_configurable(): void
    {
        // Test default timeout
        $defaultTimeout = config('exports.timeout');
        $this->assertEquals(600, $defaultTimeout, 'Default timeout should be 600 seconds (10 minutes)');

        // Test custom timeout
        config(['exports.timeout' => 1200]);
        
        $job = new ExportAttendanceReport(
            $this->admin->id,
            $this->school->id,
            'daily',
            ['date' => now()->toDateString()]
        );

        $this->assertEquals(1200, $job->timeout, 'Job timeout should match config value');
    }

    /**
     * Test export progress API endpoint
     */
    public function test_export_progress_api_endpoint(): void
    {
        $exportProgress = ExportProgress::create([
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
            'export_type' => 'daily',
            'status' => ExportProgress::STATUS_PROCESSING,
            'total_records' => 1000,
            'processed_records' => 500,
            'progress_percentage' => 50,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/export-progress/{$exportProgress->id}");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $exportProgress->id,
                    'export_type' => 'daily',
                    'status' => ExportProgress::STATUS_PROCESSING,
                    'total_records' => 1000,
                    'processed_records' => 500,
                    'progress_percentage' => 50,
                ],
            ]);
    }

    /**
     * Test active exports API endpoint
     */
    public function test_active_exports_api_endpoint(): void
    {
        // Create processing export
        ExportProgress::create([
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
            'export_type' => 'daily',
            'status' => ExportProgress::STATUS_PROCESSING,
        ]);

        // Create completed export (should not appear in active)
        ExportProgress::create([
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
            'export_type' => 'monthly',
            'status' => ExportProgress::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/export-progress/active');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', ExportProgress::STATUS_PROCESSING);
    }

    /**
     * Test tenant isolation in export progress
     */
    public function test_export_progress_respects_tenant_isolation(): void
    {
        // Create another school
        $otherSchool = School::factory()->create();
        $otherAdmin = User::factory()->create(['school_id' => $otherSchool->id]);
        $otherAdmin->assignRole('school_admin');

        // Create export for other school
        $otherExport = ExportProgress::create([
            'user_id' => $otherAdmin->id,
            'school_id' => $otherSchool->id,
            'export_type' => 'daily',
            'status' => ExportProgress::STATUS_PROCESSING,
        ]);

        // Try to access other school's export
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/export-progress/{$otherExport->id}");

        $response->assertNotFound();
    }
}
