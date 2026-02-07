<?php

namespace Tests\Feature\Console;

use App\Services\DisasterRecoveryTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DisasterRecoveryWorkflowTest extends TestCase
{
    /**
     * Test command exists and has correct signature
     */
    public function test_command_is_registered(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('dr:test-workflow', $commands);
    }

    /**
     * Test command help output
     */
    public function test_command_has_help(): void
    {
        $this->artisan('dr:test-workflow', ['--help' => true])
            ->assertSuccessful();
    }

    /**
     * Test service can be instantiated
     */
    public function test_service_can_be_instantiated(): void
    {
        $service = new DisasterRecoveryTestService();
        $this->assertNotNull($service);
        $this->assertNotEmpty($service->getStagingDbName());
    }

    /**
     * Test staging database name format
     */
    public function test_staging_db_name_format(): void
    {
        $service = new DisasterRecoveryTestService();
        $dbName = $service->getStagingDbName();
        
        $this->assertStringStartsWith('absensi_staging_dr_', $dbName);
        $this->assertMatchesRegularExpression('/^absensi_staging_dr_\d{8}_\d{6}$/', $dbName);
    }

    /**
     * Test JSON report generation
     */
    public function test_json_report_format(): void
    {
        $service = new DisasterRecoveryTestService();
        
        // Reflect to set some results manually for testing
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('results');
        $property->setAccessible(true);
        $property->setValue($service, [
            'test_id' => 'dr_test123',
            'overall_status' => 'PASS',
            'integrity_checks' => [
                ['name' => 'test_check', 'status' => 'PASS', 'details' => []],
            ],
        ]);

        $json = $service->generateJsonReport();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('dr_test123', $decoded['test_id']);
        $this->assertEquals('PASS', $decoded['overall_status']);
    }

    /**
     * Test summary generation
     */
    public function test_summary_generation(): void
    {
        $service = new DisasterRecoveryTestService();
        
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('results');
        $property->setAccessible(true);
        $property->setValue($service, [
            'overall_status' => 'PASS',
            'duration_seconds' => 45.5,
            'integrity_checks' => [
                ['name' => 'check1', 'status' => 'PASS', 'details' => []],
                ['name' => 'check2', 'status' => 'PASS', 'details' => []],
                ['name' => 'check3', 'status' => 'WARN', 'details' => []],
            ],
        ]);

        $summary = $service->generateSummary();

        $this->assertStringContainsString('✅', $summary);
        $this->assertStringContainsString('PASS', $summary);
        $this->assertStringContainsString('45.5s', $summary);
        $this->assertStringContainsString('2 passed', $summary);
        $this->assertStringContainsString('1 warnings', $summary);
    }

    /**
     * Test summary with failures
     */
    public function test_summary_with_failures(): void
    {
        $service = new DisasterRecoveryTestService();
        
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('results');
        $property->setAccessible(true);
        $property->setValue($service, [
            'overall_status' => 'FAIL',
            'duration_seconds' => 30,
            'integrity_checks' => [
                ['name' => 'check1', 'status' => 'PASS', 'details' => []],
                ['name' => 'check2', 'status' => 'FAIL', 'details' => []],
            ],
        ]);

        $summary = $service->generateSummary();

        $this->assertStringContainsString('FAIL', $summary);
        $this->assertStringContainsString('1 failed', $summary);
    }

    /**
     * Test command with JSON output option
     */
    public function test_command_json_output_option(): void
    {
        // This test checks the command accepts the option
        // Full integration test would require backup infrastructure
        $this->artisan('dr:test-workflow', ['--help' => true])
            ->expectsOutputToContain('--json');
    }

    /**
     * Test command with notify option
     */
    public function test_command_notify_option(): void
    {
        $this->artisan('dr:test-workflow', ['--help' => true])
            ->expectsOutputToContain('--notify');
    }

    /**
     * Test command with save-report option
     */
    public function test_command_save_report_option(): void
    {
        $this->artisan('dr:test-workflow', ['--help' => true])
            ->expectsOutputToContain('--save-report');
    }
}
