# CQRS Architecture Refactoring Plan
## Production-Ready Domain-Based Structure

**Status**: ✅ Ready for Implementation  
**Date**: 2026-02-10  
**Architect**: Enterprise Laravel Architect

---

## 🎯 Objectives

1. ✅ **Domain Separation**: Clear boundaries between Attendance, Subscription, QR, etc.
2. ✅ **CQRS Pattern**: Write models (Commands) and Read models (Queries) separated
3. ✅ **Backward Compatible**: No breaking changes to existing routes/controllers
4. ✅ **Laravel-Native**: Use Laravel conventions and features
5. ✅ **Production-Ready**: Not over-engineered, maintainable, testable

---

## 📊 Current State Analysis

### ✅ Already Implemented
- `app/Domain/Attendance/` - Partial CQRS structure exists
  - ✅ `AttendanceAggregateRoot.php` - Aggregate root
  - ✅ `Commands/` - RecordAttendanceCommand, ChangeAttendanceStatusCommand
  - ✅ `Handlers/` - RecordAttendanceHandler, ChangeAttendanceStatusHandler
  - ✅ `Events/` - Domain events (AttendanceRecorded, AttendanceStatusChanged, etc.)
  - ✅ `ValueObjects/` - AttendanceStatus, AttendanceTimeWindow, GeoFence
  - ✅ `Rules/` - NoDuplicateAttendanceRule, TimeWindowRule
  - ✅ `Services/` - AttendanceCheckInDomainService
  - ✅ `AttendanceStateMachine.php` - State machine

- `app/ReadModels/` - Read model exists
  - ✅ `AttendanceDailySummary.php` - Pre-aggregated read model

- `app/Listeners/` - Event listeners
  - ✅ `UpdateAttendanceSummaryListener.php` - Updates read model on events

### ⚠️ Needs Refactoring
- `app/Services/` - Multiple attendance services scattered
  - `AttendanceService.php` - Main service (needs to delegate to domain)
  - `AttendanceCheckInService.php` - Should use domain handlers
  - `AttendanceOperationService.php` - Should use domain handlers
  - `AttendanceSummaryService.php` - Should use read models

- `app/Events/` - Events in wrong namespace
  - `AttendanceRecorded.php` - Should reference domain events
  - `StudentAttended.php` - Integration event

---

## 🏗️ Target Architecture

