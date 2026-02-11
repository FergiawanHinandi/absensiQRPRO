<?php

namespace Tests\Unit\Jobs;

use App\Jobs\TenantAwareJob;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * TenantAwareJob Base Class Tests
 * 
 * Tests the tenant context enforcement in queue jobs
 * 
 * @see App\Jobs\TenantAwareJob
 */
class TenantAwareJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test job can be created with valid school_id
     */
    public function test_job_can_be_created_with_valid_school_id(): void
    {
        $school = School::factory()->create();

        $job = new TestTenantAwareJob($school->id);

        $this->assertEquals($school->id, $job->getSchoolIdPublic());
    }

    /**
     * Test job throws exception with invalid school_id (zero)
     */
    public function test_job_throws_exception_with_zero_school_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid school_id: 0');

        new TestTenantAwareJob(0);
    }

    /**
     * Test job throws exception with negative school_id
     */
    public function test_job_throws_exception_with_negative_school_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid school_id: -1');

        new TestTenantAwareJob(-1);
    }

    /**
     * Test job throws exception with non-existent school_id
     */
    public function test_job_throws_exception_with_nonexistent_school_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('School with ID 99999 does not exist');

        new TestTenantAwareJob(99999);
    }

    /**
     * Test ensureTenantContext passes with correct school_id
     */
    public function test_ensure_tenant_context_passes_with_correct_school(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);

        $job = new TestTenantAwareJob($school->id);
        
        // Should not throw exception
        $job->ensureTenantContextPublic($user);

        $this->assertTrue(true); // If we get here, test passed
    }

    /**
     * Test ensureTenantContext throws exception with wrong school_id
     */
    public function test_ensure_tenant_context_throws_exception_with_wrong_school(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school2->id]);

        $job = new TestTenantAwareJob($school1->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant context violation');
        $this->expectExceptionMessage("belongs to school {$school2->id}");
        $this->expectExceptionMessage("but job is for school {$school1->id}");

        $job->ensureTenantContextPublic($user);
    }

    /**
     * Test ensureTenantContext throws exception with model without school_id
     */
    public function test_ensure_tenant_context_throws_exception_with_model_without_school_id(): void
    {
        $school = School::factory()->create();
        
        // Create a mock object without school_id
        $modelWithoutSchoolId = new class {
            public $id = 1;
            public $name = 'Test';
        };

        $job = new TestTenantAwareJob($school->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not have school_id property');

        $job->ensureTenantContextPublic($modelWithoutSchoolId);
    }

    /**
     * Test tags include tenant-aware and school_id
     */
    public function test_tags_include_tenant_aware_and_school_id(): void
    {
        $school = School::factory()->create();
        $job = new TestTenantAwareJob($school->id);

        $tags = $job->tags();

        $this->assertContains('tenant-aware', $tags);
        $this->assertContains("school:{$school->id}", $tags);
    }

    /**
     * Test multiple jobs with different schools are isolated
     */
    public function test_multiple_jobs_with_different_schools_are_isolated(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();

        $job1 = new TestTenantAwareJob($school1->id);
        $job2 = new TestTenantAwareJob($school2->id);

        $this->assertEquals($school1->id, $job1->getSchoolIdPublic());
        $this->assertEquals($school2->id, $job2->getSchoolIdPublic());
        $this->assertNotEquals($job1->getSchoolIdPublic(), $job2->getSchoolIdPublic());
    }
}

/**
 * Test implementation of TenantAwareJob for testing purposes
 */
class TestTenantAwareJob extends TenantAwareJob
{
    /**
     * Public accessor for getSchoolId (for testing)
     */
    public function getSchoolIdPublic(): int
    {
        return $this->getSchoolId();
    }

    /**
     * Public accessor for ensureTenantContext (for testing)
     */
    public function ensureTenantContextPublic($model): void
    {
        $this->ensureTenantContext($model);
    }

    /**
     * Handle method (required by ShouldQueue)
     */
    public function handle(): void
    {
        // Test job - no implementation needed
    }
}
