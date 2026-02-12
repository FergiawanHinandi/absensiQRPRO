# Technical Debt Inventory & Refactoring Roadmap

## Executive Summary
**Date:** 2026-02-11  
**Project:** AbsensiQR Pro  
**Status:** Transitioning from Monolithic Layered Architecture to Event-Driven DDD.

The codebase is currently in a "hybrid" state. While significant progress has been made towards Domain-Driven Design (DDD) and Command Query Responsibility Segregation (CQRS), large legacy services and mixed architectural patterns pose risks to maintainability and scalability. The most critical debt lies in the **complexity of the `AttendanceCheckInService`** and the **ambiguity of the mobile project structure**.

---

## 1. Technical Debt Inventory

### A. Code Quality Metrics
| ID | Item | Location | Severity | Description |
|----|------|----------|----------|-------------|
| **CQ-01** | **God Class / High Coupling** | `AttendanceCheckInService` | **Critical** | This service has 12+ dependencies injected. It handles everything from validation, database locking, logging, notifications, to gamification points. Testing this in isolation is extremely difficult. |
| **CQ-02** | **Cyclomatic Complexity** | `AttendanceCheckInService::checkIn` | **High** | The `checkIn` method and its private helpers (`atomicCheckIn`, `validateQrTimestamp`) contain deep nesting, extensive error handling branching, and complex race condition logic. |
| **CQ-03** | **Logic Duplication** | `AttendanceService` vs `AttendanceCheckInService` | **Medium** | `AttendanceService` seems to still hold some check-in logic (legacy) or reporting logic that overlaps with the newer, cleaner services. |
| **CQ-04** | **Magic Strings & Arrays** | `AttendanceController` | **Low** | Request validation often relies on array keys (`$data['lat']`) rather than typed Data Transfer Objects (DTOs) passed from Controller to Service. |

### B. Architecture Violations
| ID | Item | Location | Severity | Description |
|----|------|----------|----------|-------------|
| **AR-01** | **Leaky Abstractions (Service Layer)** | `App\Services` | **High** | Services are doing "too much". They contain infrastructure concerns (managing explicit DB transactions, Redis locks) mixed directly with core business rules (time windows, geofencing). |
| **AR-02** | **Hybrid Domain State** | `App\Models\Attendance` | **Medium** | The model uses a mix of old `status` fields and a new State Machine trait. While `syncLegacyStatus` exists, having two sources of truth for state is fragile. |
| **AR-03** | **Transaction Script Pattern** | `AttendanceCheckInService` | **Medium** | Although there are Domain Events, the primary logic is still a procedural script (`validate A`, `validate B`, `save`, `fire event`). True DDD would push invariants into the Aggregate Root. |
| **AR-04** | **Project Structure Ambiguity** | Root Directory | **Medium** | Presence of both `mobile_flutter` (Flutter) and `AbsensiQRMobile` (React Native) folders creates confusion for new developers/CI execution about which is the "real" mobile app. |

### C. Testing Coverage Gaps
| ID | Item | Location | Severity | Description |
|----|------|----------|----------|-------------|
| **TC-01** | **Unit Test Gaps** | `Attendance` Domain Logic | **High** | Complex business rules (time windows, grace periods) are buried in Service private methods and likely hard to test without booting the framework (integration tests). |
| **TC-02** | **Concurrency Testing** | Check-in Race Conditions | **High** | The race condition protection logic (`lockForUpdate`, Redis locks) is complex and likely lacks automated stress tests to prove it works under load. |

### D. Performance Anti-patterns
| ID | Item | Location | Severity | Description |
|----|------|----------|----------|-------------|
| **PA-01** | **Synchronous Heavy Operations** | `AttendanceCheckInService` | **Medium** | While notifications are claimed to be "Fire & Forget", ensuring strictly non-blocking execution for analytics/gamification within the critical path of a check-in request is vital. |
| **PA-02** | **Potential N+1 on Reporting** | `AttendanceController::dailyReport` | **Medium** | Depending on how `AttendanceDailySummary` is queried/aggregated, there is a risk of N+1 if related data (School, Class) is lazy-loaded during serialization. |

