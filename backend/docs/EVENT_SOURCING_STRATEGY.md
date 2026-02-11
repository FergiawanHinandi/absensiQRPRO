# 📊 Event Sourcing Evolution Strategy

**Event-Driven Architecture Specialist**  
**Date**: 2026-02-10  
**Purpose**: Evolve to Event Sourcing without breaking existing system

---

## 🎯 Objectives

Evolve Attendance System to Event Sourcing to achieve:
1. **Full audit trail** (every state change recorded)
2. **Time travel debugging** (replay to any point in time)
3. **Replay capability** (rebuild state from events)
4. **Powerful analytics** (event stream analysis)
5. **Zero downtime migration** (parallel write, gradual switch)

---

## 📊 Current State vs Target State

### Current: State-based Storage

```
┌─────────────────────────────────────────────────────────────┐
│                    attendances table                         │
│                    (Final State Only)                        │
│                                                              │
│  id | school_id | student_id | status | check_in_time      │
│  ---|-----------|------------|--------|------------------   │
│  1  | 100       | 500        | present| 2026-02-10 08:00   │
│                                                              │
│  Problems:                                                   │
│  - ❌ No history (only current state)                       │
│  - ❌ No audit trail (who changed what?)                    │
│  - ❌ Can't replay (lost intermediate states)               │
│  - ❌ Limited analytics (only final state)                  │
└─────────────────────────────────────────────────────────────┘
```

### Target: Event Sourcing

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          EVENT STORE                                         │
│                    (Immutable Event Log)                                     │
│                                                                              │
│  id | aggregate_id | event_type           | payload          | version      │
│  ---|--------------|----------------------|------------------|----------     │
│  1  | att-500      | AttendanceCreated    | {...}            | 1             │
│  2  | att-500      | CheckedIn            | {...}            | 2             │
│  3  | att-500      | StatusCorrected      | {...}            | 3             │
│  4  | att-500      | CorrectionApproved   | {...}            | 4             │
│                                                                              │
│  Benefits:                                                                   │
│  - ✅ Full history (every event recorded)                                   │
│  - ✅ Complete audit trail (who, what, when)                                │
│  - ✅ Replay capability (rebuild any state)                                 │
│  - ✅ Rich analytics (event stream analysis)                                │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                          READ MODELS                                         │
│                    (Denormalized Views)                                      │
│                                                                              │
│  attendance_read (current state)                                            │
│  attendance_daily_summary (aggregated)                                      │
│  attendance_analytics (time-series)                                         │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 📝 Event Schema

### Event Store Table

**Migration**: `create_attendance_events_table.php`

```php
Schema::create('attendance_events', function (Blueprint $table) {
    $table->id();
    $table->uuid('aggregate_id')->index()->comment('Attendance aggregate ID');
    $table->string('event_type', 100)->index()->comment('Event class name');
    $table->json('payload')->comment('Event data');
    $table->json('metadata')->nullable()->comment('User, IP, timestamp, etc');
    $table->unsignedInteger('version')->comment('Aggregate version (optimistic locking)');
    $table->timestamp('occurred_at')->index()->comment('When event occurred');
    $table->timestamps();

    // Unique constraint: one version per aggregate
    $table->unique(['aggregate_id', 'version'], 'unique_aggregate_version');

    // Indexes for querying
    $table->index(['aggregate_id', 'version'], 'idx_aggregate_version');
    $table->index('event_type');
    $table->index('occurred_at');
});
```

### Snapshot Table

**Migration**: `create_attendance_snapshots_table.php`

```php
Schema::create('attendance_snapshots', function (Blueprint $table) {
    $table->id();
    $table->uuid('aggregate_id')->unique()->comment('Attendance aggregate ID');
    $table->json('state')->comment('Aggregate state at this version');
    $table->unsignedInteger('version')->comment('Version of this snapshot');
    $table->timestamp('created_at')->index();

    // Index for quick lookup
    $table->index(['aggregate_id', 'version'], 'idx_snapshot_lookup');
});
```

### Read Model Table

**Migration**: `create_attendance_read_table.php`

```php
Schema::create('attendance_read', function (Blueprint $table) {
    $table->uuid('id')->primary()->comment('Same as aggregate_id');
    $table->foreignId('school_id')->constrained()->index();
    $table->foreignId('student_id')->constrained()->index();
    $table->foreignId('schedule_id')->constrained()->index();
    $table->date('attendance_date')->index();
    $table->enum('status', ['present', 'late', 'absent', 'excused']);
    $table->timestamp('check_in_time')->nullable();
    $table->timestamp('check_out_time')->nullable();
    $table->string('correction_reason')->nullable();
    $table->enum('correction_status', ['pending', 'approved', 'rejected'])->nullable();
    $table->unsignedInteger('version')->comment('Current version from event store');
    $table->timestamps();

    // Unique constraint (same as original)
    $table->unique(
        ['schedule_id', 'student_id', 'attendance_date'],
        'unique_attendance_per_day'
    );
});
```

