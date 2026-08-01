<?php

namespace Tests\Unit\Session;

use PHPUnit\Framework\Attributes\Test;

use App\Services\Session\DatabaseSessionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Database Session Handler Unit Tests
 * 
 * Tests the database-based session fallback storage.
 * 
 * @author Redis HA Team
 * @version 1.0.0
 */
class DatabaseSessionHandlerTest extends TestCase
{
    use RefreshDatabase;

    private $handler;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Run migrations to create sessions table
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_02_24_000001_create_sessions_table.php']);
        
        $this->handler = new DatabaseSessionHandler('sessions', 7200);
    }


    #[Test]
    public function it_writes_session_data_to_database()
    {
        $sessionId = 'test_session_db_write';
        $sessionData = 'encrypted_session_data';
        
        $result = $this->handler->write($sessionId, $sessionData);
        
        $this->assertTrue($result);
        
        // Verify data was written
        $session = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertNotNull($session);
        $this->assertEquals($sessionData, $session->payload);
    }


    #[Test]
    public function it_reads_session_data_from_database()
    {
        $sessionId = 'test_session_db_read';
        $sessionData = 'test_payload_data';
        
        // Insert test data
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'payload' => $sessionData,
            'last_activity' => time(),
        ]);
        
        $result = $this->handler->read($sessionId);
        
        $this->assertEquals($sessionData, $result);
    }


    #[Test]
    public function it_returns_empty_string_for_nonexistent_session()
    {
        $result = $this->handler->read('nonexistent_session');
        
        $this->assertEquals('', $result);
    }


    #[Test]
    public function it_returns_empty_string_for_expired_session()
    {
        $sessionId = 'expired_session';
        $sessionData = 'expired_data';
        
        // Insert expired session (last_activity is old)
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'payload' => $sessionData,
            'last_activity' => time() - 10000, // Expired
        ]);
        
        $result = $this->handler->read($sessionId);
        
        $this->assertEquals('', $result);
    }


    #[Test]
    public function it_updates_existing_session()
    {
        $sessionId = 'test_session_update';
        $initialData = 'initial_data';
        $updatedData = 'updated_data';
        
        // Write initial data
        $this->handler->write($sessionId, $initialData);
        
        // Update with new data
        $this->handler->write($sessionId, $updatedData);
        
        // Verify updated data
        $session = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertEquals($updatedData, $session->payload);
    }


    #[Test]
    public function it_destroys_session_from_database()
    {
        $sessionId = 'test_session_destroy';
        $sessionData = 'data_to_destroy';
        
        // Write session
        $this->handler->write($sessionId, $sessionData);
        
        // Verify it exists
        $this->assertNotNull(DB::table('sessions')->where('id', $sessionId)->first());
        
        // Destroy session
        $result = $this->handler->destroy($sessionId);
        
        $this->assertTrue($result);
        
        // Verify it's gone
        $this->assertNull(DB::table('sessions')->where('id', $sessionId)->first());
    }


    #[Test]
    public function it_performs_garbage_collection()
    {
        $currentTime = time();
        
        // Insert active session
        DB::table('sessions')->insert([
            'id' => 'active_session',
            'payload' => 'active_data',
            'last_activity' => $currentTime,
        ]);
        
        // Insert expired sessions
        DB::table('sessions')->insert([
            'id' => 'expired_session_1',
            'payload' => 'expired_data_1',
            'last_activity' => $currentTime - 10000,
        ]);
        
        DB::table('sessions')->insert([
            'id' => 'expired_session_2',
            'payload' => 'expired_data_2',
            'last_activity' => $currentTime - 15000,
        ]);
        
        // Run garbage collection with 7200 second lifetime
        $deleted = $this->handler->gc(7200);
        
        // Should delete 2 expired sessions
        $this->assertEquals(2, $deleted);
        
        // Verify active session still exists
        $this->assertNotNull(DB::table('sessions')->where('id', 'active_session')->first());
        
        // Verify expired sessions are gone
        $this->assertNull(DB::table('sessions')->where('id', 'expired_session_1')->first());
        $this->assertNull(DB::table('sessions')->where('id', 'expired_session_2')->first());
    }


    #[Test]
    public function it_handles_open_and_close_operations()
    {
        $this->assertTrue($this->handler->open('', ''));
        $this->assertTrue($this->handler->close());
    }

    /**
     * Test session data consistency after multiple writes
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_maintains_data_consistency_across_multiple_writes()
    {
        $sessionId = 'test_consistency_writes';
        
        $writes = [
            'first_write_data',
            'second_write_data',
            'third_write_data',
        ];
        
        foreach ($writes as $data) {
            $this->handler->write($sessionId, $data);
            
            // Verify each write is persisted correctly
            $result = $this->handler->read($sessionId);
            $this->assertEquals($data, $result);
        }
        
        // Verify final state
        $session = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertEquals('third_write_data', $session->payload);
    }

    /**
     * Test concurrent session writes for different sessions
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_handles_concurrent_session_writes()
    {
        $sessions = [
            'session_a' => 'data_a',
            'session_b' => 'data_b',
            'session_c' => 'data_c',
        ];
        
        // Write all sessions
        foreach ($sessions as $sessionId => $data) {
            $this->handler->write($sessionId, $data);
        }
        
        // Verify all sessions are stored correctly
        foreach ($sessions as $sessionId => $expectedData) {
            $result = $this->handler->read($sessionId);
            $this->assertEquals($expectedData, $result);
        }
        
        // Verify count
        $count = DB::table('sessions')->count();
        $this->assertEquals(3, $count);
    }

    /**
     * Test session isolation between different session IDs
     * Validates: Requirements 2.2, 5.2
     * 
*/
    public function it_maintains_session_isolation()
    {
        $session1Id = 'isolated_session_1';
        $session1Data = 'session_1_data';
        
        $session2Id = 'isolated_session_2';
        $session2Data = 'session_2_data';
        
        // Write both sessions
        $this->handler->write($session1Id, $session1Data);
        $this->handler->write($session2Id, $session2Data);
        
        // Verify each session has its own data
        $this->assertEquals($session1Data, $this->handler->read($session1Id));
        $this->assertEquals($session2Data, $this->handler->read($session2Id));
        
        // Destroy one session
        $this->handler->destroy($session1Id);
        
        // Verify only the destroyed session is gone
        $this->assertEquals('', $this->handler->read($session1Id));
        $this->assertEquals($session2Data, $this->handler->read($session2Id));
    }

    /**
     * Test garbage collection with mixed active and expired sessions
     * Validates: Requirements 2.5, 4.3
     * 
*/
    public function it_performs_selective_garbage_collection()
    {
        $currentTime = time();
        $ttl = 3600; // 1 hour
        
        // Insert sessions with various ages
        $sessions = [
            ['id' => 'active_1', 'last_activity' => $currentTime, 'should_survive' => true],
            ['id' => 'active_2', 'last_activity' => $currentTime - 1800, 'should_survive' => true], // 30 min old
            ['id' => 'expired_1', 'last_activity' => $currentTime - 7200, 'should_survive' => false], // 2 hours old
            ['id' => 'expired_2', 'last_activity' => $currentTime - 10800, 'should_survive' => false], // 3 hours old
            ['id' => 'borderline', 'last_activity' => $currentTime - 3599, 'should_survive' => true], // Just under TTL
        ];
        
        foreach ($sessions as $session) {
            DB::table('sessions')->insert([
                'id' => $session['id'],
                'payload' => 'test_data',
                'last_activity' => $session['last_activity'],
            ]);
        }
        
        // Run garbage collection
        $deleted = $this->handler->gc($ttl);
        
        // Should delete 2 expired sessions
        $this->assertEquals(2, $deleted);
        
        // Verify correct sessions survived
        foreach ($sessions as $session) {
            $exists = DB::table('sessions')->where('id', $session['id'])->exists();
            $this->assertEquals($session['should_survive'], $exists, 
                "Session {$session['id']} survival state incorrect");
        }
    }

    /**
     * Test session write updates last_activity timestamp
     * Validates: Requirements 2.2, 2.5
     * 
*/
    public function it_updates_last_activity_on_write()
    {
        $sessionId = 'test_activity_update';
        $sessionData = 'test_data';
        
        // First write
        $this->handler->write($sessionId, $sessionData);
        $session1 = DB::table('sessions')->where('id', $sessionId)->first();
        $firstActivity = $session1->last_activity;
        
        // Wait a moment
        sleep(1);
        
        // Second write
        $this->handler->write($sessionId, $sessionData);
        $session2 = DB::table('sessions')->where('id', $sessionId)->first();
        $secondActivity = $session2->last_activity;
        
        // Verify last_activity was updated
        $this->assertGreaterThan($firstActivity, $secondActivity);
    }

    /**
     * Test reading expired session returns empty string
     * Validates: Requirements 2.5, 4.3
     * 
*/
    public function it_treats_expired_sessions_as_nonexistent()
    {
        $sessionId = 'expired_read_test';
        $sessionData = 'expired_data';
        $ttl = 3600;
        
        // Insert expired session
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'payload' => $sessionData,
            'last_activity' => time() - ($ttl + 100), // Expired
        ]);
        
        // Create handler with matching TTL
        $handler = new DatabaseSessionHandler('sessions', $ttl);
        
        // Reading expired session should return empty string
        $result = $handler->read($sessionId);
        $this->assertEquals('', $result);
    }

    /**
     * Test session persistence across handler instances
     * Validates: Requirements 2.2, 2.3
     * 
*/
    public function it_persists_sessions_across_handler_instances()
    {
        $sessionId = 'persistent_session';
        $sessionData = 'persistent_data';
        
        // Write with first handler instance
        $handler1 = new DatabaseSessionHandler('sessions', 7200);
        $handler1->write($sessionId, $sessionData);
        
        // Read with second handler instance
        $handler2 = new DatabaseSessionHandler('sessions', 7200);
        $result = $handler2->read($sessionId);
        
        $this->assertEquals($sessionData, $result);
    }

    /**
     * Test destroy operation is idempotent
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_handles_destroying_nonexistent_session()
    {
        $sessionId = 'nonexistent_destroy';
        
        // Destroying non-existent session should not throw error
        $result = $this->handler->destroy($sessionId);
        
        $this->assertTrue($result);
    }

    /**
     * Test garbage collection with no expired sessions
     * Validates: Requirements 2.5, 4.3
     * 
*/
    public function it_handles_garbage_collection_with_no_expired_sessions()
    {
        $currentTime = time();
        
        // Insert only active sessions
        DB::table('sessions')->insert([
            ['id' => 'active_1', 'payload' => 'data_1', 'last_activity' => $currentTime],
            ['id' => 'active_2', 'payload' => 'data_2', 'last_activity' => $currentTime],
        ]);
        
        // Run garbage collection
        $deleted = $this->handler->gc(7200);
        
        // Should delete nothing
        $this->assertEquals(0, $deleted);
        
        // Verify all sessions still exist
        $this->assertEquals(2, DB::table('sessions')->count());
    }

    /**
     * Test large session data handling
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_handles_large_session_data()
    {
        $sessionId = 'large_data_session';
        // Create large session data (10KB)
        $largeData = str_repeat('x', 10240);
        
        $this->handler->write($sessionId, $largeData);
        
        $result = $this->handler->read($sessionId);
        
        $this->assertEquals($largeData, $result);
        $this->assertEquals(10240, strlen($result));
    }
}
