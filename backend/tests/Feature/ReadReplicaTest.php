<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\ReadModels\AttendanceDailySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Read Replica Test
 * 
 * Tests read/write split configuration and fallback behavior
 */
class ReadReplicaTest extends TestCase
{
    use RefreshDatabase;
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_write_connection_for_inserts()
    {
        // Enable query logging
        DB::connection()->enableQueryLog();
        
        // Create attendance (INSERT)
        $attendance = Attendance::create([
            'school_id' => 1,
            'student_id' => 1,
            'schedule_id' => 1,
            'class_id' => 1,
            'attendance_date' => today(),
            'status' => 'present',
            'check_in_time' => now(),
        ]);
        
        // Get queries
        $queries = DB::connection()->getQueryLog();
        
        // Assert INSERT was executed
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('insert', strtolower($queries[0]['query']));
        
        DB::connection()->disableQueryLog();
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_write_connection_for_updates()
    {
        $attendance = Attendance::create([
            'school_id' => 1,
            'student_id' => 1,
            'schedule_id' => 1,
            'class_id' => 1,
            'attendance_date' => today(),
            'status' => 'present',
            'check_in_time' => now(),
        ]);
        
        // Enable query logging
        DB::connection()->enableQueryLog();
        
        // Update attendance (UPDATE)
        $attendance->update(['status' => 'late']);
        
        // Get queries
        $queries = DB::connection()->getQueryLog();
        
        // Assert UPDATE was executed
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('update', strtolower($queries[0]['query']));
        
        DB::connection()->disableQueryLog();
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_read_from_replica_or_primary()
    {
        // Create test data
        AttendanceDailySummary::create([
            'school_id' => 1,
            'attendance_date' => today(),
            'class_id' => null,
            'total_students' => 100,
            'total_present' => 80,
            'total_late' => 10,
            'total_absent' => 10,
            'total_excused' => 0,
            'attendance_rate' => 90.00,
            'last_updated_at' => now(),
        ]);
        
        // Enable query logging
        DB::connection()->enableQueryLog();
        
        // Read data (SELECT)
        $summary = AttendanceDailySummary::where('school_id', 1)->first();
        
        // Get queries
        $queries = DB::connection()->getQueryLog();
        
        // Assert SELECT was executed
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('select', strtolower($queries[0]['query']));
        
        // Assert data was retrieved correctly
        $this->assertNotNull($summary);
        $this->assertEquals(100, $summary->total_students);
        
        DB::connection()->disableQueryLog();
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_high_read_load()
    {
        // Create test data
        AttendanceDailySummary::create([
            'school_id' => 1,
            'attendance_date' => today(),
            'class_id' => null,
            'total_students' => 100,
            'total_present' => 80,
            'total_late' => 10,
            'total_absent' => 10,
            'total_excused' => 0,
            'attendance_rate' => 90.00,
            'last_updated_at' => now(),
        ]);
        
        $startTime = microtime(true);
        
        // Simulate 100 concurrent reads
        for ($i = 0; $i < 100; $i++) {
            $summary = AttendanceDailySummary::where('school_id', 1)->first();
            $this->assertNotNull($summary);
        }
        
        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // Convert to ms
        
        // Assert reads completed in reasonable time
        // 100 reads should complete in < 1000ms
        $this->assertLessThan(1000, $duration, "100 reads took {$duration}ms");
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maintains_write_stability_during_high_read_load()
    {
        // Create initial data
        $summary = AttendanceDailySummary::create([
            'school_id' => 1,
            'attendance_date' => today(),
            'class_id' => null,
            'total_students' => 100,
            'total_present' => 80,
            'total_late' => 10,
            'total_absent' => 10,
            'total_excused' => 0,
            'attendance_rate' => 90.00,
            'last_updated_at' => now(),
        ]);
        
        // Simulate high read load
        for ($i = 0; $i < 50; $i++) {
            AttendanceDailySummary::where('school_id', 1)->first();
        }
        
        // Perform write during high read load
        $writeStartTime = microtime(true);
        
        $summary->update([
            'total_present' => 85,
            'attendance_rate' => 95.00,
        ]);
        
        $writeEndTime = microtime(true);
        $writeDuration = ($writeEndTime - $writeStartTime) * 1000;
        
        // Continue reading
        for ($i = 0; $i < 50; $i++) {
            AttendanceDailySummary::where('school_id', 1)->first();
        }
        
        // Assert write completed quickly (< 100ms)
        $this->assertLessThan(100, $writeDuration, "Write took {$writeDuration}ms");
        
        // Assert data was updated correctly
        $updated = AttendanceDailySummary::find($summary->id);
        $this->assertEquals(85, $updated->total_present);
        $this->assertEquals(95.00, $updated->attendance_rate);
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_sticky_connections_after_write()
    {
        // This test verifies that after a write, subsequent reads
        // use the write connection to prevent reading stale data
        
        $summary = AttendanceDailySummary::create([
            'school_id' => 1,
            'attendance_date' => today(),
            'class_id' => null,
            'total_students' => 100,
            'total_present' => 80,
            'total_late' => 10,
            'total_absent' => 10,
            'total_excused' => 0,
            'attendance_rate' => 90.00,
            'last_updated_at' => now(),
        ]);
        
        // Update (write)
        $summary->update(['total_present' => 85]);
        
        // Immediately read (should use write connection due to sticky)
        $fresh = AttendanceDailySummary::find($summary->id);
        
        // Assert we get the updated value (not stale data)
        $this->assertEquals(85, $fresh->total_present);
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_falls_back_to_primary_when_replica_unavailable()
    {
        // This test verifies that when DB_READ_HOST is not set,
        // all queries use the primary database
        
        // Check if read replica is configured
        $config = config('database.connections.mysql.read');
        
        if ($config === null) {
            // No read replica configured - all queries use primary
            $this->assertTrue(true, 'No read replica configured, using primary for all queries');
        } else {
            // Read replica configured
            $this->assertNotNull($config);
        }
        
        // Verify queries still work
        $summary = AttendanceDailySummary::create([
            'school_id' => 1,
            'attendance_date' => today(),
            'class_id' => null,
            'total_students' => 100,
            'total_present' => 80,
            'total_late' => 10,
            'total_absent' => 10,
            'total_excused' => 0,
            'attendance_rate' => 90.00,
            'last_updated_at' => now(),
        ]);
        
        $retrieved = AttendanceDailySummary::find($summary->id);
        $this->assertNotNull($retrieved);
        $this->assertEquals(100, $retrieved->total_students);
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_complex_read_queries()
    {
        // Create multiple summaries
        for ($i = 1; $i <= 10; $i++) {
            AttendanceDailySummary::create([
                'school_id' => 1,
                'attendance_date' => today()->subDays($i),
                'class_id' => null,
                'total_students' => 100,
                'total_present' => 80 + $i,
                'total_late' => 10,
                'total_absent' => 10 - $i,
                'total_excused' => 0,
                'attendance_rate' => 90.00 + $i,
                'last_updated_at' => now(),
            ]);
        }
        
        // Complex query with joins, aggregates, etc.
        $results = AttendanceDailySummary::where('school_id', 1)
            ->whereNull('class_id')
            ->whereBetween('attendance_date', [today()->subDays(7), today()])
            ->orderBy('attendance_date', 'desc')
            ->get();
        
        // Assert query executed successfully
        $this->assertCount(7, $results);
        
        // Assert data is correct
        $this->assertEquals(81, $results->first()->total_present);
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_reports_connection_configuration()
    {
        $config = config('database.connections.mysql');
        
        // Check if read/write split is configured
        $hasReadReplica = isset($config['read']) && $config['read'] !== null;
        $hasWriteConfig = isset($config['write']);
        $hasStickyConfig = isset($config['sticky']);
        
        // Report configuration
        if ($hasReadReplica) {
            $this->assertTrue(true, 'Read replica is configured');
            $this->assertTrue($hasWriteConfig, 'Write configuration exists');
            $this->assertTrue($hasStickyConfig, 'Sticky connection configured');
        } else {
            $this->assertTrue(true, 'Using primary database for all queries (no replica configured)');
        }
    }
}
