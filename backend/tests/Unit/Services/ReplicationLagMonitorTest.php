<?php

namespace Tests\Unit\Services;

use App\Services\ReplicationLagMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReplicationLagMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        ReplicationLagMonitor::clearCache();
    }

    protected function tearDown(): void
    {
        ReplicationLagMonitor::clearCache();
        parent::tearDown();
    }

    /** @test */
    public function it_can_instantiate(): void
    {
        $monitor = app(ReplicationLagMonitor::class);
        $this->assertInstanceOf(ReplicationLagMonitor::class, $monitor);
    }
}