---

## 🎭 Event Types

### Domain Events

```php
<?php

namespace App\Domain\Attendance\Events;

use Carbon\Carbon;

/**
 * Attendance Created Event
 */
class AttendanceCreated
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $schoolId,
        public readonly int $studentId,
        public readonly int $scheduleId,
        public readonly Carbon $attendanceDate,
        public readonly string $status,
        public readonly ?Carbon $checkInTime,
        public readonly array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'aggregate_id' => $this->aggregateId,
            'school_id' => $this->schoolId,
            'student_id' => $this->studentId,
            'schedule_id' => $this->scheduleId,
            'attendance_date' => $this->attendanceDate->toDateString(),
            'status' => $this->status,
            'check_in_time' => $this->checkInTime?->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * Checked In Event
 */
class CheckedIn
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly Carbon $checkInTime,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'aggregate_id' => $this->aggregateId,
            'check_in_time' => $this->checkInTime->toIso8601String(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * Checked Out Event
 */
class CheckedOut
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly Carbon $checkOutTime,
        public readonly array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'aggregate_id' => $this->aggregateId,
            'check_out_time' => $this->checkOutTime->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * Status Corrected Event
 */
class StatusCorrected
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly string $newStatus,
        public readonly string $reason,
        public readonly int $correctedBy,
        public readonly array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'aggregate_id' => $this->aggregateId,
            'new_status' => $this->newStatus,
            'reason' => $this->reason,
            'corrected_by' => $this->correctedBy,
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * Correction Approved Event
 */
class CorrectionApproved
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $approvedBy,
        public readonly array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'aggregate_id' => $this->aggregateId,
            'approved_by' => $this->approvedBy,
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * Correction Rejected Event
 */
class CorrectionRejected
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $rejectedBy,
        public readonly string $reason,
        public readonly array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'aggregate_id' => $this->aggregateId,
            'rejected_by' => $this->rejectedBy,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
        ];
    }
}
```

---

## 🏗️ Aggregate Root with Event Sourcing

### AttendanceAggregate

