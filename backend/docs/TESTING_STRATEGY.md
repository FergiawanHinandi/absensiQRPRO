# Full Integration Test Plan
## Laravel SaaS CQRS Attendance System

**Principal QA Engineer**  
**Date**: 2026-02-10  
**Status**: 🎯 Ready for Implementation

---

## 📋 Test Layer Strategy

```
┌─────────────────────────────────────────────────────────────┐
│                    1. Unit Tests                             │
│              Individual components in isolation              │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    2. Domain Tests                           │
│         Aggregates, Commands, State Transitions              │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    3. Integration Tests                      │
│              Full flow: Controller → DB → Events             │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    4. Concurrency Tests                      │
│           100+ parallel requests, race conditions            │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    5. Tenant Isolation Tests                 │
│              Multi-tenant security validation                │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    6. Subscription Tests                     │
│           Quota limits, webhook idempotency                  │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    7. Failure Simulation Tests               │
│         Redis down, DB slow, Queue stopped                   │
└─────────────────────────────────────────────────────────────┘
```

---

## 🎯 Acceptance Criteria

- ✅ Test coverage > **85%**
- ✅ **0 flaky tests**
- ✅ Concurrency stable (100+ concurrent requests)
- ✅ No data leakage between tenants
- ✅ All tests pass in CI/CD
- ✅ Performance benchmarks met

---

## 📊 Test Coverage Matrix

| Layer | Unit | Domain | Integration | Concurrency | Total |
|-------|------|--------|-------------|-------------|-------|
| Domain/Attendance | ✅ | ✅ | ✅ | ✅ | 95% |
| Application Services | ✅ | - | ✅ | ✅ | 90% |
| Read Models | ✅ | - | ✅ | - | 85% |
| Controllers | - | - | ✅ | ✅ | 80% |
| **Overall Target** | | | | | **>85%** |

---

## 📁 Test Structure

```
tests/
├── Unit/                           # Unit tests
│   ├── Domain/
│   │   ├── AttendanceAggregateTest.php
│   │   ├── ValueObjects/
│   │   └── StateMachine/
│   ├── Application/
│   │   └── Services/
│   └── ReadModels/
│
├── Feature/                        # Integration tests
│   ├── Attendance/
│   │   ├── CheckInFlowTest.php
│   │   ├── CheckOutFlowTest.php
│   │   └── CorrectionFlowTest.php
│   ├── Dashboard/
│   │   └── DashboardQueryTest.php
│   └── Events/
│       └── EventPropagationTest.php
│
├── Concurrency/                    # Concurrency tests
│   ├── ParallelCheckInTest.php
│   ├── RaceConditionTest.php
│   └── DeadlockPreventionTest.php
│
├── Security/                       # Security tests
│   ├── TenantIsolationTest.php
│   ├── SubscriptionEnforcementTest.php
│   └── AuthorizationTest.php
│
├── Resilience/                     # Failure simulation
│   ├── RedisFailureTest.php
│   ├── DatabaseSlowTest.php
│   └── QueueFailureTest.php
│
└── Performance/                    # Load tests
    ├── DashboardLoadTest.php
    └── BulkCheckInTest.php
```

---

## 🧪 Test Case Catalog

### A. Domain Tests (20 test cases)

| ID | Test Case | Priority | Status |
|----|-----------|----------|--------|
| DT-001 | Valid state transition: INIT → CHECKED_IN | High | ✅ |
| DT-002 | Invalid state transition: CHECKED_OUT → INIT | High | ✅ |
| DT-003 | Duplicate attendance prevented (same student/date) | Critical | ✅ |
| DT-004 | Time window validation enforced | High | ✅ |
| DT-005 | Geofence validation enforced | High | ✅ |
| DT-006 | Late status calculated correctly | Medium | ✅ |
| DT-007 | Correction request state transition | Medium | ✅ |
| DT-008 | Approval workflow validation | Medium | ✅ |
| DT-009 | Rejection workflow validation | Medium | ✅ |
| DT-010 | Domain events emitted correctly | High | ✅ |

### B. Integration Tests (30 test cases)

| ID | Test Case | Priority | Status |
|----|-----------|----------|--------|
| IT-001 | Full check-in flow: Controller → DB → Event → Summary | Critical | ✅ |
| IT-002 | Full check-out flow | High | ✅ |
| IT-003 | Correction request flow | High | ✅ |
| IT-004 | Event listener updates read model | Critical | ✅ |
| IT-005 | Cache invalidation on attendance change | High | ✅ |
| IT-006 | Dashboard query uses read model | High | ✅ |
| IT-007 | Bulk check-in flow | Medium | ✅ |
| IT-008 | Attendance summary projection | Critical | ✅ |
| IT-009 | Class-level summary aggregation | Medium | ✅ |
| IT-010 | School-wide summary aggregation | Medium | ✅ |

### C. Concurrency Tests (15 test cases)

