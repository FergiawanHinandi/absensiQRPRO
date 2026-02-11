<?php

namespace Tests\Feature;

use App\Infrastructure\CircuitBreaker\CircuitBreakerOpenException;
use App\Infrastructure\CircuitBreaker\RedisCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Circuit Breaker Test
 * 
 * Tests the circuit breaker pattern implementation for Redis
 */
class CircuitBreakerTest extends TestCase
{
    use RefreshDatabase;
    
    private RedisCircuitBreaker $circuitBreaker;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create circuit breaker with low thresholds for testing
        $this->circuitBreaker = new RedisCircuitBreaker(
            failureThreshold: 3,
            retryTimeoutSeconds: 2,
            successThreshold: 2
        );
        
        // Reset circuit state
        Cache::store('file')->forget('circuit_breaker:redis:state');
        Cache::store('file')->forget('circuit_breaker:redis:failure_count');
        Cache::store('file')->forget('circuit_breaker:redis:last_failure');
    }
    
    /** @test */
    public function it_starts_in_closed_state()
    {
        $status = $this->circuitBreaker->getStatus();
        
        $this->assertEquals('closed', $status['state']);
        $this->assertTrue($status['is_available']);
    }
    
    /** @test */
    public function it_executes_operation_successfully_in_closed_state()
    {
        $result = $this->circuitBreaker->execute(function() {
            return 'success';
        });
        
        $this->assertEquals('success', $result);
        $this->assertTrue($this->circuitBreaker->isAvailable());
    }
    
    /** @test */
    public function it_opens_circuit_after_threshold_failures()
    {
        // Simulate 3 failures (threshold)
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute(function() {
                    throw new \Exception('Redis connection failed');
                });
            } catch (\Exception $e) {
                // Expected
            }
        }
        
        $status = $this->circuitBreaker->getStatus();
        
        $this->assertEquals('open', $status['state']);
        $this->assertFalse($status['is_available']);
        $this->assertEquals(3, $status['failure_count']);
    }
    
    /** @test */
    public function it_fails_fast_when_circuit_is_open()
    {
        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute(function() {
                    throw new \Exception('Redis failed');
                });
            } catch (\Exception $e) {}
        }
        
        // Now circuit should be open, next call should fail fast
        $this->expectException(CircuitBreakerOpenException::class);
        
        $this->circuitBreaker->execute(function() {
            return 'should not execute';
        });
    }
    
    /** @test */
    public function it_uses_fallback_when_circuit_is_open()
    {
        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute(function() {
                    throw new \Exception('Redis failed');
                });
            } catch (\Exception $e) {}
        }
        
        // Execute with fallback
        $result = $this->circuitBreaker->execute(
            operation: function() {
                return 'should not execute';
            },
            fallback: function() {
                return 'fallback value';
            }
        );
        
        $this->assertEquals('fallback value', $result);
    }
    
    /** @test */
    public function it_transitions_to_half_open_after_timeout()
    {
        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute(function() {
                    throw new \Exception('Redis failed');
                });
            } catch (\Exception $e) {}
        }
        
        $this->assertEquals('open', $this->circuitBreaker->getStatus()['state']);
        
        // Wait for retry timeout (2 seconds)
        sleep(3);
        
        // Next execution should transition to HALF_OPEN
        try {
            $this->circuitBreaker->execute(function() {
                return 'test';
            });
        } catch (\Exception $e) {}
        
        $status = $this->circuitBreaker->getStatus();
        
        // Should be either HALF_OPEN or CLOSED (if test succeeded)
        $this->assertContains($status['state'], ['half_open', 'closed']);
    }
    
    /** @test */
    public function it_closes_circuit_after_successful_half_open_tests()
    {
        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute(function() {
                    throw new \Exception('Redis failed');
                });
            } catch (\Exception $e) {}
        }
        
        // Wait for retry timeout
        sleep(3);
        
        // Execute 2 successful operations (success threshold)
        for ($i = 0; $i < 2; $i++) {
            $this->circuitBreaker->execute(function() {
                return 'success';
            });
        }
        
        $status = $this->circuitBreaker->getStatus();
        
        $this->assertEquals('closed', $status['state']);
        $this->assertTrue($status['is_available']);
    }
    
    /** @test */
    public function it_reopens_circuit_if_half_open_test_fails()
    {
        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute(function() {
                    throw new \Exception('Redis failed');
                });
            } catch (\Exception $e) {}
        }
        
        // Wait for retry timeout
        sleep(3);
        
        // Fail the HALF_OPEN test
        try {
            $this->circuitBreaker->execute(function() {
                throw new \Exception('Still failing');
            });
        } catch (\Exception $e) {}
        
        $status = $this->circuitBreaker->getStatus();
        
        $this->assertEquals('open', $status['state']);
    }
    
    /** @test */
    public function it_resets_failure_count_on_success()
    {
        // Cause 2 failures (below threshold)
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->circuitBreaker->execute(function() {
                    throw new \Exception('Failure');
                });
            } catch (\Exception $e) {}
        }
        
        $this->assertEquals(2, $this->circuitBreaker->getStatus()['failure_count']);
        
        // Successful operation should reset count
        $this->circuitBreaker->execute(function() {
            return 'success';
        });
        
        $this->assertEquals(0, $this->circuitBreaker->getStatus()['failure_count']);
    }
    
    /** @test */
    public function it_can_be_manually_reset()
    {
        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute(function() {
                    throw new \Exception('Redis failed');
                });
            } catch (\Exception $e) {}
        }
        
        $this->assertEquals('open', $this->circuitBreaker->getStatus()['state']);
        
        // Manual reset
        $this->circuitBreaker->reset();
        
        $status = $this->circuitBreaker->getStatus();
        
        $this->assertEquals('closed', $status['state']);
        $this->assertEquals(0, $status['failure_count']);
    }
}