```php
<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\Attendance\Events\*;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Attendance Aggregate Root (Event Sourced)
 * 
 * Reconstructs state from events
 */
class AttendanceAggregate
{
    private string $aggregateId;
    private int $version = 0;
    private array $uncommittedEvents = [];

    // State
    private ?int $schoolId = null;
    private ?int $studentId = null;
    private ?int $scheduleId = null;
    private ?Carbon $attendanceDate = null;
    private ?string $status = null;
    private ?Carbon $checkInTime = null;
    private ?Carbon $checkOutTime = null;
    private ?string $correctionReason = null;
    private ?string $correctionStatus = null;

    private function __construct(string $aggregateId)
    {
        $this->aggregateId = $aggregateId;
    }

    /**
     * Create new aggregate
     */
    public static function create(
        int $schoolId,
        int $studentId,
        int $scheduleId,
        Carbon $attendanceDate,
        string $status,
        ?Carbon $checkInTime,
        array $metadata
    ): self {
        $aggregateId = Str::uuid()->toString();
        $aggregate = new self($aggregateId);

        $event = new AttendanceCreated(
            aggregateId: $aggregateId,
            schoolId: $schoolId,
            studentId: $studentId,
            scheduleId: $scheduleId,
            attendanceDate: $attendanceDate,
            status: $status,
            checkInTime: $checkInTime,
            metadata: $metadata,
        );

        $aggregate->recordEvent($event);

        return $aggregate;
    }

    /**
     * Reconstruct from events
     */
    public static function reconstituteFromEvents(string $aggregateId, array $events): self
    {
        $aggregate = new self($aggregateId);

        foreach ($events as $event) {
            $aggregate->applyEvent($event, false);
        }

        return $aggregate;
    }

    /**
     * Check in
     */
    public function checkIn(Carbon $checkInTime, float $latitude, float $longitude, array $metadata): void
    {
        if ($this->checkInTime !== null) {
            throw new \DomainException('Already checked in');
        }

        $event = new CheckedIn(
            aggregateId: $this->aggregateId,
            checkInTime: $checkInTime,
            latitude: $latitude,
            longitude: $longitude,
            metadata: $metadata,
        );

        $this->recordEvent($event);
    }

    /**
     * Check out
     */
    public function checkOut(Carbon $checkOutTime, array $metadata): void
    {
        if ($this->checkInTime === null) {
            throw new \DomainException('Must check in first');
        }

        if ($this->checkOutTime !== null) {
            throw new \DomainException('Already checked out');
        }

        $event = new CheckedOut(
            aggregateId: $this->aggregateId,
            checkOutTime: $checkOutTime,
            metadata: $metadata,
        );

        $this->recordEvent($event);
    }

    /**
     * Request correction
     */
    public function requestCorrection(string $newStatus, string $reason, int $correctedBy, array $metadata): void
    {
        $event = new StatusCorrected(
            aggregateId: $this->aggregateId,
            newStatus: $newStatus,
            reason: $reason,
            correctedBy: $correctedBy,
            metadata: $metadata,
        );

        $this->recordEvent($event);
    }

    /**
     * Approve correction
     */
    public function approveCorrection(int $approvedBy, array $metadata): void
    {
        if ($this->correctionStatus !== 'pending') {
            throw new \DomainException('No pending correction');
        }

        $event = new CorrectionApproved(
            aggregateId: $this->aggregateId,
            approvedBy: $approvedBy,
            metadata: $metadata,
        );

        $this->recordEvent($event);
    }

    /**
     * Reject correction
     */
    public function rejectCorrection(int $rejectedBy, string $reason, array $metadata): void
    {
        if ($this->correctionStatus !== 'pending') {
            throw new \DomainException('No pending correction');
        }

        $event = new CorrectionRejected(
            aggregateId: $this->aggregateId,
            rejectedBy: $rejectedBy,
            reason: $reason,
            metadata: $metadata,
        );

        $this->recordEvent($event);
    }

    /**
     * Record event (new event)
     */
    private function recordEvent(object $event): void
    {
        $this->applyEvent($event, true);
        $this->uncommittedEvents[] = $event;
    }

    /**
     * Apply event to state
     */
    private function applyEvent(object $event, bool $isNew): void
    {
        match (get_class($event)) {
            AttendanceCreated::class => $this->applyAttendanceCreated($event),
            CheckedIn::class => $this->applyCheckedIn($event),
            CheckedOut::class => $this->applyCheckedOut($event),
            StatusCorrected::class => $this->applyStatusCorrected($event),
            CorrectionApproved::class => $this->applyCorrectionApproved($event),
            CorrectionRejected::class => $this->applyCorrectionRejected($event),
            default => throw new \RuntimeException('Unknown event type: ' . get_class($event)),
        };

        if ($isNew) {
            $this->version++;
        }
    }

    private function applyAttendanceCreated(AttendanceCreated $event): void
    {
        $this->schoolId = $event->schoolId;
        $this->studentId = $event->studentId;
        $this->scheduleId = $event->scheduleId;
        $this->attendanceDate = $event->attendanceDate;
        $this->status = $event->status;
        $this->checkInTime = $event->checkInTime;
    }

    private function applyCheckedIn(CheckedIn $event): void
    {
        $this->checkInTime = $event->checkInTime;
        $this->status = 'present';
    }

    private function applyCheckedOut(CheckedOut $event): void
    {
        $this->checkOutTime = $event->checkOutTime;
    }

    private function applyStatusCorrected(StatusCorrected $event): void
    {
        $this->correctionReason = $event->reason;
        $this->correctionStatus = 'pending';
    }

    private function applyCorrectionApproved(CorrectionApproved $event): void
    {
        $this->correctionStatus = 'approved';
    }

    private function applyCorrectionRejected(CorrectionRejected $event): void
    {
        $this->correctionStatus = 'rejected';
    }

    /**
     * Get uncommitted events
     */
    public function getUncommittedEvents(): array
    {
        return $this->uncommittedEvents;
    }

    /**
     * Clear uncommitted events
     */
    public function clearUncommittedEvents(): void
    {
        $this->uncommittedEvents = [];
    }

    /**
     * Get current state
     */
    public function getState(): array
    {
        return [
            'aggregate_id' => $this->aggregateId,
            'school_id' => $this->schoolId,
            'student_id' => $this->studentId,
            'schedule_id' => $this->scheduleId,
            'attendance_date' => $this->attendanceDate?->toDateString(),
            'status' => $this->status,
            'check_in_time' => $this->checkInTime?->toIso8601String(),
            'check_out_time' => $this->checkOutTime?->toIso8601String(),
            'correction_reason' => $this->correctionReason,
            'correction_status' => $this->correctionStatus,
            'version' => $this->version,
        ];
    }

    // Getters
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getVersion(): int { return $this->version; }
    public function getSchoolId(): ?int { return $this->schoolId; }
    public function getStudentId(): ?int { return $this->studentId; }
    public function getStatus(): ?string { return $this->status; }
}
```