```
app/
 ├── Domain/                          # ← WRITE SIDE (Commands)
 │    ├── Attendance/
 │    │     ├── Aggregates/           # NEW: Rename from root
 │    │     │     └── AttendanceAggregate.php (renamed from AttendanceAggregateRoot)
 │    │     ├── Commands/             # ✅ EXISTS
 │    │     │     ├── RecordAttendanceCommand.php
 │    │     │     ├── ChangeAttendanceStatusCommand.php
 │    │     │     ├── CheckInCommand.php           # NEW
 │    │     │     ├── CheckOutCommand.php          # NEW
 │    │     │     └── RequestCorrectionCommand.php # NEW
 │    │     ├── Handlers/             # ✅ EXISTS (expand)
 │    │     │     ├── RecordAttendanceHandler.php
 │    │     │     ├── ChangeAttendanceStatusHandler.php
 │    │     │     ├── CheckInHandler.php           # NEW
 │    │     │     ├── CheckOutHandler.php          # NEW
 │    │     │     └── RequestCorrectionHandler.php # NEW
 │    │     ├── Events/               # ✅ EXISTS
 │    │     │     ├── AttendanceRecorded.php
 │    │     │     ├── AttendanceStatusChanged.php
 │    │     │     ├── AttendanceCheckedOut.php
 │    │     │     ├── AttendanceApproved.php
 │    │     │     └── CorrectionRequested.php
 │    │     ├── ValueObjects/         # ✅ EXISTS
 │    │     │     ├── AttendanceStatus.php
 │    │     │     ├── AttendanceTimeWindow.php
 │    │     │     └── GeoFence.php
 │    │     ├── Rules/                # ✅ EXISTS
 │    │     │     ├── NoDuplicateAttendanceRule.php
 │    │     │     └── TimeWindowRule.php
 │    │     ├── Services/             # ✅ EXISTS
 │    │     │     └── AttendanceCheckInDomainService.php
 │    │     └── StateMachine/         # NEW: Organize better
 │    │           └── AttendanceStateMachine.php (moved)
 │    │
 │    ├── Subscription/               # ✅ EXISTS (expand)
 │    │     ├── Aggregates/           # NEW
 │    │     │     └── SubscriptionAggregate.php
 │    │     ├── Commands/             # NEW
 │    │     │     ├── CreateSubscriptionCommand.php
 │    │     │     ├── UpgradeSubscriptionCommand.php
 │    │     │     └── CancelSubscriptionCommand.php
 │    │     ├── Handlers/             # NEW
 │    │     │     ├── CreateSubscriptionHandler.php
 │    │     │     ├── UpgradeSubscriptionHandler.php
 │    │     │     └── CancelSubscriptionHandler.php
 │    │     └── Events/               # NEW
 │    │           ├── SubscriptionCreated.php
 │    │           ├── SubscriptionUpgraded.php
 │    │           └── SubscriptionCancelled.php
 │    │
 │    ├── QR/                         # ✅ EXISTS (expand)
 │    │     ├── ValueObjects/         # NEW
 │    │     │     ├── QRToken.php
 │    │     │     └── QRNonce.php
 │    │     └── Services/             # NEW
 │    │           ├── QRGenerationService.php
 │    │           └── QRValidationService.php
 │    │
 │    └── Shared/                     # ✅ EXISTS
 │          ├── AggregateRoot.php     # Base aggregate
 │          ├── Command.php           # Base command interface
 │          ├── CommandHandler.php    # Base handler interface
 │          ├── DomainEvent.php       # Base event
 │          └── ValueObject.php       # Base value object
 │
 ├── ReadModels/                      # ← READ SIDE (Queries)
 │    ├── AttendanceDailySummary.php  # ✅ EXISTS
 │    ├── AttendanceWeeklySummary.php # NEW (optional)
 │    └── Projectors/                 # NEW
 │          └── AttendanceSummaryProjector.php
 │
 ├── Application/                     # ← APPLICATION LAYER
 │    ├── Services/                   # Orchestration services
 │    │     ├── AttendanceApplicationService.php  # NEW: Facade for domain
 │    │     └── DashboardQueryService.php         # NEW: Read model queries
 │    └── DTOs/                       # Data Transfer Objects
 │          └── AttendanceDTO.php
 │
 ├── Infrastructure/                  # ✅ EXISTS
 │    ├── Persistence/
 │    │     └── AttendanceRepository.php
 │    └── Events/
 │          └── LaravelEventBridge.php
 │
 ├── Http/                            # ← UNCHANGED (backward compatible)
 │    └── Controllers/
 │          └── AttendanceController.php  # Uses Application Services
 │
 └── Listeners/                       # ✅ EXISTS
      └── UpdateAttendanceSummaryListener.php  # Updates read models
```

---

## 🔄 Migration Strategy (Zero Downtime)

### Phase 1: Organize Domain Layer ✅
1. Create `Domain/Attendance/Aggregates/` folder
2. Move `AttendanceAggregateRoot.php` → `AttendanceAggregate.php`
3. Create `Domain/Attendance/StateMachine/` folder
4. Move `AttendanceStateMachine.php` to StateMachine folder
5. Add missing Commands (CheckIn, CheckOut, RequestCorrection)
6. Add missing Handlers for new commands

### Phase 2: Create Application Layer 🆕
1. Create `Application/Services/AttendanceApplicationService.php`
   - Facade that delegates to domain handlers
   - Backward compatible with existing service calls
