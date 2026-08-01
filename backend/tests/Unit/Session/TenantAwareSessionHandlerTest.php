<?php

namespace Tests\Unit\Session;

use PHPUnit\Framework\Attributes\Test;

use App\Models\User;
use App\Services\Session\DatabaseSessionHandler;
use App\Services\Session\TenantAwareSessionHandler;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

/**
 * Tenant-Aware Session Handler Unit Tests
 * 
 * Tests the multi-tenant session isolation and fallback mechanisms.
 * 
 * @author Redis HA Team
 * @version 1.0.0
 */
class TenantAwareSessionHandlerTest extends TestCase
{
    private $redisMock;
    private $fallbackMock;
    private $handler;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->redisMock = Mockery::mock('Redis');
        $this->fallbackMock = Mockery::mock(DatabaseSessionHandler::class);
        
        $this->handler = new TenantAwareSessionHandler(
            $this->redisMock,
            'session',
            7200,
            $this->fallbackMock
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }


    #[Test]
    public function it_builds_tenant_aware_session_key_for_authenticated_user()
    {
        // Create a user with school_id
        $user = new User();
        $user->id = 1;
        $user->school_id = 5;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_session_123';
        $expectedKey = 'session:tenant:5:session:test_session_123';
        
        $this->redisMock->shouldReceive('get')
            ->with($expectedKey)
            ->once()
            ->andReturn('session_data');
        
        $result = $this->handler->read($sessionId);
        
        $this->assertEquals('session_data', $result);
    }


    #[Test]
    public function it_uses_guest_tenant_for_unauthenticated_sessions()
    {
        Auth::shouldReceive('check')->andReturn(false);
        
        $sessionId = 'test_session_456';
        $expectedKey = 'session:tenant:guest:session:test_session_456';
        
        $this->redisMock->shouldReceive('get')
            ->with($expectedKey)
            ->once()
            ->andReturn('guest_session_data');
        
        $result = $this->handler->read($sessionId);
        
        $this->assertEquals('guest_session_data', $result);
    }


    #[Test]
    public function it_writes_session_data_with_ttl()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 10;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_session_789';
        $sessionData = 'encrypted_session_data';
        $expectedKey = 'session:tenant:10:session:test_session_789';
        
        $this->redisMock->shouldReceive('setex')
            ->with($expectedKey, 7200, $sessionData)
            ->once()
            ->andReturn(true);
        
        $result = $this->handler->write($sessionId, $sessionData);
        
