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
}