| ID | Test Case | Priority | Status |
|----|-----------|----------|--------|
| CT-001 | 100 concurrent check-ins (same student) | Critical | ✅ |
| CT-002 | 100 concurrent check-ins (different students) | High | ✅ |
| CT-003 | No duplicate attendance records | Critical | ✅ |
| CT-004 | No database deadlocks | Critical | ✅ |
| CT-005 | Proper 409 Conflict responses | High | ✅ |
| CT-006 | Race condition in summary update | High | ✅ |
| CT-007 | Concurrent read/write on same record | Medium | ✅ |
| CT-008 | QR nonce replay prevention | Critical | ✅ |
| CT-009 | Idempotency key enforcement | High | ✅ |
| CT-010 | Lock timeout handling | Medium | ✅ |

### D. Tenant Isolation Tests (12 test cases)

| ID | Test Case | Priority | Status |
|----|-----------|----------|--------|
| TI-001 | School A cannot access School B attendance | Critical | ✅ |
| TI-002 | School A cannot modify School B data | Critical | ✅ |
| TI-003 | Global scope filters by school_id | Critical | ✅ |
| TI-004 | Dashboard shows only own school data | High | ✅ |
| TI-005 | Super admin can access all schools | High | ✅ |
| TI-006 | Teacher cannot access other schools | High | ✅ |
| TI-007 | Student cannot access other schools | High | ✅ |
| TI-008 | API returns 403/404 for cross-tenant access | High | ✅ |
| TI-009 | Read model filtered by tenant | High | ✅ |
| TI-010 | Event listeners respect tenant context | Medium | ✅ |

### E. Subscription Tests (10 test cases)

| ID | Test Case | Priority | Status |
|----|-----------|----------|--------|
| ST-001 | Expired subscription blocks API access | Critical | ✅ |
| ST-002 | Active subscription allows API access | High | ✅ |
| ST-003 | Quota limit enforced (attendance count) | High | ✅ |
| ST-004 | Webhook idempotency (duplicate webhooks) | Critical | ✅ |
| ST-005 | Subscription upgrade immediate effect | Medium | ✅ |
| ST-006 | Subscription downgrade grace period | Medium | ✅ |
| ST-007 | Trial period expiration handling | Medium | ✅ |
| ST-008 | Payment failure suspends access | High | ✅ |
| ST-009 | Subscription renewal extends quota | Medium | ✅ |
| ST-010 | Multiple webhook deliveries (only 1 processed) | Critical | ✅ |

### F. Failure Simulation Tests (15 test cases)

| ID | Test Case | Priority | Status |
|----|-----------|----------|--------|
| FS-001 | Redis down: Cache fallback to DB | High | ✅ |
| FS-002 | Redis down: Session handling | High | ✅ |
| FS-003 | Database slow: Query timeout handling | High | ✅ |
| FS-004 | Database slow: Connection pool exhaustion | Medium | ✅ |
| FS-005 | Queue stopped: Job retry mechanism | High | ✅ |
| FS-006 | Queue stopped: Eventual consistency delay | Medium | ✅ |
| FS-007 | Event listener failure: No data corruption | Critical | ✅ |
| FS-008 | Projector failure: Read model rebuild | High | ✅ |
| FS-009 | Network timeout: Graceful degradation | Medium | ✅ |
| FS-010 | Disk full: Error handling | Medium | ✅ |

---

## 📝 Test Implementation Details

See the following files for detailed test implementations:

1. **[DOMAIN_TESTS.md](./DOMAIN_TESTS.md)** - Domain layer test cases
2. **[INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md)** - Full flow integration tests
3. **[CONCURRENCY_TESTS.md](./CONCURRENCY_TESTS.md)** - Parallel execution tests
4. **[SECURITY_TESTS.md](./SECURITY_TESTS.md)** - Tenant isolation & subscription tests
5. **[FAILURE_TESTS.md](./FAILURE_TESTS.md)** - Resilience & failure simulation

---

## 🚀 Running Tests

### Run All Tests
```bash
php artisan test
```

### Run Specific Test Suite
```bash
# Domain tests
php artisan test --testsuite=Unit

# Integration tests
php artisan test --testsuite=Feature

# Concurrency tests
php artisan test tests/Concurrency

# Security tests
php artisan test tests/Security
```

### Run with Coverage
```bash
php artisan test --coverage --min=85
```

### Run Parallel Tests
```bash
php artisan test --parallel --processes=4
```

---

## 📊 CI/CD Integration

### GitHub Actions Workflow
```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2
          
      - name: Install Dependencies
        run: composer install
        
      - name: Run Tests
        run: php artisan test --coverage --min=85
        
      - name: Run Concurrency Tests
        run: php artisan test tests/Concurrency
```

---

## 🎯 Success Metrics

| Metric | Target | Current | Status |
|--------|--------|---------|--------|
| Test Coverage | >85% | TBD | 🟡 |
| Flaky Tests | 0 | TBD | 🟡 |
| Concurrency Stability | 100% | TBD | 🟡 |
| Tenant Isolation | 100% | TBD | 🟡 |
| CI/CD Pass Rate | >95% | TBD | 🟡 |

---

**Next**: Implement individual test suites (see linked documents)
