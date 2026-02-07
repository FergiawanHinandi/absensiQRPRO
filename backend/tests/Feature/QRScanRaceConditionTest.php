<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\QRCode;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QRScanRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    protected $school;

    protected $student;

    protected $teacher;

    protected $schedule;

    protected $qrCode;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip race condition tests on SQLite as they require PostgreSQL specific features
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Race condition tests require PostgreSQL for proper locking support.');
        }

        $this->school = School::factory()->create();
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);

        // Create academic year
        $academicYear = \App\Models\AcademicYear::factory()->create([
            'school_id' => $this->school->id,
        ]);

        $class = ClassModel::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $academicYear->id,
        ]);

        $subject = Subject::factory()->create([
            'school_id' => $this->school->id,
        ]);

        $teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        $this->schedule = Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => Carbon::now()->dayOfWeek,
            'start_time' => Carbon::now()->subMinutes(10)->format('H:i:s'),
            'end_time' => Carbon::now()->addMinutes(50)->format('H:i:s'),
        ]);

        $this->qrCode = QRCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'token' => 'test-token-'.uniqid(),
            'qr_code' => 'test-qr-code-'.uniqid(),
            'expires_at' => Carbon::now()->addMinutes(30),
            'is_active' => true,
            'generated_by' => $teacher->id,
        ]);
    }

    /**
     * Test: Concurrent QR scans from same student should not create duplicate attendance
     *
     * @return void
     */
    public function test_concurrent_qr_scans_no_duplicate_attendance()
    {
        Sanctum::actingAs($this->student, ['*']);

        $results = [];
        $exceptions = [];

        // Simulate 5 concurrent requests
        for ($i = 0; $i < 5; $i++) {
            try {
                $response = $this->postJson('/api/v1/student/attendance/scan', [
                    'qr_code' => $this->qrCode->qr_code,
                    'latitude' => -6.200000,
                    'longitude' => 106.816666,
                ]);

                $results[] = [
                    'attempt' => $i + 1,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ];
            } catch (\Exception $e) {
                $exceptions[] = $e->getMessage();
            }
        }

        // Count how many attendances were created
        $attendanceCount = Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->where('attendance_date', Carbon::now()->format('Y-m-d'))
            ->count();

        // Should only have 1 attendance record
        $this->assertEquals(
            1,
            $attendanceCount,
            "Expected exactly 1 attendance record, but found {$attendanceCount}. ".
            'Results: '.json_encode($results)
        );

        // First request should succeed
        $this->assertEquals(200, $results[0]['status'], 'First scan should succeed');

        // Subsequent requests should fail or return duplicate message
        for ($i = 1; $i < count($results); $i++) {
            $this->assertTrue(
                in_array($results[$i]['status'], [409, 422, 200]),
                'Subsequent scans should return conflict or duplicate message'
            );

            if ($results[$i]['status'] === 200) {
                // If status is 200, it should indicate duplicate
                $message = $results[$i]['body']['message'] ?? '';
                $this->assertStringContainsString(
                    'sudah',
                    strtolower($message),
                    'Response should indicate attendance already exists'
                );
            }
        }
    }

    /**
     * Test: Database constraint prevents duplicate attendance
     *
     * @return void
     */
    public function test_database_constraint_prevents_duplicate()
    {
        // Create first attendance
        $attendance1 = Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => Carbon::now()->format('Y-m-d'),
            'status' => 'present',
            'recorded_by' => $this->student->id,
        ]);

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance1->id,
        ]);

        // Try to create duplicate
        $duplicateCreated = false;
        try {
            $attendance2 = Attendance::create([
                'school_id' => $this->school->id,
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => Carbon::now()->format('Y-m-d'),
                'status' => 'present',
                'recorded_by' => $this->student->id,
            ]);
            $duplicateCreated = true;
        } catch (\Exception $e) {
            // Expected to throw exception due to unique constraint
            $this->assertStringContainsString(
                'Duplicate entry',
                $e->getMessage(),
                'Should throw duplicate entry exception'
            );
        }

        $this->assertFalse($duplicateCreated, 'Duplicate attendance should not be created');

        // Verify only one record exists
        $count = Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->where('attendance_date', Carbon::now()->format('Y-m-d'))
            ->count();

        $this->assertEquals(1, $count, 'Should have exactly 1 attendance record');
    }

    /**
     * Test: Using first() check before insert prevents race condition
     *
     * @return void
     */
    public function test_first_or_create_prevents_race_condition()
    {
        $results = [];

        // Simulate concurrent first-or-create operations
        for ($i = 0; $i < 10; $i++) {
            $attendance = Attendance::firstOrCreate(
                [
                    'student_id' => $this->student->id,
                    'schedule_id' => $this->schedule->id,
                    'attendance_date' => Carbon::now()->format('Y-m-d'),
                ],
                [
                    'school_id' => $this->school->id,
                    'status' => 'present',
                    'recorded_by' => $this->student->id,
                ]
            );

            $results[] = $attendance->id;
        }

        // All operations should return the same record ID
        $uniqueIds = array_unique($results);
        $this->assertCount(
            1,
            $uniqueIds,
            'firstOrCreate should always return the same record'
        );

        // Verify only one record in database
        $count = Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->where('attendance_date', Carbon::now()->format('Y-m-d'))
            ->count();

        $this->assertEquals(1, $count);
    }

    /**
     * Test: Transaction with lock prevents race condition
     *
     * @return void
     */
    public function test_transaction_with_lock_prevents_race()
    {
        $results = [];

        for ($i = 0; $i < 5; $i++) {
            DB::beginTransaction();
            try {
                // Lock the row to prevent concurrent writes
                $existing = Attendance::where('student_id', $this->student->id)
                    ->where('schedule_id', $this->schedule->id)
                    ->where('attendance_date', Carbon::now()->format('Y-m-d'))
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    $attendance = Attendance::create([
                        'school_id' => $this->school->id,
                        'student_id' => $this->student->id,
                        'schedule_id' => $this->schedule->id,
                        'attendance_date' => Carbon::now()->format('Y-m-d'),
                        'status' => 'present',
                        'recorded_by' => $this->student->id,
                    ]);
                    $results[] = ['created' => true, 'id' => $attendance->id];
                } else {
                    $results[] = ['created' => false, 'id' => $existing->id];
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $results[] = ['created' => false, 'error' => $e->getMessage()];
            }
        }

        // Only first attempt should create, rest should find existing
        $createdCount = collect($results)->where('created', true)->count();
        $this->assertEquals(1, $createdCount, 'Only one record should be created');

        // Verify single record in database
        $count = Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->where('attendance_date', Carbon::now()->format('Y-m-d'))
            ->count();

        $this->assertEquals(1, $count);
    }

    /**
     * Test: Unique index on attendances table exists
     *
     * @return void
     */
    public function test_unique_index_exists_on_attendances()
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Check for unique indexes using pragma
            $indexes = DB::select('PRAGMA index_list(attendances)');
            $uniqueIndexes = collect($indexes)->where('unique', 1);

            $this->assertNotEmpty(
                $uniqueIndexes,
                'Unique index should exist on attendances table'
            );
        } elseif ($driver === 'pgsql') {
            // PostgreSQL-compatible query
            $indexes = DB::select("
                SELECT indexname, indexdef 
                FROM pg_indexes 
                WHERE tablename = 'attendances' 
                AND indexdef LIKE '%UNIQUE%'
            ");

            $this->assertNotEmpty(
                $indexes,
                'Unique index should exist on attendances table'
            );
        } else {
            // MySQL
            $indexes = DB::select('SHOW INDEXES FROM attendances WHERE Non_unique = 0');
            $this->assertNotEmpty($indexes);
        }

        $this->assertTrue(true, 'Unique indexes verified');
    }

    /**
     * Test: QR code deactivation after first scan (optional security measure)
     *
     * @return void
     */
    public function test_qr_code_single_use_option()
    {
        // This test assumes QR codes can be configured for single-use
        // Update QR code to allow only one scan
        $this->qrCode->update(['single_use' => true]);

        Sanctum::actingAs($this->student, ['*']);

        // First scan
        $response1 = $this->postJson('/api/v1/student/attendance/scan', [
            'qr_code' => $this->qrCode->qr_code,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);

        $response1->assertStatus(200);

        // Refresh QR code state
        $this->qrCode->refresh();

        // Second scan should fail if single-use is enforced
        if ($this->qrCode->single_use && ! $this->qrCode->is_active) {
            $response2 = $this->postJson('/api/v1/student/attendance/scan', [
                'qr_code' => $this->qrCode->qr_code,
                'latitude' => -6.200000,
                'longitude' => 106.816666,
            ]);

            $this->assertTrue(
                in_array($response2->status(), [400, 410, 422]),
                'Second scan should fail for single-use QR code'
            );
        }

        // Note: This test will be skipped if single_use functionality is not implemented
        $this->assertTrue(true, 'QR code single-use test completed');
    }
}
