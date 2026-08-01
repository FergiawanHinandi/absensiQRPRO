<?php

namespace Tests\Unit\Services;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Services\TrendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for TrendService
 *
 * Covers:
 * - getDailyTrend() — DB-dependent, uses RefreshDatabase
 * - getComparison() — DB-dependent, uses RefreshDatabase
 * - aggregateWeekly() — pure logic, no DB needed
 * - formatDateLabel() — pure logic, no DB needed
 */
#[\PHPUnit\Framework\Attributes\Group('services')]
#[\PHPUnit\Framework\Attributes\Group('trends')]
class TrendServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TrendService $trendService;
    protected School $school;
    protected User $student;
    protected User $teacher;
    protected Subject $subject;
    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        // Set Carbon locale to Indonesian for date label assertions
        Carbon::setLocale('id');

        // Freeze time for deterministic date calculations
        Carbon::setTestNow(Carbon::parse('2026-07-30 10:00:00'));

        $this->trendService = new TrendService();

        // Create test school
        $this->school = School::factory()->create([
            'is_active' => true,
        ]);

        // Create teacher
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Create student
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create subject
        $this->subject = Subject::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'Matematika',
        ]);

        // Create reusable schedule
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ──────────── Helpers ────────────

    /**
     * Helper: create students for attendance records.
     */
    private function createStudents(int $count): array
    {
        return User::factory()->count($count)->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'is_active' => true,
        ])->all();
    }

    /**
     * Helper: create an attendance record with minimal required fields.
     *
     * TrendService uses DB::table('attendances') — raw SQL queries.
     * We bypass Eloquent's guarded/state-machine by using DB::table() direct insert.
     * This ensures the `status` column (used by TrendService) contains the expected value.
     */
    private function createAttendance(
        string $date,
        int $studentId,
        string $legacyStatus = 'present',
        ?int $scheduleId = null,
        ?int $schoolId = null,
    ): void {
        DB::table('attendances')->insert([
            'school_id' => $schoolId ?? $this->school->id,
            'student_id' => $studentId,
            'schedule_id' => $scheduleId ?? $this->schedule->id,
            'attendance_date' => $date,
            'state' => 'init',
            'status' => $legacyStatus,
            'check_in_time' => now(),
            'is_manual' => false,
            'request_id' => \Illuminate\Support\Str::uuid()->toString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ──────────── formatDateLabel ────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function format_date_label_returns_day_name_for_7d(): void
    {
        // setUp() calls Carbon::setLocale('id')
        // isoFormat('dd') with 'id' locale returns 2-char abbreviation (e.g., 'Sn' for Senin)
        $monday = Carbon::parse('2026-01-05'); // Monday
        $label = $this->trendService->formatDateLabel($monday, '7d');

        $this->assertNotEmpty($label, 'Label should not be empty');
        // Indonesian: Sn, Sl, Rb, Km, Jm, Sb, Mg (2-char)
        // English: Mon, Tue, Wed, Thu, Fri, Sat, Sun (3-char)
        $this->assertLessThanOrEqual(3, strlen($label), 'Day abbreviation should be 2-3 chars');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function format_date_label_returns_date_for_30d(): void
    {
        $date = Carbon::parse('2026-01-05');
        $label = $this->trendService->formatDateLabel($date, '30d');

        $this->assertEquals('05 Jan', $label, '30d period should return DD MMM format');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function format_date_label_returns_date_for_90d(): void
    {
        $date = Carbon::parse('2026-01-15');
        $label = $this->trendService->formatDateLabel($date, '90d');

        $this->assertEquals('15 Jan', $label, '90d period should return DD MMM format');
    }

    // ──────────── aggregateWeekly ────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_weekly_groups_daily_data_into_weeks(): void
    {
        // Create 14 days of daily data (2 weeks) — all present
        $dailyData = [];
        $start = Carbon::parse('2026-01-05'); // Monday

        for ($i = 0; $i < 14; $i++) {
            $date = $start->copy()->addDays($i);
            $dailyData[] = [
                'date' => $date->toDateString(),
                'label' => $date->isoFormat('dd'),
                'present' => 20,
                'late' => 2,
                'absent' => 1,
                'excused' => 1,
                'total' => 24,
                'rate' => round((22 / 24) * 100, 1), // (20+2)/24 ≈ 91.7
            ];
        }

        $weekly = $this->trendService->aggregateWeekly($dailyData);

        $this->assertCount(2, $weekly, '14 days should produce 2 weekly groups');
        $this->assertEquals('Minggu 1', $weekly[0]['label']);
        $this->assertEquals('Minggu 2', $weekly[1]['label']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_weekly_calculates_rate_correctly(): void
    {
        $dailyData = [
            [
                'date' => '2026-01-05',
                'label' => 'Sen',
                'present' => 15,
                'late' => 5,
                'absent' => 0,
                'excused' => 0,
                'total' => 20,
                'rate' => 100.0,
            ],
            [
                'date' => '2026-01-06',
                'label' => 'Sel',
                'present' => 10,
                'late' => 0,
                'absent' => 10,
                'excused' => 0,
                'total' => 20,
                'rate' => 50.0,
            ],
        ];

        $weekly = $this->trendService->aggregateWeekly($dailyData);

        $this->assertCount(1, $weekly);
        // present+late = (15+5) + (10+0) = 30, total = 20+20 = 40
        $this->assertEquals(30, $weekly[0]['present'] + $weekly[0]['late']);
        $this->assertEquals(40, $weekly[0]['total']);
        // Rate: (30/40)*100 = 75.0
        $this->assertEquals(75.0, $weekly[0]['rate']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_weekly_returns_empty_array_for_empty_input(): void
    {
        $weekly = $this->trendService->aggregateWeekly([]);
        $this->assertIsArray($weekly);
        $this->assertCount(0, $weekly);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_weekly_handles_partial_week(): void
    {
        // Only 3 days (Mon-Wed) — should be one partial week
        $dailyData = [];
        for ($i = 0; $i < 3; $i++) {
            $date = Carbon::parse('2026-01-05')->addDays($i);
            $dailyData[] = [
                'date' => $date->toDateString(),
                'label' => $date->isoFormat('dd'),
                'present' => 10,
                'late' => 0,
                'absent' => 0,
                'excused' => 0,
                'total' => 10,
                'rate' => 100.0,
            ];
        }

        $weekly = $this->trendService->aggregateWeekly($dailyData);

        $this->assertCount(1, $weekly, '3 days should be 1 partial week');
        $this->assertEquals(30, $weekly[0]['present']);
        $this->assertEquals(100.0, $weekly[0]['rate']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_weekly_handles_zero_total_records(): void
    {
        $dailyData = [
            [
                'date' => '2026-01-05',
                'label' => 'Sen',
                'present' => 0,
                'late' => 0,
                'absent' => 0,
                'excused' => 0,
                'total' => 0,
                'rate' => 0.0,
            ],
        ];

        $weekly = $this->trendService->aggregateWeekly($dailyData);

        $this->assertCount(1, $weekly);
        $this->assertEquals(0, $weekly[0]['rate'], 'Rate should be 0 when total is 0');
    }

    // ──────────── getDailyTrend ────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_daily_trend_returns_correct_structure(): void
    {
        $result = $this->trendService->getDailyTrend($this->school->id, '7d');

        $this->assertArrayHasKey('daily', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('weekly', $result);
        $this->assertArrayHasKey('startDate', $result);
        $this->assertArrayHasKey('endDate', $result);

        // Should have 7 daily entries (zero-filled)
        $this->assertCount(7, $result['daily']);
        $this->assertNull($result['weekly'], '7d period should not have weekly aggregation');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_daily_trend_counts_attendance_records(): void
    {
        $today = now()->toDateString();
        $students = $this->createStudents(6);

        // 3 present, 2 late, 1 absent (alpha) — each with unique student
        foreach (array_slice($students, 0, 3) as $s) {
            $this->createAttendance($today, $s->id, 'present');
        }
        foreach (array_slice($students, 3, 2) as $s) {
            $this->createAttendance($today, $s->id, 'late');
        }
        $this->createAttendance($today, $students[5]->id, 'alpha');

        $result = $this->trendService->getDailyTrend($this->school->id, '7d');
        $todayEntry = collect($result['daily'])->firstWhere('date', $today);

        $this->assertNotNull($todayEntry, 'Today should have a daily entry');
        $this->assertEquals(3, $todayEntry['present'], 'Present count should be 3');
        $this->assertEquals(2, $todayEntry['late'], 'Late count should be 2');
        $this->assertEquals(1, $todayEntry['absent'], 'Absent count should be 1');
        $this->assertEquals(0, $todayEntry['excused'], 'Excused count should be 0');
        $this->assertEquals(6, $todayEntry['total'], 'Total should be 6');
        // Rate: (3+2)/6 * 100 = 83.3
        $this->assertEquals(83.3, $todayEntry['rate']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_daily_trend_calculates_summary_correctly(): void
    {
        // Create records across multiple days — 10 per day for 3 days
        $today = now();
        $students = $this->createStudents(10);

        for ($i = 0; $i < 3; $i++) {
            $date = $today->copy()->subDays($i)->toDateString();
            foreach ($students as $s) {
                $this->createAttendance($date, $s->id, 'present');
            }
        }

        $result = $this->trendService->getDailyTrend($this->school->id, '7d');
        $summary = $result['summary'];

        $this->assertEquals('7d', $summary['period']);
        $this->assertEquals(7, $summary['days']);
        $this->assertEquals(3, $summary['days_with_data'], '3 days should have data');
        $this->assertEquals(30, $summary['total_records']);
        $this->assertEquals(30, $summary['total_present']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_daily_trend_returns_thirty_days_for_30d_period(): void
    {
        $result = $this->trendService->getDailyTrend($this->school->id, '30d');

        $this->assertCount(30, $result['daily']);
        $this->assertNotNull($result['weekly'], '30d period should have weekly aggregation');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_daily_trend_scopes_by_teacher(): void
    {
        // Teacher's schedule — 5 attendance records
        $students = $this->createStudents(8);
        $today = now()->toDateString();

        foreach (array_slice($students, 0, 5) as $s) {
            $this->createAttendance($today, $s->id, 'present', $this->schedule->id);
        }

        // Another schedule (different teacher) — 3 attendance records
        $otherTeacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
            'is_active' => true,
        ]);
        $otherSchedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'teacher_id' => $otherTeacher->id,
            'subject_id' => $this->subject->id,
        ]);
        foreach (array_slice($students, 5, 3) as $s) {
            $this->createAttendance($today, $s->id, 'present', $otherSchedule->id);
        }

        // With teacher filter — should only see 5 records (teacher's schedule only)
        $result = $this->trendService->getDailyTrend($this->school->id, '7d', $this->teacher->id);
        $todayEntry = collect($result['daily'])->firstWhere('date', $today);

        $this->assertNotNull($todayEntry);
        $this->assertEquals(5, $todayEntry['total'], 'Teacher should only see 5 records');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_daily_trend_zeros_fill_missing_dates(): void
    {
        // Only create data for today
        $today = now()->toDateString();
        $this->createAttendance($today, $this->student->id, 'present');

        $result = $this->trendService->getDailyTrend($this->school->id, '7d');

        $this->assertCount(7, $result['daily']);

        // Days without data should have 0 for all counts
        $yesterday = now()->subDay()->toDateString();
        $yesterdayEntry = collect($result['daily'])->firstWhere('date', $yesterday);
        $this->assertNotNull($yesterdayEntry);
        $this->assertEquals(0, $yesterdayEntry['total'], 'Days without data should have 0 total');
        $this->assertEquals(0, $yesterdayEntry['rate'], 'Days without data should have 0 rate');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_daily_trend_handles_sick_and_permit_as_excused(): void
    {
        $today = now()->toDateString();
        $students = $this->createStudents(2);

        $this->createAttendance($today, $students[0]->id, 'sick');
        $this->createAttendance($today, $students[1]->id, 'permit');

        $result = $this->trendService->getDailyTrend($this->school->id, '7d');
        $todayEntry = collect($result['daily'])->firstWhere('date', $today);

        $this->assertEquals(2, $todayEntry['excused'], 'Sick and permit should be counted as excused');
        $this->assertEquals(0, $todayEntry['present']);
    }

    // ──────────── getComparison ────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_comparison_returns_correct_structure(): void
    {
        $result = $this->trendService->getComparison($this->school->id);

        $this->assertArrayHasKey('this_week', $result);
        $this->assertArrayHasKey('last_week', $result);
        $this->assertArrayHasKey('change_percent', $result);
        $this->assertArrayHasKey('trend_direction', $result);

        $this->assertArrayHasKey('rate', $result['this_week']);
        $this->assertArrayHasKey('total', $result['this_week']);
        $this->assertArrayHasKey('attended', $result['this_week']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_comparison_detects_improvement(): void
    {
        $students = $this->createStudents(6);

        // Last week: 50% attendance (3 present + 3 absent per day)
        $lastWeekStart = now()->subDays(13);
        $lastWeekEnd = now()->subDays(7);
        for ($date = $lastWeekStart->copy(); $date->lte($lastWeekEnd); $date->addDay()) {
            $dateStr = $date->toDateString();
            foreach (array_slice($students, 0, 3) as $s) {
                $this->createAttendance($dateStr, $s->id, 'present');
            }
            foreach (array_slice($students, 3, 3) as $s) {
                $this->createAttendance($dateStr, $s->id, 'alpha');
            }
        }

        // This week: 100% attendance
        $thisWeekStart = now()->subDays(6);
        for ($date = $thisWeekStart->copy(); $date->lte(now()); $date->addDay()) {
            $dateStr = $date->toDateString();
            foreach ($students as $s) {
                $this->createAttendance($dateStr, $s->id, 'present');
            }
        }

        $result = $this->trendService->getComparison($this->school->id);

        $this->assertEquals(50.0, $result['last_week']['rate']);
        $this->assertEquals(100.0, $result['this_week']['rate']);
        $this->assertEquals('up', $result['trend_direction']);
        $this->assertGreaterThan(0, $result['change_percent']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_comparison_detects_decline(): void
    {
        $students = $this->createStudents(6);

        // Last week: 100% attendance
        $lastWeekStart = now()->subDays(13);
        $lastWeekEnd = now()->subDays(7);
        for ($date = $lastWeekStart->copy(); $date->lte($lastWeekEnd); $date->addDay()) {
            $dateStr = $date->toDateString();
            foreach ($students as $s) {
                $this->createAttendance($dateStr, $s->id, 'present');
            }
        }

        // This week: 50% attendance
        $thisWeekStart = now()->subDays(6);
        for ($date = $thisWeekStart->copy(); $date->lte(now()); $date->addDay()) {
            $dateStr = $date->toDateString();
            foreach (array_slice($students, 0, 3) as $s) {
                $this->createAttendance($dateStr, $s->id, 'present');
            }
            foreach (array_slice($students, 3, 3) as $s) {
                $this->createAttendance($dateStr, $s->id, 'alpha');
            }
        }

        $result = $this->trendService->getComparison($this->school->id);

        $this->assertEquals('down', $result['trend_direction']);
        $this->assertLessThan(0, $result['change_percent']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_comparison_returns_stable_when_no_change(): void
    {
        $students = $this->createStudents(6);

        // Same attendance pattern both weeks (67% attendance)
        for ($date = now()->subDays(13)->copy(); $date->lte(now()); $date->addDay()) {
            $dateStr = $date->toDateString();
            foreach (array_slice($students, 0, 4) as $s) {
                $this->createAttendance($dateStr, $s->id, 'present');
            }
            foreach (array_slice($students, 4, 2) as $s) {
                $this->createAttendance($dateStr, $s->id, 'alpha');
            }
        }

        $result = $this->trendService->getComparison($this->school->id);

        // 4/6 = 66.7% attendance rate
        $this->assertEquals('stable', $result['trend_direction']);
        $this->assertEquals(0, $result['change_percent']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_comparison_handles_no_data(): void
    {
        // No attendance records at all
        $result = $this->trendService->getComparison($this->school->id);

        $this->assertEquals(0, $result['this_week']['total']);
        $this->assertEquals(0, $result['last_week']['total']);
        $this->assertEquals(0, $result['this_week']['rate']);
        $this->assertEquals('stable', $result['trend_direction']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_comparison_scopes_by_teacher(): void
    {
        $students = $this->createStudents(3);
        $today = now()->toDateString();

        // Attendance linked to teacher's schedule
        foreach ($students as $s) {
            $this->createAttendance($today, $s->id, 'present', $this->schedule->id);
        }

        // Without teacher filter — sees all 3 records
        $resultAll = $this->trendService->getComparison($this->school->id);
        // With teacher filter — also sees 3 (all linked to this teacher's schedule)
        $resultTeacher = $this->trendService->getComparison($this->school->id, $this->teacher->id);

        $this->assertGreaterThan(0, $resultAll['this_week']['total']);
        $this->assertEquals(3, $resultTeacher['this_week']['attended']);
    }
}
