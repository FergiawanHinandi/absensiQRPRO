# FormRequest Migration Guide

## Objective
Refactor all controller validation from `$request->validate()` to dedicated FormRequest classes for better organization, reusability, and testing.

---

## Why FormRequest?

### Current Problem
```php
// Controller is cluttered with validation logic
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:6',
        // ... many more rules
    ]);
    
    // Business logic
}
```

### Solution with FormRequest
```php
// Clean controller
public function store(StoreUserRequest $request)
{
    // Validation already done!
    $validated = $request->validated();
    
    // Business logic only
}
```

### Benefits
1. **Separation of Concerns** - Validation logic isolated
2. **Reusability** - Same validation rules across multiple controllers
3. **Testability** - Easy to unit test validation rules
4. **Authorization** - Built-in `authorize()` method
5. **Custom Messages** - Centralized error messages
6. **Clean Controllers** - Controllers focus on business logic

---

## Implementation Pattern

### Step 1: Create FormRequest Class

```bash
php artisan make:request StoreStudentRequest
```

### Step 2: Define Rules

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user has permission
        return $this->user()->can('create', Student::class);
        
        // Or simple role check
        // return $this->user()->role_type === 'admin';
        
        // Or always true if authorization handled elsewhere
        // return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'nis' => 'required|string|unique:users,nis',
            'class_id' => 'required|exists:classes,id',
            'gender' => 'required|in:L,P',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama siswa wajib diisi',
            'email.required' => 'Email wajib diisi',
            'email.email' => 'Format email tidak valid',
            'email.unique' => 'Email sudah terdaftar',
            'nis.unique' => 'NIS sudah terdaftar',
            'class_id.exists' => 'Kelas tidak ditemukan',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'alamat email',
            'nis' => 'nomor induk siswa',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Add school_id from authenticated user
        if ($this->user()) {
            $this->merge([
                'school_id' => $this->user()->school_id,
            ]);
        }

        // Clean/format data
        if ($this->has('phone')) {
            $this->merge([
                'phone' => preg_replace('/[^0-9]/', '', $this->phone),
            ]);
        }
    }

    /**
     * Handle a passed validation attempt.
     */
    protected function passedValidation(): void
    {
        // Additional processing after validation passes
        // Log, track, etc.
    }
}
```

### Step 3: Update Controller

**Before:**
```php
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        // ... more rules
    ]);

    $student = Student::create($validated);
    return response()->json($student, 201);
}
```

**After:**
```php
public function store(StoreStudentRequest $request)
{
    // Validation and authorization already handled!
    $student = Student::create($request->validated());
    
    return response()->json($student, 201);
}
```

---

## Common Patterns

### Pattern 1: Update Request (Different Rules for Update)

```php
class UpdateStudentRequest extends FormRequest
{
    public function rules(): array
    {
        $studentId = $this->route('student'); // Get ID from route

        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('users')->ignore($studentId),
            ],
            'nis' => [
                'sometimes',
                'required',
                'string',
                Rule::unique('users')->ignore($studentId),
            ],
            // Other fields are optional on update
            'class_id' => 'sometimes|exists:classes,id',
            'gender' => 'sometimes|in:L,P',
        ];
    }
}
```

### Pattern 2: School Scoping in Authorization

```php
public function authorize(): bool
{
    // Ensure school_id matches authenticated user's school
    if ($this->has('school_id')) {
        return $this->school_id === $this->user()->school_id;
    }

    return true;
}

