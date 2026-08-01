<?php

namespace Tests\Feature\Redis\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Test Job Fixture
 * 
 * Simple job class used for testing queue functionality.
 * Stores arbitrary data for verification during tests.
 */
class TestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Job data
     * 
     * @var array
     */
    private array $data;

    /**
     * Create a new job instance.
     *
     * @param array $data Job data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        // This is a test job, so it doesn't need to do anything
        // In real tests, we just verify the job is queued and preserved
    }

    /**
     * Get the job data
     * 
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get the job ID
     * 
     * @return string|null
     */
    public function getJobId(): ?string
    {
        return $this->data['id'] ?? null;
    }
}
