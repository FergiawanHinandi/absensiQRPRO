<?php

namespace Tests\Feature;

use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\QrCode\CloseQrRequest;
use App\Http\Requests\QrCode\GenerateQrRequest;
use App\Http\Requests\Report\ExportReportRequest;
use App\Http\Requests\Report\MonthlySummaryRequest;
use App\Http\Requests\SchoolAdmin\StoreClassRequest;
use App\Http\Requests\SchoolAdmin\StoreStudentRequest;
use App\Http\Requests\SchoolAdmin\StoreTeacherRequest;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class FormRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected $school;

    protected $admin;

    protected $teacher;

    protected $student;

    protected $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test school
        $this->school = School::factory()->create([
            'name' => 'Test School',
            'package_type' => 'standard',
            'max_students' => 500,
            'max_teachers' => 50,
            'max_classes' => 20,
        ]);

        // Create test admin
        $this->admin = User::factory()->create([
            'role_type' => 'school_admin',
            'school_id' => $this->school->id,
            'email' => 'admin@test.com',
        ]);

        // Create test teacher
        $this->teacher = User::factory()->create([
            'role_type' => 'teacher',
            'school_id' => $this->school->id,
            'email' => 'teacher@test.com',
        ]);

        // Create test student
        $this->student = User::factory()->create([
            'role_type' => 'student',
            'school_id' => $this->school->id,
            'email' => 'student@test.com',
        ]);

        // Create academic year using factory
        $this->academicYear = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'name' => '2024/2025',
            'start_date' => '2024-07-01',
            'end_date' => '2025-06-30',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function store_teacher_request_validates_required_fields()
    {
        $request = new StoreTeacherRequest;
        $request->setUserResolver(function () {
            return $this->admin;
        });

        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
        $this->assertArrayHasKey('password', $validator->errors()->toArray());
        $this->assertArrayHasKey('gender', $validator->errors()->toArray());
    }

    /** @test */
    public function store_teacher_request_validates_email_format()
    {
        $request = new StoreTeacherRequest;
        $request->setUserResolver(function () {
            return $this->admin;
        });

        $data = [
            'name' => 'Test Teacher',
            'email' => 'invalid-email',
            'password' => 'password123',
            'gender' => 'L',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    /** @test */
    public function store_teacher_request_validates_gender_values()
    {
        $request = new StoreTeacherRequest;
        $request->setUserResolver(function () {
            return $this->admin;
        });

        $data = [
            'name' => 'Test Teacher',
            'email' => 'teacher@example.com',
            'password' => 'password123',
            'gender' => 'X', // Invalid gender
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('gender', $validator->errors()->toArray());
    }

    /** @test */
    public function store_student_request_validates_required_fields()
    {
        $request = new StoreStudentRequest;
        $request->setUserResolver(function () {
            return $this->admin;
        });

        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
        $this->assertArrayHasKey('nis', $validator->errors()->toArray());
        $this->assertArrayHasKey('gender', $validator->errors()->toArray());
        $this->assertArrayHasKey('class_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('password', $validator->errors()->toArray());
    }

    /** @test */
    public function store_student_request_validates_nis_format()
    {
        $request = new StoreStudentRequest;
        $request->setUserResolver(function () {
            return $this->admin;
        });

        $data = [
            'name' => 'Test Student',
            'email' => 'student@example.com',
            'nis' => 'ABC123', // Should be numbers only
            'gender' => 'L',
            'class_id' => 1,
            'password' => 'password123',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('nis', $validator->errors()->toArray());
    }

    /** @test */
    public function store_class_request_validates_required_fields()
    {
        $request = new StoreClassRequest;
        $request->setUserResolver(function () {
            return $this->admin;
        });

        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('grade_level', $validator->errors()->toArray());
    }

    /** @test */
    public function store_class_request_validates_grade_level_range()
    {
        $request = new StoreClassRequest;
        $request->setUserResolver(function () {
            return $this->admin;
        });

        $data = [
            'name' => 'Test Class',
            'grade_level' => 15, // Should be 1-12
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('grade_level', $validator->errors()->toArray());
    }

    /** @test */
    public function generate_qr_request_validates_required_fields()
    {
        $request = new GenerateQrRequest;
        $request->setUserResolver(function () {
            return $this->teacher;
        });

        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('schedule_id', $validator->errors()->toArray());
    }

    /** @test */
    public function close_qr_request_validates_required_fields()
    {
        $request = new CloseQrRequest;
        $request->setUserResolver(function () {
            return $this->teacher;
        });

        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('qr_code_id', $validator->errors()->toArray());
    }

    /** @test */
    public function store_permission_request_validates_required_fields()
    {
        $request = new StorePermissionRequest;
        $request->setUserResolver(function () {
            return $this->student;
        });

        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('type', $validator->errors()->toArray());
        $this->assertArrayHasKey('reason', $validator->errors()->toArray());
        $this->assertArrayHasKey('start_date', $validator->errors()->toArray());
        $this->assertArrayHasKey('end_date', $validator->errors()->toArray());
    }

    /** @test */
    public function store_permission_request_validates_permission_type()
    {
        $request = new StorePermissionRequest;
        $request->setUserResolver(function () {
            return $this->student;
        });

        $data = [
            'type' => 'invalid_type', // Should be 'sick' or 'permit'
            'reason' => 'Test reason',
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-01',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('type', $validator->errors()->toArray());
    }

    /** @test */
    public function export_report_request_validates_date_range()
    {
        $request = new ExportReportRequest;
        $request->setUserResolver(function () {
            return $this->admin;
        });

        $data = [
            'start_date' => '2024-01-10',
            'end_date' => '2024-01-05', // End date before start date
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('end_date', $validator->errors()->toArray());
    }

    /** @test */
    public function monthly_summary_request_validates_month_range()
    {
        $request = new MonthlySummaryRequest;
        $request->setUserResolver(function () {
            return $this->admin;
        });

        $data = [
            'class_id' => 1,
            'month' => 15, // Should be 1-12
            'year' => 2024,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('month', $validator->errors()->toArray());
    }

    /** @test */
    public function form_requests_have_proper_authorization()
    {
        // Test StoreTeacherRequest authorization
        $teacherRequest = new StoreTeacherRequest;
        $teacherRequest->setUserResolver(function () {
            return $this->admin;
        });
        $this->assertTrue($teacherRequest->authorize());

        // Test with unauthorized user (student trying to create teacher)
        $teacherRequest->setUserResolver(function () {
            return $this->student;
        });
        $this->assertFalse($teacherRequest->authorize());

        // Test StoreStudentRequest authorization
        $studentRequest = new StoreStudentRequest;
        $studentRequest->setUserResolver(function () {
            return $this->admin;
        });
        $this->assertTrue($studentRequest->authorize());

        // Test StoreClassRequest authorization
        $classRequest = new StoreClassRequest;
        $classRequest->setUserResolver(function () {
            return $this->admin;
        });
        $this->assertTrue($classRequest->authorize());
    }

    /** @test */
    public function form_requests_have_custom_messages()
    {
        $request = new StoreTeacherRequest;
        $messages = $request->messages();

        $this->assertIsArray($messages);
        $this->assertArrayHasKey('name.required', $messages);
        $this->assertArrayHasKey('email.required', $messages);
        $this->assertArrayHasKey('email.email', $messages);
        $this->assertArrayHasKey('gender.required', $messages);
        $this->assertArrayHasKey('password.required', $messages);
    }

    /** @test */
    public function form_requests_have_custom_attributes()
    {
        $request = new StoreStudentRequest;
        $attributes = $request->attributes();

        $this->assertIsArray($attributes);
        $this->assertArrayHasKey('name', $attributes);
        $this->assertArrayHasKey('email', $attributes);
        $this->assertArrayHasKey('nis', $attributes);
        $this->assertArrayHasKey('gender', $attributes);
    }
}
