<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\ClassStudent;
use App\Models\Schedule;
use App\Models\School;
use App\Models\TeacherDevice;
use App\Models\User;
use App\Services\AttendanceCheckInService;
use App\Services\AttendanceResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BIZ-1: Teacher scan flow must work end-to-end.
 *
 * Regression coverage for the pre-existing crash where teacherCheckIn()
 * referenced undefined findTeacherSchedule()/validateStudentInClass()
 * methods, which turned every teacher-scan request into a 500.
 */
class TeacherScanFlowTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private User $student;
    private ClassModel $class;
    private Schedule $schedule;
    private string $deviceId;
    private string $qrToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create([
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        $this->class = ClassModel::factory()->create([
            'school_id' => $this->school->id,
        ]);

        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);

        ClassStudent::create([
            'student_id' => $this->student->id,
            'class_id' => $this->class->id,
            'status' => 'active',
            'enrollment_date' => now(),
        ]);

        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => now()->subMinutes(10)->format('H:i:s'),
            'end_time' => now()->addMinutes(30)->format('H:i:s'),
            'is_active' => true,
        ]);

        $this->deviceId = (string) Str::uuid();

        TeacherDevice::factory()->create([
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'device_id' => $this->deviceId,
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $this->qrToken = $this->signedQrToken($this->student, $this->school);
    }

    private function signedQrToken(User $student, School $school): string
    {
        $payload = [
            'sid' => $student->id,
            'sch' => $school->id,
            'iat' => now()->timestamp,
            'exp' => now()->addMinutes(5)->timestamp,
            'n' => Str::random(16),
        ];

        $encoded = base64_encode(json_encode($payload));

        return $encoded . '.' . hash_hmac('sha256', $encoded, config('qr.secret'));
    }

    private function scanPayload(array $overrides = []): array
    {
        return array_merge([
            'qr_token' => $this->qrToken,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'device_id' => $this->deviceId,
        ], $overrides);
    }

    /**
     * PHPUnit injects a non-UUID X-Request-ID header when absent, which the
     * request_id uuid rule rejects; always send explicit headers instead.
     */
    private function scanHeaders(?string $requestId = null): array
    {
        return [
            'X-Idempotency-Key' => (string) Str::uuid(),
            'X-Request-ID' => $requestId ?? (string) Str::uuid(),
            'X-Device-ID' => $this->deviceId,
        ];
    }

    /**
     * BIZ-1: teacherCheckIn() no longer crashes with an undefined-method
     * error; it returns a successful AttendanceResult via the canonical flow.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_check_in_service_returns_successful_result(): void
    {
        $service = app(AttendanceCheckInService::class);

        $result = $service->teacherCheckIn(
            $this->teacher,
            $this->qrToken,
            ['lat' => -6.2088, 'lng' => 106.8456]
        );

        $this->assertInstanceOf(AttendanceResult::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('present', $result->status);

        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_type' => 'teacher_scan',
            'recorded_by' => $this->teacher->id,
        ]);
    }

    /**
     * BIZ-1: the /attendance/scan-student endpoint records attendance
     * end-to-end (middleware stack + controller + service).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_can_scan_student_via_scan_student_endpoint(): void
    {
        $requestId = (string) Str::uuid();

        $response = $this->actingAs($this->teacher)
            ->withHeaders($this->scanHeaders($requestId))
            ->postJson('/api/v1/attendance/scan-student', $this->scanPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'present');

        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_type' => 'teacher_scan',
            'recorded_by' => $this->teacher->id,
            'request_id' => $requestId,
        ]);
    }

    /**
     * BIZ-1: homeroom_teacher role is allowed by the secure scan route
     * and by the service (previously recordByTeacherScan was teacher-only).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function homeroom_teacher_can_scan_via_service(): void
    {
        $homeroom = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'homeroom_teacher',
        ]);

        $this->schedule->update(['teacher_id' => $homeroom->id]);

        $result = app(AttendanceCheckInService::class)->teacherCheckIn(
            $homeroom,
            $this->qrToken,
            ['lat' => -6.2088, 'lng' => 106.8456]
        );

        $this->assertTrue($result->isSuccessful());

        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'recorded_by' => $homeroom->id,
        ]);
    }

    /**
     * BIZ-1: student not enrolled in the schedule's class is rejected.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function student_not_in_class_is_rejected(): void
    {
        ClassStudent::where('student_id', $this->student->id)->delete();

        $response = $this->actingAs($this->teacher)
            ->withHeaders($this->scanHeaders())
            ->postJson('/api/v1/attendance/scan-student', $this->scanPayload());

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'STUDENT_NOT_IN_CLASS');
    }

    /**
     * BIZ-1: replaying the same request_id returns the existing attendance
     * (idempotent retry) instead of creating a duplicate row.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function same_request_id_is_idempotent(): void
    {
        $requestId = (string) Str::uuid();

        $first = $this->actingAs($this->teacher)
            ->withHeaders($this->scanHeaders($requestId))
            ->postJson('/api/v1/attendance/scan-student', $this->scanPayload());

        $first->assertStatus(201)->assertJsonPath('data.is_retry', false);

        $second = $this->actingAs($this->teacher)
            ->withHeaders($this->scanHeaders($requestId))
            ->postJson('/api/v1/attendance/scan-student', $this->scanPayload());

        $second->assertStatus(200)->assertJsonPath('data.is_retry', true);

        $this->assertSame(
            1,
            Attendance::where('student_id', $this->student->id)
                ->where('schedule_id', $this->schedule->id)
                ->whereDate('attendance_date', now()->timezone($this->school->timezone)->toDateString())
                ->count()
        );
    }
}
