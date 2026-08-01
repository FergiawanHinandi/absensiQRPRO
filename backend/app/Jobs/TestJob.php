<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Test job for integration testing
 * Used in Redis HA integration tests
 */
class TestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        // Simulate job processing
        if (isset($this->data['will_fail']) && $this->data['will_fail']) {
            throw new \Exception('Test job intentionally failed');
        }

        // Process job data
        logger('TestJob processed', $this->data);
    }
}