---

## 💾 Event Store Repository

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

use App\Domain\Attendance\AttendanceAggregate;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Event Store Repository
 * 
 * Persists and retrieves events
 */
class EventStoreRepository
{
    private const SNAPSHOT_INTERVAL = 100;

    /**
     * Save aggregate events
     */
    public function save(AttendanceAggregate $aggregate): void
    {
        $events = $aggregate->getUncommittedEvents();

        if (empty($events)) {
            return;
        }

        DB::transaction(function () use ($aggregate, $events) {
            $currentVersion = $aggregate->getVersion() - count($events);

            foreach ($events as $event) {
                $currentVersion++;

                DB::table('attendance_events')->insert([
                    'aggregate_id' => $aggregate->getAggregateId(),
                    'event_type' => get_class($event),
                    'payload' => json_encode($event->toArray()),
                    'metadata' => json_encode($event->metadata ?? []),
                    'version' => $currentVersion,
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Create snapshot every N events
            if ($aggregate->getVersion() % self::SNAPSHOT_INTERVAL === 0) {
                $this->createSnapshot($aggregate);
            }
        });

        $aggregate->clearUncommittedEvents();
    }

    /**
     * Load aggregate from events
     */
    public function load(string $aggregateId): ?AttendanceAggregate
    {
        // Try to load from snapshot first
        $snapshot = $this->loadSnapshot($aggregateId);
        
        if ($snapshot) {
            $events = $this->loadEventsAfterVersion($aggregateId, $snapshot['version']);
            $aggregate = AttendanceAggregate::reconstituteFromSnapshot(
                $aggregateId,
                $snapshot['state'],
                $snapshot['version']
            );
        } else {
            $events = $this->loadAllEvents($aggregateId);
            
            if (empty($events)) {
                return null;
            }

            $aggregate = AttendanceAggregate::reconstituteFromEvents($aggregateId, $events);
        }

        return $aggregate;
    }

    /**
     * Load all events for aggregate
     */
    private function loadAllEvents(string $aggregateId): array
    {
        $rows = DB::table('attendance_events')
            ->where('aggregate_id', $aggregateId)
            ->orderBy('version')
            ->get();

        return $this->hydrateEvents($rows);
    }

    /**
     * Load events after specific version
     */
    private function loadEventsAfterVersion(string $aggregateId, int $version): array
    {
        $rows = DB::table('attendance_events')
            ->where('aggregate_id', $aggregateId)
            ->where('version', '>', $version)
            ->orderBy('version')
            ->get();

        return $this->hydrateEvents($rows);
    }

    /**
     * Hydrate events from database rows
     */
    private function hydrateEvents($rows): array
    {
        $events = [];

        foreach ($rows as $row) {
            $eventClass = $row->event_type;
            $payload = json_decode($row->payload, true);

            // Reconstruct event object
            $events[] = new $eventClass(...$payload);
        }

        return $events;
    }

    /**
     * Create snapshot
     */
    private function createSnapshot(AttendanceAggregate $aggregate): void
    {
        DB::table('attendance_snapshots')->updateOrInsert(
            ['aggregate_id' => $aggregate->getAggregateId()],
            [
                'state' => json_encode($aggregate->getState()),
                'version' => $aggregate->getVersion(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Load snapshot
     */
    private function loadSnapshot(string $aggregateId): ?array
    {
        $snapshot = DB::table('attendance_snapshots')
            ->where('aggregate_id', $aggregateId)
            ->first();

        if (!$snapshot) {
            return null;
        }

        return [
            'state' => json_decode($snapshot->state, true),
            'version' => $snapshot->version,
        ];
    }

    /**
     * Get event stream for aggregate
     */
    public function getEventStream(string $aggregateId): array
    {
        return DB::table('attendance_events')
            ->where('aggregate_id', $aggregateId)
            ->orderBy('version')
            ->get()
            ->map(function ($row) {
                return [
                    'version' => $row->version,
                    'event_type' => class_basename($row->event_type),
                    'payload' => json_decode($row->payload, true),
                    'occurred_at' => $row->occurred_at,
                ];
            })
            ->toArray();
    }
}
```

---

**Status**: ✅ Part 1 Complete  
**Next**: Event handlers, projections, migration strategy
