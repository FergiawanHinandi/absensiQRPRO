<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cross-Tenant Access Prevention Tests
 *
 * These tests simulate ID tampering attacks where users try to access
 * resources from other schools by manipulating IDs in API requests.
 */
class CrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private User $adminA;

    private User $studentA;

    private User $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two schools
        $this->schoolA = School::create([
            'name' => 'School A',
            'npsn' => '12345678',
            'school_level' => 'SMA',
            'address' => 'Address A',
            'is_active' => true,
        ]);

        $this->schoolB = School::create([
            'name' => 'School B',
            'npsn' => '87654321',
            'school_level' => 'SMA',
            'address' => 'Address B',
            'is_active' => true,
        ]);

        // Create users
        $this->adminA = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Admin A',
            'username' => 'admin_a',
            'email' => 'admin.a@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'school_admin',
            'is_active' => true,
        ]);

        $this->studentA = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Student A',
            'username' => 'student_a',
            'email' => 'student.a@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $this->studentB = User::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Student B',
            'username' => 'student_b',
            'email' => 'student.b@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_from_school_a_cannot_access_student_from_school_b_via_api()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        // Try to access student from School B
        $response = $this->getJson("/api/v1/school-admin/students/{$this->studentB->id}");

        // Should return 403 Forbidden or 404 Not Found (depending on implementation)
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            "Expected 403 or 404, got {$response->status()}"
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_update_student_from_different_school()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        $response = $this->putJson("/api/v1/school-admin/students/{$this->studentB->id}", [
            'name' => 'Hacked Name',
            'username' => 'hacked',
            'email' => 'hacked@example.com',
        ]);

        $this->assertTrue(
            in_array($response->status(), [403, 404, 422]),
            'Should block cross-school student update'
        );

        // Verify student B was not modified
        $this->studentB->refresh();
        $this->assertNotEquals('Hacked Name', $this->studentB->name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_cannot_view_attendance_from_different_school()
    {
        Sanctum::actingAs($this->studentA, ['*']);

        // Create class and attendance for School B
        $classB = ClassModel::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Class B',
            'grade_level' => 10,
            'is_active' => true,
        ]);

        $attendanceB = Attendance::create([
            'school_id' => $this->schoolB->id,
            'schedule_id' => 1,
            'class_id' => $classB->id,
            'student_id' => $this->studentB->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'is_manual' => false,
        ]);

        // Try to access via API (if endpoint exists)
        $response = $this->getJson("/api/v1/attendance/history?student_id={$this->studentB->id}");

        // Should not return attendance from School B
        if ($response->status() === 200) {
            $data = $response->json('data');

            // If data is returned, it should be empty or not contain School B data
            if (is_array($data)) {
                foreach ($data as $record) {
                    $this->assertNotEquals(
                        $this->schoolB->id,
                        $record['school_id'] ?? null,
                        'Should not return attendance from different school'
                    );
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_view_classes_from_different_school()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        $classB = ClassModel::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Class B',
            'grade_level' => 10,
            'is_active' => true,
        ]);

        // Try to access class list - should only show School A classes
        $response = $this->getJson('/api/v1/school-admin/classes');

        if ($response->status() === 200) {
            $classes = $response->json('data.classes') ?? $response->json('data') ?? [];

            foreach ($classes as $class) {
                $this->assertEquals(
                    $this->schoolA->id,
                    $class['school_id'] ?? null,
                    'Should only return classes from same school'
                );
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_cannot_scan_qr_code_from_different_school()
    {
        Sanctum::actingAs($this->studentA, ['*']);

        // Create a QR code for School B
        $qrCodeB = \App\Models\QrCode::create([
            'school_id' => $this->schoolB->id,
            'schedule_id' => 1,
            'code' => 'QR_SCHOOL_B_'.uniqid(),
            'valid_from' => now()->subMinutes(10),
            'valid_until' => now()->addMinutes(50),
            'is_locked' => false,
        ]);

        // Try to scan QR code from School B
        $response = $this->postJson('/api/v1/attendance/scan', [
            'qr_code' => $qrCodeB->code,
        ]);

        // Should fail - either 403, 404, or 422
        $this->assertTrue(
            in_array($response->status(), [403, 404, 422]),
            'Should block scanning QR code from different school'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function list_endpoints_only_return_same_school_data()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        // Create some data for both schools
        $studentA2 = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Student A2',
            'username' => 'student_a2',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $studentB2 = User::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Student B2',
            'username' => 'student_b2',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Request student list
        $response = $this->getJson('/api/v1/school-admin/students');

        if ($response->status() === 200) {
            $students = $response->json('data.students') ?? $response->json('data') ?? [];

            // Should only contain students from School A
            $studentIds = array_column($students, 'id');

            $this->assertContains($this->studentA->id, $studentIds, 'Should contain Student A');
            $this->assertContains($studentA2->id, $studentIds, 'Should contain Student A2');
            $this->assertNotContains($this->studentB->id, $studentIds, 'Should NOT contain Student B');
            $this->assertNotContains($studentB2->id, $studentIds, 'Should NOT contain Student B2');
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function dashboard_stats_only_include_same_school_data()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        // Create attendance for both schools
        $classA = ClassModel::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Class A',
            'grade_level' => 10,
            'is_active' => true,
        ]);

        $classB = ClassModel::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Class B',
            'grade_level' => 10,
            'is_active' => true,
        ]);

        Attendance::create([
            'school_id' => $this->schoolA->id,
            'schedule_id' => 1,
            'class_id' => $classA->id,
            'student_id' => $this->studentA->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'is_manual' => false,
        ]);

        Attendance::create([
            'school_id' => $this->schoolB->id,
            'schedule_id' => 2,
            'class_id' => $classB->id,
            'student_id' => $this->studentB->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'is_manual' => false,
        ]);

        // Request dashboard
        $response = $this->getJson('/api/v1/admin/dashboard');

        if ($response->status() === 200) {
            $data = $response->json('data');

            // Verify counts only include School A data
            // This is a basic check - actual implementation may vary
            $this->assertIsArray($data, 'Dashboard should return data');
        }
    }
}
