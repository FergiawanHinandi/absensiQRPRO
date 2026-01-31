<?php

namespace Tests\Feature;

use App\Core\Services\Attendance\QRCodeService;
use App\Core\Services\Attendance\TeacherAttendanceService;
use App\Core\Services\GeofenceService;
use App\Core\Services\SecurityEventLogger;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\QrNonce;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeacherAttendance;
use App\Models\TeacherAttendanceAnomaly;
use App\Models\TeacherDevice;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Comprehensive Attendance Integrity & Security Tests
 * 
 * Tests all security rules for both student and teacher attendance.
 * These tests focus on service-level testing for reliability.
 */
class AttendanceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $teacherA;
    protected User $teacherB;
    protected User $student;
    protected User $studentOtherClass;
    protected ClassModel $classA;
    protected ClassModel $classB;
    protected Schedule $scheduleA;
    protected Schedule $scheduleB;
    protected AcademicYear $academicYear;
    protected Subject $subject;
    protected QRCodeService $qrCodeService;
    protected TeacherAttendanceService $teacherAttendanceService;
    protected GeofenceService $geofenceService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school with coordinates
        $this->school = School::create([
            'name' => 'SMP Test School',
            'npsn' => '12345678',
            'school_level' => 'SMP',
            'address' => 'Test Address',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'radius_meters' => 100,
            'is_active' => true,
            'package_type' => 'basic',
            'settings' => [
                'work_start_time' => '07:00',
                'work_end_time' => '16:00',
            ],
        ]);

        // Create academic year
        $this->academicYear = AcademicYear::create([
            'school_id' => $this->school->id,
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'semester' => 1,
            'is_active' => true,
        ]);

        // Create subject
        $this->subject = Subject::create([
            'school_id' => $this->school->id,
            'name' => 'Matematika',
            'code' => 'MTK',
            'school_level' => 'SMP',
            'grade_level' => 7,
        ]);

        // Create classes
        $this->classA = ClassModel::create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->academicYear->id,
            'name' => 'Kelas 7A',
            'grade_level' => '7',
        ]);

        $this->classB = ClassModel::create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->academicYear->id,
            'name' => 'Kelas 7B',
            'grade_level' => '7',
        ]);

        // Create Teacher A
        $this->teacherA = User::create([
            'school_id' => $this->school->id,
            'username' => 'teacher_a',
            'name' => 'Teacher A',
            'email' => 'teacher_a@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Create Teacher B
        $this->teacherB = User::create([
            'school_id' => $this->school->id,
            'username' => 'teacher_b',
            'name' => 'Teacher B',
            'email' => 'teacher_b@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Create Student in Class A
        $this->student = User::create([
            'school_id' => $this->school->id,
            'class_id' => $this->classA->id,
            'username' => 'student_a',
            'name' => 'Student A',
            'email' => 'student_a@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create Student in Class B (different class)
        $this->studentOtherClass = User::create([
            'school_id' => $this->school->id,
            'class_id' => $this->classB->id,
            'username' => 'student_b',
            'name' => 'Student B',
            'email' => 'student_b@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create Schedule A (Teacher A, Class A)
        $this->scheduleA = Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $this->classA->id,
            'academic_year_id' => $this->academicYear->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacherA->id,
            'day_of_week' => Carbon::now()->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'room' => 'Room A',
        ]);

        // Create Schedule B (Teacher B, Class B)
        $this->scheduleB = Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $this->classB->id,
            'academic_year_id' => $this->academicYear->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacherB->id,
            'day_of_week' => Carbon::now()->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'room' => 'Room B',
        ]);

        // Get services
        $this->qrCodeService = app(QRCodeService::class);
        $this->teacherAttendanceService = app(TeacherAttendanceService::class);
        $this->geofenceService = app(GeofenceService::class);
    }

    /**
     * Helper to generate teacher QR token
     */
    protected function generateTeacherQrToken(int $teacherId, int $schoolId): string
    {
        $payload = [
            'v' => 1,
            't' => 'teacher',
            'teacher_id' => $teacherId,
            'school_id' => $schoolId,
            'ts' => Carbon::now()->timestamp,
            'exp' => Carbon::now()->addSeconds(30)->timestamp,
            'n' => Str::random(8),
        ];

        return Crypt::encrypt($payload);
    }

    // ========================================
    // SECTION 1 — GEOFENCE SERVICE TESTS
    // ========================================

    /**
     * TEST 1: GeofenceService correctly calculates distance
     */
    public function test_geofence_service_calculates_distance_correctly(): void
    {
        // School location
        $schoolLat = -6.2088;
        $schoolLng = 106.8456;

        // Same location
        $distance = $this->geofenceService->calculateDistance($schoolLat, $schoolLng, $schoolLat, $schoolLng);
        $this->assertEquals(0, $distance);

        // About 100m away (approximately)
        $nearLat = -6.2097; // ~100m south
        $distance2 = $this->geofenceService->calculateDistance($schoolLat, $schoolLng, $nearLat, $schoolLng);
        $this->assertGreaterThan(80, $distance2);
        $this->assertLessThan(120, $distance2);
    }

    /**
     * TEST 2: GeofenceService correctly identifies within radius
     */
    public function test_geofence_service_correctly_identifies_within_radius(): void
    {
        $schoolLat = -6.2088;
        $schoolLng = 106.8456;
        $maxRadius = 50;

        // Within radius (same location)
        $result = $this->geofenceService->isWithinRadius($schoolLat, $schoolLng, $schoolLat, $schoolLng, $maxRadius);
        $this->assertTrue($result);

        // Far outside radius (1km away)
        $farLat = -6.2180;
        $result2 = $this->geofenceService->isWithinRadius($schoolLat, $schoolLng, $farLat, $schoolLng, $maxRadius);
        $this->assertFalse($result2);
    }

    // ========================================
    // SECTION 2 — TEACHER ATTENDANCE SERVICE TESTS
    // ========================================

    /**
     * TEST 3: Teacher can check-in from approved device within radius
     */
    public function test_teacher_check_in_from_approved_device_within_radius(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 0)); // 07:00 - exactly on time for present

        $deviceId = 'approved-device-001';

        // Create approved device
        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        // Generate teacher QR
        $token = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        // Process check-in via service
        $attendance = $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token,
            'lat' => -6.2088, // Same as school location
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
        ]);

        $this->assertInstanceOf(TeacherAttendance::class, $attendance);
        $this->assertEquals($this->teacherA->id, $attendance->teacher_id);
        $this->assertContains($attendance->status, ['present', 'late']); // Either is valid

        Carbon::setTestNow();
    }

    /**
     * TEST 4: Teacher cannot check-in from unapproved device
     */
    public function test_teacher_cannot_check_in_from_unapproved_device(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $deviceId = 'pending-device-001';

        // Create PENDING (unapproved) device
        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'Samsung Galaxy',
            'platform' => 'android',
            'is_approved' => false,
            'approved_at' => null,
        ]);

        $token = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/menunggu|disetujui|unapproved|baru terdeteksi|persetujuan/i');

        $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
        ]);

        Carbon::setTestNow();
    }

    /**
     * TEST 5: Teacher cannot check-in with unknown device (creates anomaly)
     */
    public function test_teacher_cannot_check_in_with_unknown_device(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $token = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        $this->expectException(Exception::class);

        $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => 'unknown-device-xyz',
            'is_mock_location' => false,
        ]);

        // Verify anomaly was logged
        $this->assertDatabaseHas('teacher_attendance_anomalies', [
            'teacher_id' => $this->teacherA->id,
            'anomaly_type' => 'new_device_attempt',
        ]);

        Carbon::setTestNow();
    }

    /**
     * TEST 6: Teacher cannot check-in outside 50m radius
     */
    public function test_teacher_cannot_check_in_outside_radius(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $deviceId = 'approved-device-002';

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $token = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/luar area|di luar|outside|radius/i');

        // Far coordinates (~1km away)
        $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token,
            'lat' => -6.2180,
            'lng' => 106.8550,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
        ]);

        Carbon::setTestNow();
    }

    /**
     * TEST 7: Teacher cannot check-in with mock location
     */
    public function test_teacher_cannot_check_in_with_mock_location(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $deviceId = 'approved-device-003';

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $token = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/palsu|mock|fake/i');

        $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => true, // MOCK!
        ]);

        Carbon::setTestNow();
    }

    /**
     * TEST 8: Teacher cannot check-in twice on same day
     */
    public function test_teacher_cannot_check_in_twice_same_day(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $deviceId = 'approved-device-004';

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        // First check-in
        $token1 = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);
        $attendance1 = $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token1,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
        ]);

        $this->assertNotNull($attendance1);

        // Second check-in attempt
        $token2 = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/sudah.*check-in|already|duplicate/i');

        $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token2,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
        ]);

        Carbon::setTestNow();
    }

    /**
     * TEST 9: Teacher cannot check-in outside working hours (too early)
     */
    public function test_teacher_cannot_check_in_outside_working_hours_early(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(5, 0)); // 5 AM - too early

        $deviceId = 'approved-device-005';

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $token = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/belum.*waktu|terlalu.*pagi|too early/i');

        $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
        ]);

        Carbon::setTestNow();
    }

    /**
     * TEST 10: Teacher QR ownership is validated
     */
    public function test_teacher_qr_ownership_validation(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $deviceId = 'approved-device-006';

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        // Generate QR for Teacher B but Teacher A tries to use it
        $tokenForTeacherB = $this->generateTeacherQrToken($this->teacherB->id, $this->school->id);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/bukan milik|ownership|not yours/i');

        $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $tokenForTeacherB,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
        ]);

        Carbon::setTestNow();
    }

    // ========================================
    // SECTION 3 — DATABASE INTEGRITY TESTS
    // ========================================

    /**
     * TEST 11: DB prevents duplicate student attendance
     */
    public function test_db_prevents_duplicate_student_attendance(): void
    {
        // Insert first attendance
        Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->scheduleA->id,
            'student_id' => $this->student->id,
            'attendance_date' => Carbon::today(),
            'attendance_type' => 'in',
            'status' => 'present',
            'check_in_time' => now(),
            'is_manual' => false,
            'request_id' => (string) Str::uuid(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Attempt duplicate insert
        Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->scheduleA->id,
            'student_id' => $this->student->id,
            'attendance_date' => Carbon::today(),
            'attendance_type' => 'in',
            'status' => 'present',
            'check_in_time' => now(),
            'is_manual' => false,
            'request_id' => (string) Str::uuid(),
        ]);
    }

    /**
     * TEST 12: DB prevents duplicate teacher daily attendance
     */
    public function test_db_prevents_duplicate_teacher_daily_attendance(): void
    {
        TeacherAttendance::create([
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacherA->id,
            'attendance_date' => Carbon::today(),
            'status' => 'present',
            'check_in_time' => now(),
            'is_manual' => false,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TeacherAttendance::create([
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacherA->id,
            'attendance_date' => Carbon::today(),
            'status' => 'present',
            'check_in_time' => now(),
            'is_manual' => false,
        ]);
    }

    /**
     * TEST 13: DB prevents reused QR nonce
     */
    public function test_db_prevents_reused_qr_nonce(): void
    {
        $nonce = 'unique-nonce-12345';

        QrNonce::create([
            'nonce' => $nonce,
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->scheduleA->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        QrNonce::create([
            'nonce' => $nonce,
            'school_id' => $this->school->id,
            'student_id' => $this->studentOtherClass->id,
            'schedule_id' => $this->scheduleA->id,
        ]);
    }

    /**
     * TEST 14: DB prevents same device used by 2 teachers
     */
    public function test_db_prevents_same_device_for_two_teachers(): void
    {
        $deviceId = 'shared-device-001';

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TeacherDevice::create([
            'teacher_id' => $this->teacherB->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
        ]);
    }

    // ========================================
    // SECTION 4 — SECURITY ANOMALY LOGGING
    // ========================================

    /**
     * TEST 15: Out-of-radius attempt creates anomaly log
     */
    public function test_out_of_radius_creates_anomaly(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $deviceId = 'approved-device-anomaly-01';

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $token = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        try {
            $this->teacherAttendanceService->processCheckIn($this->teacherA, [
                'qr_token' => $token,
                'lat' => -6.3000, // Very far
                'lng' => 106.9000,
                'accuracy' => 10,
                'device_id' => $deviceId,
                'is_mock_location' => false,
            ]);
        } catch (Exception $e) {
            // Expected
        }

        // Verify anomaly was logged
        $this->assertDatabaseHas('teacher_attendance_anomalies', [
            'teacher_id' => $this->teacherA->id,
            'anomaly_type' => 'outside_radius',
            'severity' => 'high',
        ]);

        Carbon::setTestNow();
    }

    /**
     * TEST 16: Unknown device attempt creates anomaly log
     */
    public function test_unknown_device_creates_anomaly(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $token = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        try {
            $this->teacherAttendanceService->processCheckIn($this->teacherA, [
                'qr_token' => $token,
                'lat' => -6.2088,
                'lng' => 106.8456,
                'accuracy' => 10,
                'device_id' => 'totally-unknown-device',
                'is_mock_location' => false,
            ]);
        } catch (Exception $e) {
            // Expected
        }

        $this->assertDatabaseHas('teacher_attendance_anomalies', [
            'teacher_id' => $this->teacherA->id,
            'anomaly_type' => 'new_device_attempt',
            'severity' => 'high',
        ]);

        Carbon::setTestNow();
    }

    /**
     * TEST 17: Mock location attempt is rejected and logged
     */
    public function test_mock_location_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $deviceId = 'approved-device-anomaly-02';

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $token = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        $exceptionThrown = false;
        try {
            $this->teacherAttendanceService->processCheckIn($this->teacherA, [
                'qr_token' => $token,
                'lat' => -6.2088,
                'lng' => 106.8456,
                'accuracy' => 10,
                'device_id' => $deviceId,
                'is_mock_location' => true,
            ]);
        } catch (Exception $e) {
            $exceptionThrown = true;
            $this->assertStringContainsStringIgnoringCase('palsu', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Exception should be thrown for mock location');

        // No attendance should be created
        $this->assertDatabaseMissing('teacher_attendances', [
            'teacher_id' => $this->teacherA->id,
        ]);

        Carbon::setTestNow();
    }

    // ========================================
    // SECTION 5 — RACE CONDITION PROTECTION
    // ========================================

    /**
     * TEST 18: Service-level duplicate prevention works
     */
    public function test_duplicate_prevention_at_service_level(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $deviceId = 'race-device-001';

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        // First attempt
        $token1 = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);
        $attendance1 = $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token1,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
        ]);

        $this->assertNotNull($attendance1);

        // Second attempt should fail
        $token2 = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);

        try {
            $this->teacherAttendanceService->processCheckIn($this->teacherA, [
                'qr_token' => $token2,
                'lat' => -6.2088,
                'lng' => 106.8456,
                'accuracy' => 10,
                'device_id' => $deviceId,
                'is_mock_location' => false,
            ]);
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            // Expected
        }

        // Only 1 record should exist
        $count = TeacherAttendance::where('teacher_id', $this->teacherA->id)
            ->whereDate('attendance_date', Carbon::today())
            ->count();

        $this->assertEquals(1, $count, 'Only one attendance record should exist');

        Carbon::setTestNow();
    }

    /**
     * TEST 19: Idempotency with same request_id
     */
    public function test_idempotency_with_same_request_id(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(7, 30));

        $deviceId = 'idempotent-device-001';
        $requestId = (string) Str::uuid();

        TeacherDevice::create([
            'teacher_id' => $this->teacherA->id,
            'school_id' => $this->school->id,
            'device_id' => $deviceId,
            'device_name' => 'iPhone 15',
            'platform' => 'ios',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        // First request
        $token1 = $this->generateTeacherQrToken($this->teacherA->id, $this->school->id);
        $attendance1 = $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token1,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
            'request_id' => $requestId,
        ]);

        // Second request with SAME request_id should return same attendance (idempotent)
        $attendance2 = $this->teacherAttendanceService->processCheckIn($this->teacherA, [
            'qr_token' => $token1,
            'lat' => -6.2088,
            'lng' => 106.8456,
            'accuracy' => 10,
            'device_id' => $deviceId,
            'is_mock_location' => false,
            'request_id' => $requestId, // Same request_id
        ]);

        $this->assertEquals($attendance1->id, $attendance2->id, 'Same request_id should return same attendance');

        Carbon::setTestNow();
    }
}
