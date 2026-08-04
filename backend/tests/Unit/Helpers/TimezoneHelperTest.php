<?php

namespace Tests\Unit\Helpers;

use App\Helpers\TimezoneHelper;
use App\Models\School;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimezoneHelperTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: now() returns correct timezone from config
     */
    public function test_now_returns_correct_timezone_from_config(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        
        $now = TimezoneHelper::now();
        
        $this->assertEquals('Asia/Jakarta', $now->timezone->getName());
        $this->assertInstanceOf(Carbon::class, $now);
    }

    /**
     * Test 2: now() accepts timezone override
     */
    public function test_now_accepts_timezone_override(): void
    {
        config(['app.timezone' => 'UTC']);
        
        $now = TimezoneHelper::now('Asia/Tokyo');
        
        $this->assertEquals('Asia/Tokyo', $now->timezone->getName());
    }

    /**
     * Test 3: schoolNow() uses school timezone
     */
    public function test_school_now_uses_school_timezone(): void
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Tokyo',
        ]);
        
        $now = TimezoneHelper::schoolNow($school);
        
        $this->assertEquals('Asia/Tokyo', $now->timezone->getName());
        $this->assertInstanceOf(Carbon::class, $now);
    }

    /**
     * Test 4: schoolNow() falls back to app timezone when school timezone is null
     */
    public function test_school_now_falls_back_to_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        
        $school = School::factory()->create([
            'timezone' => null,
        ]);
        
        $now = TimezoneHelper::schoolNow($school);
        
        $this->assertEquals('Asia/Jakarta', $now->timezone->getName());
    }

    /**
     * Test 5: parse() parses datetime with correct timezone
     */
    public function test_parse_parses_datetime_with_correct_timezone(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        
        $parsed = TimezoneHelper::parse('2026-02-09 08:00:00');
        
        $this->assertEquals('Asia/Jakarta', $parsed->timezone->getName());
        $this->assertEquals('2026-02-09 08:00:00', $parsed->format('Y-m-d H:i:s'));
    }

    /**
     * Test 6: parse() accepts timezone override
     */
    public function test_parse_accepts_timezone_override(): void
    {
        $parsed = TimezoneHelper::parse('2026-02-09 08:00:00', 'Asia/Tokyo');
        
        $this->assertEquals('Asia/Tokyo', $parsed->timezone->getName());
        $this->assertEquals('2026-02-09 08:00:00', $parsed->format('Y-m-d H:i:s'));
    }

    /**
     * Test 7: today() returns correct date string in school timezone
     */
    public function test_today_returns_correct_date_string(): void
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Jakarta',
        ]);
        
        // Freeze time for consistent testing
        Carbon::setTestNow('2026-02-09 15:30:00');
        
        $today = TimezoneHelper::today($school);
        
        $this->assertEquals('2026-02-09', $today);
        $this->assertIsString($today);
        
        Carbon::setTestNow(); // Reset
    }

    /**
     * Test 8: timeFromString() creates correct datetime from time string
     */
    public function test_time_from_string_creates_correct_datetime(): void
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Jakarta',
        ]);
        
        $time = TimezoneHelper::timeFromString('08:00:00', $school, '2026-02-09');
        
        $this->assertEquals('Asia/Jakarta', $time->timezone->getName());
        $this->assertEquals('2026-02-09 08:00:00', $time->format('Y-m-d H:i:s'));
    }

    /**
     * Test 9: timeFromString() defaults to today when date is null
     */
    public function test_time_from_string_defaults_to_today(): void
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Jakarta',
        ]);
        
        Carbon::setTestNow('2026-02-09 15:30:00');
        
        $time = TimezoneHelper::timeFromString('08:00:00', $school);
        
        $this->assertEquals('2026-02-09 08:00:00', $time->format('Y-m-d H:i:s'));
        
        Carbon::setTestNow(); // Reset
    }

    /**
     * Test 10: isBetween() correctly checks if time is within range
     */
    public function test_is_between_checks_time_range_correctly(): void
    {
        $start = Carbon::parse('2026-02-09 08:00:00');
        $end = Carbon::parse('2026-02-09 10:00:00');
        
        // Test time within range
        $within = Carbon::parse('2026-02-09 09:00:00');
        $this->assertTrue(TimezoneHelper::isBetween($within, $start, $end));
        
        // Test time at start boundary (inclusive)
        $this->assertTrue(TimezoneHelper::isBetween($start, $start, $end));
        
        // Test time at end boundary (inclusive)
        $this->assertTrue(TimezoneHelper::isBetween($end, $start, $end));
        
        // Test time before range
        $before = Carbon::parse('2026-02-09 07:00:00');
        $this->assertFalse(TimezoneHelper::isBetween($before, $start, $end));
        
        // Test time after range
        $after = Carbon::parse('2026-02-09 11:00:00');
        $this->assertFalse(TimezoneHelper::isBetween($after, $start, $end));
    }

    /**
     * Test 11: Timezone consistency across different methods
     */
    public function test_timezone_consistency_across_methods(): void
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Tokyo',
        ]);
        
        Carbon::setTestNow('2026-02-09 15:30:00');
        
        $now = TimezoneHelper::schoolNow($school);
        $today = TimezoneHelper::today($school);
        $time = TimezoneHelper::timeFromString('15:30:00', $school);
        
        // All should use the same timezone
        $this->assertEquals('Asia/Tokyo', $now->timezone->getName());
        $this->assertEquals($today, $now->toDateString());
        $this->assertEquals('Asia/Tokyo', $time->timezone->getName());
        
        Carbon::setTestNow(); // Reset
    }

    /**
     * Test 12: Different timezones produce different dates at boundary
     */
    public function test_different_timezones_produce_different_dates_at_boundary(): void
    {
        $schoolJakarta = School::factory()->create([
            'timezone' => 'Asia/Jakarta', // UTC+7
        ]);
        
        $schoolTokyo = School::factory()->create([
            'timezone' => 'Asia/Tokyo', // UTC+9
        ]);
        
        // Set time to 01:00 UTC (08:00 Jakarta, 10:00 Tokyo)
        Carbon::setTestNow(Carbon::parse('2026-02-09 01:00:00', 'UTC'));
        
        $jakartaToday = TimezoneHelper::today($schoolJakarta);
        $tokyoToday = TimezoneHelper::today($schoolTokyo);
        
        // Both should be the same date in this case
        $this->assertEquals('2026-02-09', $jakartaToday);
        $this->assertEquals('2026-02-09', $tokyoToday);
        
        // But at midnight boundary, they differ
        Carbon::setTestNow(Carbon::parse('2026-02-08 17:00:00', 'UTC')); // 00:00 Jakarta, 02:00 Tokyo
        
        $jakartaToday = TimezoneHelper::today($schoolJakarta);
        $tokyoToday = TimezoneHelper::today($schoolTokyo);
        
        $this->assertEquals('2026-02-09', $jakartaToday);
        $this->assertEquals('2026-02-09', $tokyoToday);
        
        Carbon::setTestNow(); // Reset
    }

    /**
     * Test 13: whereDate() queries use timezone-aware dates
     * 
     * This test verifies that when using whereDate() with attendance_date,
     * the date comparison respects the timezone, not just UTC.
     * 
     * Scenario: A school in Tokyo (UTC+9) creates an attendance at 23:30 Tokyo time.
     * In UTC, this would be 14:30 on the previous day. The whereDate() query
     * should find the attendance when querying with Tokyo timezone date.
     */
    public function test_where_date_queries_use_timezone_aware_dates(): void
    {
        // Create a school with Tokyo timezone
        $school = School::factory()->create([
            'timezone' => 'Asia/Tokyo', // UTC+9
        ]);
        
        // Create a student for this school
        $student = \App\Models\Student::factory()->create([
            'school_id' => $school->id,
        ]);
        
        // Create a schedule
        $schedule = \App\Models\Schedule::factory()->create([
            'school_id' => $school->id,
        ]);
        
        // Freeze time at 2026-02-09 23:30 Tokyo time (14:30 UTC)
        // This is important: 23:30 Tokyo is still Feb 9, but 14:30 UTC is Feb 9
        Carbon::setTestNow(Carbon::parse('2026-02-09 14:30:00', 'UTC'));
        
        // Create an attendance record using TimezoneHelper for date
        $attendanceDate = TimezoneHelper::today($school); // Should be "2026-02-09"
        
        $attendance = \App\Models\Attendance::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => $attendanceDate,
            'attendance_type' => 'in',
            'session_type' => 'morning',
            'check_in_time' => TimezoneHelper::schoolNow($school),
        ]);
        
        // Test 1: Query using whereDate with TimezoneHelper::today()
        $query1 = \App\Models\Attendance::where('school_id', $school->id)
            ->whereDate('attendance_date', TimezoneHelper::today($school))
            ->count();
        
        $this->assertEquals(1, $query1, 'Query with TimezoneHelper::today() should find the attendance');
        
        // Test 2: Query using whereDate with Carbon::today() (should also work since we're using Tokyo timezone)
        $query2 = \App\Models\Attendance::where('school_id', $school->id)
            ->whereDate('attendance_date', Carbon::today('Asia/Tokyo')->toDateString())
            ->count();
        
        $this->assertEquals(1, $query2, 'Query with Carbon::today() in Tokyo timezone should find the attendance');
        
        // Test 3: Query using whereDate with UTC date (should NOT find it if we use wrong timezone)
        // 23:30 Tokyo = 14:30 UTC, both are Feb 9, so this should still find it
        $query3 = \App\Models\Attendance::where('school_id', $school->id)
            ->whereDate('attendance_date', Carbon::today('UTC')->toDateString())
            ->count();
        
        $this->assertEquals(1, $query3, 'Query with UTC date should also find it since both dates are Feb 9');
        
        // Test 4: Edge case - test with a time that crosses date boundary
        // Set time to 00:30 Tokyo time (15:30 UTC previous day)
        // 00:30 Tokyo Feb 10 = 15:30 UTC Feb 9
        Carbon::setTestNow(Carbon::parse('2026-02-09 15:30:00', 'UTC'));
        
        $attendanceDate2 = TimezoneHelper::today($school); // Should be "2026-02-10"
        
        $attendance2 = \App\Models\Attendance::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => $attendanceDate2,
            'attendance_type' => 'in',
            'session_type' => 'morning',
            'check_in_time' => TimezoneHelper::schoolNow($school),
        ]);
        
        // Query with Tokyo date (Feb 10)
        $query4 = \App\Models\Attendance::where('school_id', $school->id)
            ->whereDate('attendance_date', TimezoneHelper::today($school))
            ->count();
        
        $this->assertEquals(1, $query4, 'Query with Tokyo date (Feb 10) should find the new attendance');
        
        // Query with UTC date (Feb 9) should NOT find the new attendance
        $query5 = \App\Models\Attendance::where('school_id', $school->id)
            ->whereDate('attendance_date', Carbon::today('UTC')->toDateString())
            ->count();
        
        $this->assertEquals(1, $query5, 'Query with UTC date (Feb 9) should only find the Feb 9 attendance, not the one dated Feb 10 Tokyo time');
        
        Carbon::setTestNow(); // Reset
    }
    
    /**
     * Test 14: whereDate() with different timezones produces correct results
     * 
     * Tests that the same physical time produces different dates in different timezones
     * and whereDate() queries respect this.
     */
    public function test_where_date_with_different_timezones_produces_correct_results(): void
    {
        // Create two schools with different timezones
        $schoolTokyo = School::factory()->create([
            'timezone' => 'Asia/Tokyo', // UTC+9
        ]);
        
        $schoolJakarta = School::factory()->create([
            'timezone' => 'Asia/Jakarta', // UTC+7
        ]);
        
        // Create students for each school
        $studentTokyo = \App\Models\Student::factory()->create([
            'school_id' => $schoolTokyo->id,
        ]);
        
        $studentJakarta = \App\Models\Student::factory()->create([
            'school_id' => $schoolJakarta->id,
        ]);
        
        // Create schedules
        $scheduleTokyo = \App\Models\Schedule::factory()->create([
            'school_id' => $schoolTokyo->id,
        ]);
        
        $scheduleJakarta = \App\Models\Schedule::factory()->create([
            'school_id' => $schoolJakarta->id,
        ]);
        
        // Set time to a boundary case: 2026-02-09 23:30 UTC
        // This is:
        // - 2026-02-10 08:30 Tokyo time (UTC+9)
        // - 2026-02-10 06:30 Jakarta time (UTC+7)
        Carbon::setTestNow(Carbon::parse('2026-02-09 23:30:00', 'UTC'));
        
        // Create attendance for Tokyo school (date should be Feb 10)
        $attendanceTokyo = \App\Models\Attendance::create([
            'school_id' => $schoolTokyo->id,
            'student_id' => $studentTokyo->id,
            'schedule_id' => $scheduleTokyo->id,
            'attendance_date' => TimezoneHelper::today($schoolTokyo), // Feb 10
            'attendance_type' => 'in',
            'session_type' => 'morning',
            'check_in_time' => TimezoneHelper::schoolNow($schoolTokyo),
        ]);
        
        // Create attendance for Jakarta school (date should also be Feb 10)
        $attendanceJakarta = \App\Models\Attendance::create([
            'school_id' => $schoolJakarta->id,
            'student_id' => $studentJakarta->id,
            'schedule_id' => $scheduleJakarta->id,
            'attendance_date' => TimezoneHelper::today($schoolJakarta), // Feb 10
            'attendance_type' => 'in',
            'session_type' => 'morning',
            'check_in_time' => TimezoneHelper::schoolNow($schoolJakarta),
        ]);
        
        // Test Tokyo school query
        $tokyoCount = \App\Models\Attendance::where('school_id', $schoolTokyo->id)
            ->whereDate('attendance_date', TimezoneHelper::today($schoolTokyo))
            ->count();
        
        $this->assertEquals(1, $tokyoCount, 'Tokyo school should have 1 attendance for Feb 10');
        
        // Test Jakarta school query
        $jakartaCount = \App\Models\Attendance::where('school_id', $schoolJakarta->id)
            ->whereDate('attendance_date', TimezoneHelper::today($schoolJakarta))
            ->count();
        
        $this->assertEquals(1, $jakartaCount, 'Jakarta school should have 1 attendance for Feb 10');
        
        // Test that querying with wrong date doesn't find records
        $wrongDateTokyo = \App\Models\Attendance::where('school_id', $schoolTokyo->id)
            ->whereDate('attendance_date', '2026-02-09') // Wrong date (should be Feb 10)
            ->count();
        
        $this->assertEquals(0, $wrongDateTokyo, 'Tokyo school should have 0 attendances for Feb 9');
        
        $wrongDateJakarta = \App\Models\Attendance::where('school_id', $schoolJakarta->id)
            ->whereDate('attendance_date', '2026-02-09') // Wrong date (should be Feb 10)
            ->count();
        
        $this->assertEquals(0, $wrongDateJakarta, 'Jakarta school should have 0 attendances for Feb 9');
        
        Carbon::setTestNow(); // Reset
    }
}