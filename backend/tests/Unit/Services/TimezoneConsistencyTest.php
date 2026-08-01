<?php

namespace Tests\Unit\Services;

use App\Helpers\Timezone;
use App\Models\School;
use App\Models\Schedule;
use App\Services\ProductionAttendanceService;
use Carbon\Carbon;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TimezoneConsistencyTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_school_timezone_instead_of_server_utc()
    {
        // 1. Setup School with Asia/Jakarta (UTC+7)
        $school = School::factory()->create(['timezone' => 'Asia/Jakarta']);
        
        // 2. Set Server Time (Mock) to UTC
        // Scenario: It's 16:00 UTC (23:00 WIB)
        // If we used server date, it would be "Today" (say 2026-01-01)
        // If we use school date, it is ALSO "Today" (2026-01-01)
        
        // Let's use the critical Midnight crossover
        // Server: 2026-01-01 17:01:00 UTC
        // School: 2026-01-02 00:01:00 WIB
        
        $serverTime = Carbon::create(2026, 1, 1, 17, 1, 0, 'UTC');
        Carbon::setTestNow($serverTime);
        
        // Verify Server Time is previous day
        $this->assertEquals('2026-01-01', now('UTC')->toDateString());
        
        // Verify School Time is next day
        $schoolNow = Timezone::now($school);
        $this->assertEquals('2026-01-02', $schoolNow->toDateString());
        $this->assertEquals('Asia/Jakarta', $schoolNow->timezoneName);
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function helper_returns_correct_date_string()
    {
        $school = School::factory()->create(['timezone' => 'Asia/Tokyo']); // UTC+9
        
        // Server: 2026-05-20 16:00:00 UTC
        // Tokyo:  2026-05-21 01:00:00 JST
        Carbon::setTestNow(Carbon::create(2026, 5, 20, 16, 0, 0, 'UTC'));
        
        $today = Timezone::today($school);
        $this->assertEquals('2026-05-21', $today);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generate_qr_uses_school_timezone_for_validity()
    {
        // Scenario: Student scans QR generated near midnight
        // If server time logic is used, expiry might be calculated wrong relative to school time
        
        $school = School::factory()->create(['timezone' => 'Asia/Jayapura']); // UTC+9
        
        // Mock time
        $baseTime = Carbon::create(2026, 10, 10, 14, 59, 0, 'UTC'); // 23:59 Jayapura
        Carbon::setTestNow($baseTime);
        
        // Using Helper
        $now = Timezone::now($school);
        $this->assertEquals('23:59:00', $now->toTimeString());
        
        // Advance 2 minutes (Cross midnight)
        Carbon::setTestNow($baseTime->copy()->addMinutes(2));
        
        $nowLater = Timezone::now($school);
        $this->assertEquals('00:01:00', $nowLater->toTimeString());
        $this->assertNotEquals($now->toDateString(), $nowLater->toDateString());
    }
}
