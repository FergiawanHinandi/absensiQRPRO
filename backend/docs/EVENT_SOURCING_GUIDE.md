# 📊 Event Sourcing - Complete Guide

**Event-Driven Architecture Specialist**  
**Date**: 2026-02-10  
**Status**: ✅ Design Ready

---

## 📋 Executive Summary

Design for evolving Attendance System to **Event Sourcing** without breaking the existing system. This enables:
- **Full audit trail** (every state change recorded)
- **Time travel debugging** (replay to any point in time)
- **Replay capability** (rebuild state from events)
- **Zero downtime migration** (parallel write strategy)

---

## 🏗️ Architecture

### 1. Event Store (Write Model)

The Application writes to an append-only log of events.

**Table**: `attendance_events`
- `aggregate_id`: UUID of the attendance record
- `event_type`: Class name (e.g., `CheckedIn`)
- `payload`: JSON data
- `version`: Sequence number (1, 2, 3...)

**Events**:
- `AttendanceCreated`
- `CheckedIn`
- `CheckedOut`
- `StatusCorrected`
- `CorrectionApproved`
- `CorrectionRejected`

### 2. Read Models (Projections)

Projections listen to events and update optimized read tables.

**Table**: `attendance_read`
- Denormalized view optimized for queries
- Rebuilt automatically from event stream

### 3. Snapshots

Optimization to avoid replaying thousands of events.

**Table**: `attendance_snapshots`
- Stores aggregate state every 100 events
- Speeds up loading aggregates

---

## 🔄 Write Flow

```
Command (CheckIn)
    ↓
Handler
    ↓
Load Aggregate (from Events/Snapshot)
    ↓
Call Aggregate Method (checkIn)
    ↓
Aggregate Records Event (CheckedIn)
    ↓
Save to Event Store (Optimistic Locking)
    ↓
Publish Event
    ↓
Projector Updates Read Model
```

---

## 🚀 Migration Plan (Zero Downtime)

### Phase 1: Dual Write (Parallel)
- Continue writing to legacy `attendances` table.
- Start writing to `attendance_events` table in parallel.
- Both systems run simultaneously.

### Phase 2: Backfill Historic Data
- Run script to convert old `attendances` rows into `AttendanceCreated` events.
- Ensure all history is captured in Event Store.

### Phase 3: Switch Read
- Update Dashboard/Reports to query `attendance_read` table.
- Verify data consistency.

### Phase 4: Event Sourcing as Truth
- Stop writing to legacy `attendances` table.
- Event Store becomes the Single Source of Truth.

---

## 🛡️ Rollback Strategy

1. **Dual Write Failure**: Switch off Event Sourcing feature flag. System reverts to legacy mode.
2. **Read Model Corruption**: Run `php artisan event-store:replay --truncate` to rebuild from scratch.
3. **Logic Bugs**: Fix projector code -> Deploy -> Replay events.

---

## 🛠️ Implementation Details

### Files Created:
1. **`EVENT_SOURCING_STRATEGY.md`** - Core architecture & schema
2. **`EVENT_SOURCING_IMPLEMENTATION.md`** - Projections, Replay, Migration
3. **`2026_02_10_114800_create_event_sourcing_tables.php`** - Database migration

### Key Classes:
- `AttendanceAggregate`: Domain logic & state reconstruction
- `EventStoreRepository`: Persistence & snapshots
- `AttendanceProjector`: Read model builder
- `ReplayEventsCommand`: Replay utility

---

## 📊 Analytics Example

With Event Sourcing, we can answer complex questions:

*"How many students checked in late but were later corrected to present?"*

```sql
SELECT count(DISTINCT aggregate_id)
FROM attendance_events e1
WHERE event_type = 'CheckedIn' 
AND payload->>'status' = 'late'
AND EXISTS (
    SELECT 1 FROM attendance_events e2 
    WHERE e2.aggregate_id = e1.aggregate_id 
    AND e2.event_type = 'StatusCorrected'
    AND e2.payload->>'new_status' = 'present'
);
```

---

**Status**: ✅ Ready for Development  
**Next Step**: Implement Aggregate Root and Repository classes.