---

## 2. Refactoring Roadmap (3-Month Plan)

### Phase 1: Stabilization & Structure (Month 1)
*Goal: Remove ambiguity and stop the bleeding in the God Class.*

1.  **Resolve Mobile Project Ambiguity (Week 1)**
    *   Archive the deprecated project (likely `mobile_flutter` if React Native is the current direction, or vice versa based on USER confirmation). Move it to an `_archive` folder or delete it.
2.  **Introduce DTOs (Week 2)**
    *   Create `CheckInRequestDTO` and `ScanDataDTO`.
    *   Refactor `AttendanceController` to hydrate DTOs and pass *only* DTOs to services.
3.  **Extract Infrastructure Services (Week 3-4)**
    *   Extract Redis locking logic into a dedicated `ConcurrencyManagerInterface`.
    *   Extract Geofence calculation into `LocationValidatorService`.
    *   Inject these *interfaces* into `AttendanceCheckInService` to clean up the code.

### Phase 2: Domain Evolution (Month 2)
*Goal: Move logic from Service to Domain Model.*

1.  **Strengthen Aggregate Root (Week 5-6)**
    *   Move validation logic (Time Window, Status Determination) from Service to `Attendance` model or a `SchedulePolicy` domain object.
    *   The Service should just coordinate: `Repo->find()`, `Model->checkIn()`, `Repo->save()`.
2.  **Refactor State Machine (Week 7-8)**
    *   Completing the transition to the State Machine.
    *   Remove `status` column dependence.
    *   Ensure `AttendanceStatusChanged` event is the *sole* driver for updates.

### Phase 3: Testing & Resilience (Month 3)
*Goal: Verify correctness under load.*

1.  **Concurrency Test Suite (Week 9-10)**
    *   Write a Pest/PHPUnit test suite that spawns parallel processes to hit the Check-In endpoint for the same user.
    *   Verify only *one* record is created.
2.  **Read Model Optimization (Week 11-12)**
    *   Benchmark `AttendanceDailySummary`.
    *   Implement Redis caching layer for the Dashboard "Live Status" instead of hitting Postgres.

---

## 3. Prioritization Scoring Matrix

| Task | Impact (1-5) | Effort (1-5) | Risk (1-5) | **Score** | Priority |
|------|:------------:|:------------:|:----------:|:---------:|:--------:|
| **Refactor `AttendanceCheckInService` (Decompose)** | 5 | 5 | 5 | **125** | **P0** |
| **Resolve Mobile Folder Ambiguity** | 3 | 1 | 2 | **6** | **P1** |
| **Implement CheckInRequestDTO** | 4 | 2 | 2 | **16** | **P1** |
| **Concurrency Load Testing** | 5 | 3 | 3 | **45** | **P1** |
| **Clean up Legacy `AttendanceService`** | 3 | 3 | 2 | **18** | **P2** |
| **Remove `status` column (Legacy)** | 2 | 4 | 4 | **32** | **P3** |

*(Score = Impact * Effort * Risk is not a strict math formula here, but indicative of "Bang for Buck". High Impact/Low Effort wins.)*

---

## 4. Code Review Checklist (Preventing Future Debt)

*   [ ] **No Arrays Passed to Services**: Use named arguments or DTOs.
*   [ ] **Service Dependencies < 5**: If a service needs > 5 dependencies, it's doing too much. Split it.
*   [ ] **Business Logic in Models**: "Is this student late?" should be `$schedule->isLate($time)`, not `if ($time > $schedule->start_time + 15)`.
*   [ ] **Events for Side Effects**: Never put "Send Email" code in the Controller or checking Transaction logic. Dispatch `AttendanceCheckedIn` and let a Listener handle it.
*   [ ] **Strict Typing**: All method arguments and return types must be typed.