        $this->assertTrue($result);
    }


    #[Test]
    public function it_destroys_session_data()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 15;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_session_delete';
        $expectedKey = 'session:tenant:15:session:test_session_delete';
        
        $this->redisMock->shouldReceive('del')
            ->with($expectedKey)
            ->once()
            ->andReturn(1);
        
        $result = $this->handler->destroy($sessionId);
        
        $this->assertTrue($result);
    }


    #[Test]
    public function it_falls_back_to_database_on_redis_read_failure()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 20;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_session_fallback';
        
        // Redis throws exception
        $this->redisMock->shouldReceive('get')
            ->andThrow(new \Exception('Redis connection failed'));
        
        // Fallback handler should be called
        $this->fallbackMock->shouldReceive('read')
            ->with($sessionId)
            ->once()
            ->andReturn('fallback_session_data');
        
        $result = $this->handler->read($sessionId);
        
        $this->assertEquals('fallback_session_data', $result);
    }


    #[Test]
    public function it_falls_back_to_database_on_redis_write_failure()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 25;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_session_write_fallback';
        $sessionData = 'test_data';
        
        // Redis throws exception
        $this->redisMock->shouldReceive('setex')
            ->andThrow(new \Exception('Redis connection failed'));
        
        // Fallback handler should be called
        $this->fallbackMock->shouldReceive('write')
            ->with($sessionId, $sessionData)
            ->once()
            ->andReturn(true);
        
        $result = $this->handler->write($sessionId, $sessionData);
        
        $this->assertTrue($result);
    }


    #[Test]
    public function it_returns_empty_string_when_session_not_found()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 30;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'nonexistent_session';
        
        $this->redisMock->shouldReceive('get')
            ->andReturn(null);
        
        $result = $this->handler->read($sessionId);
        
        $this->assertEquals('', $result);
    }


    #[Test]
    public function it_handles_open_and_close_operations()
    {
        $this->assertTrue($this->handler->open('', ''));
        $this->assertTrue($this->handler->close());
    }


    #[Test]
    public function it_handles_garbage_collection()
    {
        // Redis handles GC automatically via TTL
        $result = $this->handler->gc(7200);
        
        $this->assertEquals(0, $result);
    }

    /**
     * Test session continuity during Redis to database fallback
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_maintains_session_continuity_during_fallback()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 100;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_session_continuity';
        $sessionData = 'important_session_data';
        
        // Write to Redis successfully
        $this->redisMock->shouldReceive('setex')
            ->once()
            ->andReturn(true);
        
        $this->handler->write($sessionId, $sessionData);
        
        // Redis fails on read, fallback to database
        $this->redisMock->shouldReceive('get')
            ->once()
            ->andThrow(new \Exception('Redis connection lost'));
        
        $this->fallbackMock->shouldReceive('read')
            ->with($sessionId)
            ->once()
            ->andReturn($sessionData);
        
        // Session data should still be accessible
        $result = $this->handler->read($sessionId);
        $this->assertEquals($sessionData, $result);
    }

    /**
     * Test session data consistency between Redis and database
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_maintains_data_consistency_during_fallback_writes()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 200;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_consistency';
        $sessionData = 'consistent_data';
        
        // Redis write fails, should fallback to database
        $this->redisMock->shouldReceive('setex')
            ->once()
            ->andThrow(new \Exception('Redis write failed'));
        
        $this->fallbackMock->shouldReceive('write')
            ->with($sessionId, $sessionData)
            ->once()
            ->andReturn(true);
        
        // Write should succeed via fallback
        $result = $this->handler->write($sessionId, $sessionData);
        $this->assertTrue($result);
    }

    /**
     * Test tenant isolation is maintained during fallback
     * Validates: Requirements 2.2, 5.2
     * 
*/
    public function it_maintains_tenant_isolation_during_fallback()
    {
        // Tenant 1 session
        $user1 = new User();
        $user1->id = 1;
        $user1->school_id = 10;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user1);
        
        $sessionId1 = 'tenant1_session';
        $expectedKey1 = 'session:tenant:10:session:tenant1_session';
        
        $this->redisMock->shouldReceive('get')
            ->with($expectedKey1)
            ->once()
            ->andReturn('tenant1_data');
        
        $result1 = $this->handler->read($sessionId1);
        $this->assertEquals('tenant1_data', $result1);
        
        // Tenant 2 session - different key prefix
        $user2 = new User();
        $user2->id = 2;
        $user2->school_id = 20;
        
        // Reset Auth mock for second user
        Auth::clearResolvedInstances();
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user2);
        
        $sessionId2 = 'tenant2_session';
        $expectedKey2 = 'session:tenant:20:session:tenant2_session';
        
        $this->redisMock->shouldReceive('get')
            ->with($expectedKey2)
            ->once()
            ->andReturn('tenant2_data');
        
        $result2 = $this->handler->read($sessionId2);
        $this->assertEquals('tenant2_data', $result2);
        
        // Verify different tenants use different keys
        $this->assertNotEquals($expectedKey1, $expectedKey2);
    }

    /**
     * Test fallback handler is called for destroy operations
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_falls_back_to_database_on_redis_destroy_failure()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 50;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_session_destroy_fallback';
        
        // Redis throws exception on destroy
        $this->redisMock->shouldReceive('del')
            ->andThrow(new \Exception('Redis connection failed'));
        
        // Fallback handler should be called
        $this->fallbackMock->shouldReceive('destroy')
            ->with($sessionId)
            ->once()
            ->andReturn(true);
        
        $result = $this->handler->destroy($sessionId);
        
        $this->assertTrue($result);
    }

    /**
     * Test session data remains accessible after multiple fallback cycles
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_handles_multiple_fallback_cycles()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 75;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_multiple_fallbacks';
        $sessionData = 'persistent_data';
        
        // First write to Redis succeeds
        $this->redisMock->shouldReceive('setex')
            ->once()
            ->andReturn(true);
        
        $this->handler->write($sessionId, $sessionData);
        
        // Read from Redis fails, fallback to database
        $this->redisMock->shouldReceive('get')
            ->once()
            ->andThrow(new \Exception('Redis read failed'));
        
        $this->fallbackMock->shouldReceive('read')
            ->with($sessionId)
            ->once()
            ->andReturn($sessionData);
        
        $result1 = $this->handler->read($sessionId);
        $this->assertEquals($sessionData, $result1);
        
        // Write fails, fallback to database
        $this->redisMock->shouldReceive('setex')
            ->once()
            ->andThrow(new \Exception('Redis write failed'));
        
        $this->fallbackMock->shouldReceive('write')
            ->with($sessionId, $sessionData)
            ->once()
            ->andReturn(true);
        
        $result2 = $this->handler->write($sessionId, $sessionData);
        $this->assertTrue($result2);
    }

    /**
     * Test session handler without fallback returns false on Redis failure
     * Validates: Requirements 4.3
     * 
*/
    public function it_returns_false_when_no_fallback_handler_configured()
    {
        // Create handler without fallback
        $handlerNoFallback = new TenantAwareSessionHandler(
            $this->redisMock,
            'session',
            7200,
            null // No fallback handler
        );
        
        $user = new User();
        $user->id = 1;
        $user->school_id = 99;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_no_fallback';
        $sessionData = 'test_data';
        
        // Redis write fails and no fallback available
        $this->redisMock->shouldReceive('setex')
            ->andThrow(new \Exception('Redis failed'));
        
        $result = $handlerNoFallback->write($sessionId, $sessionData);
        
        $this->assertFalse($result);
    }

    /**
     * Test concurrent session operations during fallback
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_handles_concurrent_operations_during_fallback()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 150;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        // Simulate multiple session operations
        $sessions = [
            'session_1' => 'data_1',
            'session_2' => 'data_2',
            'session_3' => 'data_3',
        ];
        
        foreach ($sessions as $sessionId => $sessionData) {
            // Redis fails for all operations
            $this->redisMock->shouldReceive('setex')
                ->once()
                ->andThrow(new \Exception('Redis unavailable'));
            
            $this->fallbackMock->shouldReceive('write')
                ->with($sessionId, $sessionData)
                ->once()
                ->andReturn(true);
            
            $result = $this->handler->write($sessionId, $sessionData);
            $this->assertTrue($result);
        }
    }

    /**
     * Test session TTL is preserved during fallback
     * Validates: Requirements 2.2, 2.5
     * 
*/
    public function it_preserves_session_ttl_during_fallback()
    {
        $user = new User();
        $user->id = 1;
        $user->school_id = 175;
        
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        
        $sessionId = 'test_ttl_preservation';
        $sessionData = 'ttl_test_data';
        $expectedTtl = 7200;
        
        // Verify Redis setex is called with correct TTL
        $this->redisMock->shouldReceive('setex')
            ->with(Mockery::any(), $expectedTtl, $sessionData)
            ->once()
            ->andReturn(true);
        
        $this->handler->write($sessionId, $sessionData);
        
        $this->assertTrue(true); // If we get here, TTL was correct
    }
}
