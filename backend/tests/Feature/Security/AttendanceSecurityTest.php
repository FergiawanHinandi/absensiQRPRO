<?php

namespace Tests\Feature\Security;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Models\AcademicYear;
use App\Services\AttendanceArchiveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected $school;
    protected $student;
    protected $schedule;
    protected $class;
    protected $subject;
    protected $academicYear;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup Models
        $this->school = School::factory()->create();
        
        $this->academicYear = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'status' => 'active'
        ]);

        $this->class = ClassModel::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->academicYear->id
        ]);
        
        $this->student = User::factory()->create([
            'role_type' => 'student',
            'school_id' => $this->school->id,
            'device_id' => 'device-unit-test-123',
            'username' => 'student_test',
            'email' => 'student@test.com'
        ]);
        
        $this->student->classStudents()->create([
            'class_id' => $this->class->id,
            'status' => 'active',
            'school_id' => $this->school->id
        ]);
        
        $this->subject = Subject::factory()->create(['school_id' => $this->school->id]);

        $this->schedule = Schedule::factory()->create([
             'school_id' => $this->school->id,
             'class_id' => $this->class->id,
             'subject_id' => $this->subject->id,
             'start_time' => '07:00:00',
             'end_time' => '12:00:00',
             'day_of_week' => strtolower(now()->englishDayOfWeek)
        ]);
    }

    protected function generateQrPayload($schedule, $expiresAt = null, $idempotencyKey = null)
    {
        $data = [
            'schedule_id' => $schedule->id,
            'school_id' => $schedule->school_id,
            'expires_at' => ($expiresAt ?? now()->addMinutes(1))->toIso8601String(),
            'idempotency_key' => $idempotencyKey ?? Str::uuid()->toString(),
            'nonce' => Str::random(16),
            'generated_at' => now()->timestamp
        ];

        $signature = hash_hmac('sha256', json_encode($data), config('app.key'));

        return [
            'qr_payload' => [
                'data' => $data,
                'signature' => $signature
            ]
        ];
    }

    public function test_attendance_duplicate_prevention()
    {
        $payload = $this->generateQrPayload($this->schedule);
        
        $response1 = $this->actingAs($this->student)
             ->withHeader('X-Idempotency-Key', (string) Str::uuid())
             ->postJson('/api/v1/attendance/scan', $payload);
        $response1->assertStatus(200);
        
        $payload2 = $this->generateQrPayload($this->schedule);
        
        $response2 = $this->actingAs($this->student)
             ->withHeader('X-Idempotency-Key', (string) Str::uuid())
             ->postJson('/api/v1/attendance/scan', $payload2);
             
        $response2->assertStatus(400) 
                  ->assertJsonFragment(['success' => false]);
    }

    public function test_cross_school_access_prevention()
    {
        $otherSchool = School::factory()->create();
        $otherClass = ClassModel::factory()->create(['school_id' => $otherSchool->id]);
        $otherSchedule = Schedule::factory()->create([
            'school_id' => $otherSchool->id,
            'class_id' => $otherClass->id,
            'day_of_week' => strtolower(now()->englishDayOfWeek)
        ]);
        
        $payload = $this->generateQrPayload($otherSchedule);
        
        $response = $this->actingAs($this->student)
             ->withHeader('X-Idempotency-Key', (string) Str::uuid())
             ->postJson('/api/v1/attendance/scan', $payload);
             
        $response->assertStatus(404);
    }

    public function test_qr_signature_validation()
    {
        $payload = $this->generateQrPayload($this->schedule);
        $payload['qr_payload']['signature'] = 'invalid_hash_value';
        
        $response = $this->actingAs($this->student)
             ->withHeader('X-Idempotency-Key', (string) Str::uuid())
             ->postJson('/api/v1/attendance/scan', $payload);
             
        $response->assertStatus(403);
    }

    public function test_expired_qr_rejection()
    {
        $payload = $this->generateQrPayload($this->schedule, now()->subMinutes(5));
        
        $response = $this->actingAs($this->student)
             ->withHeader('X-Idempotency-Key', (string) Str::uuid())
             ->postJson('/api/v1/attendance/scan', $payload);
             
        $response->assertStatus(410);
    }

    public function test_replay_rejection()
    {
        $payload = $this->generateQrPayload($this->schedule);
        
        $this->actingAs($this->student)
             ->withHeader('X-Idempotency-Key', (string) Str::uuid())
             ->postJson('/api/v1/attendance/scan', $payload)
             ->assertStatus(200);

        $response = $this->actingAs($this->student)
             ->withHeader('X-Idempotency-Key', (string) Str::uuid())
             ->postJson('/api/v1/attendance/scan', $payload);
             
        $response->assertStatus(400); 
    }

    public function test_idempotency_working()
    {
        $payload = $this->generateQrPayload($this->schedule);
        $idemKey = Str::uuid()->toString();
        
        $response1 = $this->actingAs($this->student)
             ->withHeader('X-Idempotency-Key', $idemKey)
             ->postJson('/api/v1/attendance/scan', $payload);
        $response1->assertStatus(200);
        
        $response2 = $this->actingAs($this->student)
             ->withHeader('X-Idempotency-Key', $idemKey)
             ->postJson('/api/v1/attendance/scan', $payload);
             
        $response2->assertStatus(200)
                  ->assertJson($response1->json());
    }

    public function test_missing_idempotency_key_rejected()
    {
        $payload = $this->generateQrPayload($this->schedule);

        $response = $this->actingAs($this->student)
             ->postJson('/api/v1/attendance/scan', $payload);

        $response->assertStatus(400)
                 ->assertJsonFragment(['code' => 'MISSING_IDEMPOTENCY_KEY']);
    }

    public function test_archive_working()
    {
        $year = 2024;
        $date = Carbon::create($year, 5, 10);
        $archiveTable = "attendances_{$year}";

        if (!Schema::hasTable($archiveTable)) {
            DB::statement("CREATE TABLE {$archiveTable} LIKE attendances");
        }
        
        $attendance = Attendance::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'attendance_date' => $date,
            'status' => 'present',
            'created_at' => $date,
            'updated_at' => $date
        ]);
        
        $this->assertDatabaseHas('attendances', ['id' => $attendance->id]);
        
        Artisan::call('attendance:archive', [
            'year' => $year,
            '--force' => true
        ]);
        
        $this->assertDatabaseMissing('attendances', ['id' => $attendance->id]);
        
        $existsInArchive = DB::table($archiveTable)
            ->where('id', $attendance->id)
            ->exists();
        $this->assertTrue($existsInArchive, "Record not found in {$archiveTable}");
    }

    public function test_report_working_after_archive()
    {
        $year = 2023;
        $date = Carbon::create($year, 1, 1);
        $archiveTable = "attendances_{$year}";

        if (!Schema::hasTable($archiveTable)) {
            DB::statement("CREATE TABLE {$archiveTable} LIKE attendances");
        }
        
        $attendance = Attendance::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'attendance_date' => $date,
            'status' => 'present'
        ]);
        
        Artisan::call('attendance:archive', ['year' => $year, '--force' => true]);
        
        $service = new AttendanceArchiveService();
        $results = $service->getAttendances([
            'school_id' => $this->school->id,
            'start_date' => $date->toDateString(),
            'end_date' => $date->toDateString()
        ]);
        
        $this->assertNotEmpty($results);
        $this->assertEquals($date->toDateString(), $results->first()->attendance_date);
        $this->assertEquals($this->student->id, $results->first()->student_id);
    }
}