2. Create `Application/Services/DashboardQueryService.php`
   - Encapsulates all read model queries
   - Replaces direct AttendanceDailySummary queries

### Phase 3: Refactor Existing Services (Gradual)
1. Update `AttendanceService.php` to delegate to `AttendanceApplicationService`
2. Update `AttendanceCheckInService.php` to use domain handlers
3. Update `AttendanceSummaryService.php` to use `DashboardQueryService`
4. Keep old methods as deprecated wrappers (backward compatible)

### Phase 4: Expand Subscription Domain
1. Create `Domain/Subscription/Aggregates/SubscriptionAggregate.php`
2. Create Commands, Handlers, Events for subscription lifecycle
3. Create Application service for subscriptions

### Phase 5: Testing & Validation
1. Run existing tests (should pass - backward compatible)
2. Add new domain tests for aggregates and handlers
3. Add integration tests for application services
4. Performance testing (read models should be faster)

---

## 📝 Implementation Checklist

### Domain Layer
- [ ] Create `Domain/Attendance/Aggregates/` folder
- [ ] Rename `AttendanceAggregateRoot` → `AttendanceAggregate`
- [ ] Create `Domain/Attendance/StateMachine/` folder
- [ ] Move `AttendanceStateMachine.php`
- [ ] Create missing Commands (CheckIn, CheckOut, RequestCorrection)
- [ ] Create missing Handlers
- [ ] Create `Domain/Subscription/` structure
- [ ] Create `Domain/QR/` value objects and services

### Application Layer
- [ ] Create `Application/Services/AttendanceApplicationService.php`
- [ ] Create `Application/Services/DashboardQueryService.php`
- [ ] Create `Application/Services/SubscriptionApplicationService.php`
- [ ] Create DTOs for data transfer

### Read Models
- [ ] Create `ReadModels/Projectors/AttendanceSummaryProjector.php`
- [ ] (Optional) Create `AttendanceWeeklySummary.php`

### Refactoring
- [ ] Update `AttendanceService.php` to delegate
- [ ] Update `AttendanceCheckInService.php`
- [ ] Update `AttendanceSummaryService.php`
- [ ] Update controllers to use Application services
- [ ] Add deprecation notices to old methods

### Testing
- [ ] Run existing test suite
- [ ] Add domain unit tests
- [ ] Add application integration tests
- [ ] Performance benchmarks

---

## 🎯 Success Criteria

1. ✅ All existing tests pass
2. ✅ No breaking changes to routes/controllers
3. ✅ Clear domain boundaries
4. ✅ Write/Read separation enforced
5. ✅ Dashboard queries use read models (<50ms)
6. ✅ Domain logic isolated from infrastructure
7. ✅ Easy to test and maintain

---

## 📚 Key Principles

### 1. **Aggregates**
- Enforce business invariants
- Transaction boundaries
- Emit domain events

### 2. **Commands**
- Represent user intent
- Immutable DTOs
- Validated before handling

### 3. **Handlers**
- Load aggregate
- Execute business logic
- Persist changes
- Dispatch events

### 4. **Events**
- Past tense (AttendanceRecorded)
- Immutable
- Trigger side effects (read model updates, notifications)

### 5. **Read Models**
- Denormalized
- Eventually consistent
- Optimized for queries
- No business logic

### 6. **Application Services**
- Orchestrate use cases
- Coordinate domain and infrastructure
- Transaction management
- Error handling

---

## 🚀 Next Steps

1. Review and approve this plan
2. Create feature branch: `feature/cqrs-refactoring`
3. Implement Phase 1 (Domain organization)
4. Implement Phase 2 (Application layer)
5. Gradual refactoring of existing services
6. Testing and validation
7. Documentation update
8. Merge to main

---

**Note**: This refactoring maintains 100% backward compatibility. Old code continues to work while new code follows CQRS patterns.
