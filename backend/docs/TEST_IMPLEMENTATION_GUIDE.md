# Test Implementation Guide

**Principal QA Engineer**  
**Date**: 2026-02-10

---

## 🎯 Overview

This document provides detailed implementation guidance for the Full Integration Test Plan.

---

## 📁 Test Suite Structure

```
tests/
├── Unit/                           # Unit tests (isolated components)
│   ├── Domain/
│   │   └── Attendance/
│   │       └── AttendanceAggregateTest.php ✅
│   └── Application/
│
├── Feature/                        # Integration tests (full flow)
│   └── Attendance/
│       └── CheckInFlowTest.php ✅
│
├── Concurrency/                    # Concurrency tests
│   └── ParallelCheckInTest.php ✅
│
├── Security/                       # Security tests
│   ├── TenantIsolationTest.php ✅
│   └── SubscriptionEnforcementTest.php ✅
│
└── Resilience/                     # Failure simulation
    └── FailureSimulationTest.php ✅
```

---

## 🚀 Running Tests

### Run All Tests
```bash
php artisan test
```

### Run Specific Test Suite
```bash
# Domain tests
php artisan test tests/Unit/Domain

# Integration tests
php artisan test tests/Feature

# Concurrency tests
php artisan test tests/Concurrency

# Security tests
php artisan test tests/Security

# Resilience tests
php artisan test tests/Resilience
```

### Run by Group
```bash
# Run all domain tests
php artisan test --group=domain

# Run all concurrency tests
php artisan test --group=concurrency

# Run all security tests
php artisan test --group=security

# Run all slow tests
php artisan test --group=slow
```

### Run with Coverage
```bash
# Generate coverage report
php artisan test --coverage

# Enforce minimum coverage
php artisan test --coverage --min=85

# Generate HTML coverage report
php artisan test --coverage-html coverage
```

### Run in Parallel
```bash
# Run tests in parallel (4 processes)
php artisan test --parallel --processes=4

# Parallel with coverage
php artisan test --parallel --processes=4 --coverage
```

---

## 📊 Test Categories

### 1. Domain Tests (Unit)

**Purpose**: Test business logic in isolation

**What to test**:
- ✅ State transitions
- ✅ Business rules
- ✅ Invariants
- ✅ Domain events

**Example**:
```php
public function it_allows_valid_state_transition(): void
{
    $aggregate = AttendanceAggregate::create(...);
    $aggregate->checkIn(...);
    
    $this->assertEquals(AttendanceState::CHECKED_IN, $aggregate->getCurrentState());
}
```

**File**: `tests/Unit/Domain/Attendance/AttendanceAggregateTest.php`

---

### 2. Integration Tests (Feature)

**Purpose**: Test full flow from controller to database

**What to test**:
- ✅ Controller → Service → Handler → DB
- ✅ Event dispatching
- ✅ Read model updates
- ✅ API endpoints

**Example**:
```php
public function it_completes_full_check_in_flow(): void
{
    $service = app(AttendanceApplicationService::class);
    
    $attendance = $service->checkIn([...]);
    
    $this->assertDatabaseHas('attendances', [...]);
    Event::assertDispatched(StudentAttended::class);
}
```

**File**: `tests/Feature/Attendance/CheckInFlowTest.php`

---

### 3. Concurrency Tests

**Purpose**: Test system under concurrent load

**What to test**:
- ✅ 100+ parallel requests
- ✅ Race conditions
- ✅ Deadlock prevention
- ✅ Duplicate prevention

**Example**:
```php
public function it_prevents_duplicate_attendance_under_concurrent_load(): void
{
    // Simulate 100 concurrent check-ins
    for ($i = 0; $i < 100; $i++) {
        $promises[] = function() { /* check-in */ };
    }
    
    // Only 1 attendance record should be created
    $this->assertEquals(1, Attendance::count());
}
```

**File**: `tests/Concurrency/ParallelCheckInTest.php`

---

### 4. Security Tests

**Purpose**: Test tenant isolation and access control

**What to test**:
- ✅ Cross-tenant access prevention
- ✅ Subscription enforcement
- ✅ Quota limits
- ✅ Webhook idempotency

**Example**:
```php
public function it_prevents_cross_tenant_access(): void
{
    $this->actingAs($userSchoolA);
    
    $response = $this->getJson("/api/attendance/{$attendanceSchoolB->id}");
    
    $this->assertContains($response->status(), [403, 404]);
}
```

**Files**:
- `tests/Security/TenantIsolationTest.php`
- `tests/Security/SubscriptionEnforcementTest.php`

---

### 5. Resilience Tests

**Purpose**: Test system behavior under failure conditions

**What to test**:
- ✅ Redis down
- ✅ Database slow
- ✅ Queue stopped
- ✅ Network timeout

**Example**:
```php
public function it_falls_back_to_database_when_redis_is_down(): void
{
    Cache::shouldReceive('get')->andThrow(new \Exception('Redis failed'));
    
    $summary = $queryService->getTodaySummary($schoolId);
    
    $this->assertNotNull($summary); // Fallback to DB
}
```