protected function prepareForValidation(): void
{
    // Force school_id to user's school
    $this->merge([
        'school_id' => $this->user()->school_id,
    ]);
}
```

### Pattern 3: Conditional Validation

```php
public function rules(): array
{
    $rules = [
        'name' => 'required|string|max:255',
        'type' => 'required|in:sick,permit',
    ];

    // Different rules based on type
    if ($this->type === 'sick') {
        $rules['medical_certificate'] = 'required|file|mimes:pdf,jpg,png|max:2048';
    }

    if ($this->type === 'permit') {
        $rules['reason'] = 'required|string|max:500';
    }

    return $rules;
}
```

### Pattern 4: Array Validation (Bulk Operations)

```php
class BulkStoreStudentsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'students' => 'required|array|min:1|max:100',
            'students.*.name' => 'required|string|max:255',
            'students.*.email' => 'required|email|distinct',
            'students.*.nis' => 'required|string|distinct',
            'students.*.class_id' => 'required|exists:classes,id',
        ];
    }

    public function messages(): array
    {
        return [
            'students.*.email.distinct' => 'Email pada baris :position sudah ada dalam data import',
            'students.*.nis.distinct' => 'NIS pada baris :position sudah ada dalam data import',
        ];
    }
}
```

---

## Migration Checklist

### Controllers to Migrate

#### Admin Controllers
- [ ] `AdminStudentController` - create, update, import
- [ ] `AdminTeacherController` - create, update, import
- [ ] `AdminClassController` - create, update
- [ ] `AdminSubjectController` - create, update
- [ ] `AdminScheduleController` - create, update
- [ ] `AdminParentController` - create, update
- [ ] `AdminAttendanceController` - manual create
- [ ] `AdminSchoolSettingsController` - update settings

#### Teacher Controllers
- [ ] `TeacherAttendanceController` - manual attendance
-[ ] `TeacherPermissionController` - approve, create

#### Parent Controllers
- [ ] `ParentPermissionController` - create permission request

#### Super Admin Controllers
- [ ] `SuperAdmin\SchoolController` - create, update
- [ ] `SuperAdmin\UserController` - create admin
- [ ] `SuperAdmin\PackageController` - update package

### Common FormRequests Needed

1. **User Management**
   - `StoreUserRequest`
   - `UpdateUserRequest`
   - `BulkImportUsersRequest`

2. **Student Management**
   - `StoreStudentRequest`
   - `UpdateStudentRequest`
   - `ImportStudentsRequest`

3. **Teacher Management**
   - `StoreTeacherRequest`
   - `UpdateTeacherRequest`
   - `AssignTeacherRequest`

4. **Class Management**
   - `StoreClassRequest`
   - `UpdateClassRequest`

5. **Schedule Management**
   - `StoreScheduleRequest`
   - `UpdateScheduleRequest`

6. **Attendance**
   - `StoreManualAttendanceRequest`
   - `BulkAttendanceRequest`
   - `ScanQRAttendanceRequest`

7. **Permission Requests**
   - `StorePermissionRequest`
   - `ApprovePermissionRequest`

8. **School Settings**
   - `UpdateSchoolSettingsRequest`
   - `UpdateAttendanceSettingsRequest`

---

## Testing FormRequests

```php
<?php

namespace Tests\Unit\Requests;

use Tests\TestCase;
use App\Http\Requests\StoreStudentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class StoreStudentRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_data()
    {
        $user = User::factory()->create(['role_type' => 'admin']);
        
        $request = StoreStudentRequest::create('/api/v1/admin/students', 'POST', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'nis' => '12345',
            'class_id' => 1,
            'gender' => 'L',
        ]);

        $request->setUserResolver(fn() => $user);

        $this->assertTrue($request->authorize());
        $validator = validator($request->all(), $request->rules());
        $this->assertFalse($validator->fails());
    }

    public function test_validation_fails_without_name()
    {
        $request = new StoreStudentRequest();
        $request->merge([
            'email' => 'john@example.com',
            // name is missing
        ]);

        $validator = validator($request->all(), $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('name'));
    }
}
```

---

## Benefits Summary

### Before (Current State)
- ❌ Validation scattered across controllers
- ❌ Duplicate validation logic
- ❌ Hard to test
- ❌ No centralized authorization
- ❌ Controllers too large

### After (With FormRequests)
- ✅ Centralized validation logic
- ✅ Reusable across controllers
- ✅ Easy to unit test
- ✅ Built-in authorization
- ✅ Clean, focused controllers
- ✅ Better error messages
- ✅ Type hinting and IDE support

---

## Quick Migration Script

```bash
#!/bin/bash
# migrate-to-form-requests.sh

# Create directories
mkdir -p app/Http/Requests/Admin
mkdir -p app/Http/Requests/Teacher
mkdir -p app/Http/Requests/Parent
mkdir -p app/Http/Requests/Student
mkdir -p app/Http/Requests/SuperAdmin

# Generate FormRequests
php artisan make:request Admin/StoreStudentRequest
php artisan make:request Admin/UpdateStudentRequest
php artisan make:request Admin/StoreTeacherRequest
php artisan make:request Admin/UpdateTeacherRequest
php artisan make:request Admin/StoreClassRequest
php artisan make:request Admin/StoreScheduleRequest
php artisan make:request Admin/ManualAttendanceRequest

php artisan make:request Teacher/StorePermissionRequest
php artisan make:request Teacher/ApprovePermissionRequest

php artisan make:request Student/ScanQRRequest

echo "FormRequest classes created. Now update the rules and controllers!"
```

---

## Next Steps

1. **Create FormRequests** - Use artisan command to generate
2. **Move validation rules** - Copy from controllers to FormRequests
3. **Add authorization** - Implement `authorize()` method
4. **Update controllers** - Replace Request with FormRequest
5. **Test thoroughly** - Ensure validation still works
6. **Remove old validation** - Clean up controller code

**Estimated Time:** 4-6 hours for complete migration
**Priority:** High - Improves code quality significantly