**File**: `tests/Resilience/FailureSimulationTest.php`

---

## 🎯 Test Patterns

### Pattern 1: Arrange-Act-Assert

```php
public function it_does_something(): void
{
    // Arrange: Set up test data
    $student = Student::factory()->create();
    
    // Act: Perform action
    $result = $service->checkIn([...]);
    
    // Assert: Verify outcome
    $this->assertNotNull($result);
}
```

### Pattern 2: Database Assertions

```php
// Assert record exists
$this->assertDatabaseHas('attendances', [
    'student_id' => 1,
    'status' => 'present',
]);

// Assert record doesn't exist
$this->assertDatabaseMissing('attendances', [
    'student_id' => 1,
    'status' => 'absent',
]);

// Assert count
$this->assertDatabaseCount('attendances', 10);
```

### Pattern 3: Event Assertions

```php
Event::fake();

// Perform action
$service->checkIn([...]);

// Assert event dispatched
Event::assertDispatched(StudentAttended::class);

// Assert event not dispatched
Event::assertNotDispatched(SomeOtherEvent::class);

// Assert event with callback
Event::assertDispatched(StudentAttended::class, function ($event) {
    return $event->attendance->student_id === 1;
});
```

### Pattern 4: API Testing

```php
// Authenticated request
$this->actingAs($user, 'sanctum');

$response = $this->postJson('/api/attendance/check-in', [
    'student_id' => 1,
]);

// Assert status
$response->assertStatus(200);

// Assert JSON structure
$response->assertJsonStructure([
    'success',
    'data' => ['id', 'student_id'],
]);

// Assert JSON content
$response->assertJson([
    'success' => true,
]);
```

---

## 🔧 Test Utilities

### Factory Usage

```php
// Create single model
$student = Student::factory()->create();

// Create multiple models
$students = Student::factory()->count(10)->create();

// Create with attributes
$student = Student::factory()->create([
    'school_id' => 1,
    'name' => 'John Doe',
]);

// Create without saving
$student = Student::factory()->make();
```

### Database Transactions

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class MyTest extends TestCase
{
    use RefreshDatabase; // Automatically rollback after each test
    
    public function test_something(): void
    {
        // Database changes are rolled back after this test
    }
}
```

### Time Manipulation

```php
use Illuminate\Support\Facades\Date;

// Travel to specific time
$this->travel(5)->hours();

// Travel to specific date
Date::setTestNow('2026-02-10 08:00:00');

// Travel back
Date::setTestNow();
```

---

## 📈 Coverage Goals

| Layer | Target Coverage | Priority |
|-------|----------------|----------|
| Domain Layer | >95% | Critical |
| Application Services | >90% | High |
| Read Models | >85% | High |
| Controllers | >80% | Medium |
| **Overall** | **>85%** | **Required** |

---

## 🚨 Common Pitfalls

### ❌ Don't: Test implementation details
```php
// Bad
$this->assertTrue($aggregate->internalMethod());
```

### ✅ Do: Test behavior
```php
// Good
$this->assertEquals(AttendanceState::CHECKED_IN, $aggregate->getCurrentState());
```

### ❌ Don't: Create dependencies between tests
```php
// Bad - Test B depends on Test A
public function test_a() { $this->userId = 1; }
public function test_b() { User::find($this->userId); }
```

### ✅ Do: Make tests independent
```php
// Good - Each test is independent
public function test_a() { $user = User::factory()->create(); }
public function test_b() { $user = User::factory()->create(); }
```

### ❌ Don't: Use sleep() in tests
```php
// Bad
sleep(5);
```

### ✅ Do: Use time travel or mocks
```php
// Good
$this->travel(5)->seconds();
```

---

## 🎓 Best Practices

1. **One assertion per test** (when possible)
2. **Descriptive test names** (`it_prevents_duplicate_attendance`)
3. **Use factories** for test data
4. **Clean up after tests** (use RefreshDatabase)
5. **Mock external services**
6. **Test edge cases**
7. **Keep tests fast** (<100ms per test)
8. **Group related tests** (`@group domain`)

---

## 📝 Test Checklist

Before marking a feature complete:

- [ ] Unit tests written
- [ ] Integration tests written
- [ ] Edge cases covered
- [ ] Error cases covered
- [ ] Coverage >85%
- [ ] All tests pass
- [ ] No flaky tests
- [ ] Tests run in <5 minutes

---

## 🔍 Debugging Tests

### Run single test
```bash
php artisan test --filter=it_prevents_duplicate_attendance
```

### Run with verbose output
```bash
php artisan test --verbose
```

### Run with debug output
```bash
php artisan test --debug
```

### Stop on failure
```bash
php artisan test --stop-on-failure
```

---

## 📚 Resources

- [Laravel Testing Documentation](https://laravel.com/docs/testing)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Pest PHP](https://pestphp.com/) (alternative test framework)

---

**Status**: ✅ Ready for Implementation  
**Coverage Target**: >85%  
**Test Count**: 100+ test cases
