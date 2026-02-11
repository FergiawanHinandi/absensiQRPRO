# 📊 Event Sourcing Implementation (Part 2)

**Event-Driven Architecture Specialist**
**Date**: 2026-02-10
**Context**: Continuation of Event Sourcing Strategy

---

## 📽️ Projections (Read Model Builders)

Projections bertugas mendengarkan Domain Events dan memperbarui tabel Read Model (`attendance_read`) yang dioptimalkan untuk query.

### Attendance Projector

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Projections;

use App\Domain\Attendance\Events\AttendanceCreated;
use App\Domain\Attendance\Events\CheckedIn;
use App\Domain\Attendance\Events\CheckedOut;
use App\Domain\Attendance\Events\StatusCorrected;
use App\Domain\Attendance\Events\CorrectionApproved;
use App\Domain\Attendance\Events\CorrectionRejected;
use Illuminate\Support\Facades\DB;

class AttendanceProjector
{
    public function handle(object $event): void
    {
        match (get_class($event)) {
            AttendanceCreated::class => $this->onAttendanceCreated($event),
            CheckedIn::class => $this->onCheckedIn($event),
            CheckedOut::class => $this->onCheckedOut($event),
            StatusCorrected::class => $this->onStatusCorrected($event),
            CorrectionApproved::class => $this->onCorrectionApproved($event),
            CorrectionRejected::class => $this->onCorrectionRejected($event),
            default => null,
        };
    }

    private function onAttendanceCreated(AttendanceCreated $event): void
    {
        DB::table('attendance_read')->insert([
            'id' => $event->aggregateId,
            'school_id' => $event->schoolId,
            'student_id' => $event->studentId,
            'schedule_id' => $event->scheduleId,
            'attendance_date' => $event->attendanceDate,
            'status' => $event->status,
            'check_in_time' => $event->checkInTime,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function onCheckedIn(CheckedIn $event): void
    {
        DB::table('attendance_read')
            ->where('id', $event->aggregateId)
            ->update([
                'status' => 'present',
                'check_in_time' => $event->checkInTime,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
    }

    private function onCheckedOut(CheckedOut $event): void
    {
        DB::table('attendance_read')
            ->where('id', $event->aggregateId)
            ->update([
                'check_out_time' => $event->checkOutTime,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
    }

    private function onStatusCorrected(StatusCorrected $event): void
    {
        DB::table('attendance_read')
            ->where('id', $event->aggregateId)
            ->update([
                'correction_reason' => $event->reason,
                'correction_status' => 'pending',
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
    }

    private function onCorrectionApproved(CorrectionApproved $event): void
    {
        DB::table('attendance_read')
            ->where('id', $event->aggregateId)
            ->update([
                'correction_status' => 'approved',
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
    }

    private function onCorrectionRejected(CorrectionRejected $event): void
    {
        DB::table('attendance_read')
            ->where('id', $event->aggregateId)
            ->update([
                'correction_status' => 'rejected',
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
    }
}
```

---

## ⏪ Replay Capability (Time Travel)

Fitur ini memungkinkan kita membangun ulang seluruh database Read Model dari awal (atau sampai titik waktu tertentu) jika terjadi inkonsistensi data atau perubahan struktur tabel Read Model.

### Replay Command

```php
<?php

namespace App\Console\Commands;

use App\Infrastructure\Projections\AttendanceProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplayEventsCommand extends Command
{
    protected $signature = 'event-store:replay {--truncate : Truncate read tables first}';
    protected $description = 'Replay events to rebuild read models';

    public function handle(AttendanceProjector $projector): int
    {
        if ($this->option('truncate')) {
            $this->warn('Truncating attendance_read table...');
            DB::table('attendance_read')->truncate();
        }

        $this->info('Starting replay...');
        
        $bar = $this->output->createProgressBar(
            DB::table('attendance_events')->count()
        );

        DB::table('attendance_events')
            ->orderBy('id') // Global sequence ID
            ->chunk(1000, function ($events) use ($projector, $bar) {
                foreach ($events as $row) {
                    $eventClass = $row->event_type;
                    $payload = json_decode($row->payload, true);
                    
                    // Rehydrate event
                    $event = new $eventClass(...$payload);
                    
                    // Process projection
                    $projector->handle($event);
                    
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info('Replay completed successfully!');

        return self::SUCCESS;
    }
}
```

---

## 🚀 Migration Strategy (Zero Downtime)

Kita menggunakan strategi **Parallel Run** untuk migrasi yang aman.

### Phase 1: Dual Write (Parallel)
Aplikasi menulis ke tabel lama `attendances` DAN `attendance_events` secara bersamaan. Read tetap dari tabel lama.

**Implementation in Service:**

```php
public function checkIn(CheckInCommand $command): void
{
    DB::transaction(function () use ($command) {
        // 1. OLD WAY (Keep legacy working)
        $legacyAttendance = Attendance::find($command->id);
        $legacyAttendance->update(['status' => 'present', 'check_in_time' => now()]);
        
        // 2. NEW WAY (Event Sourcing)
        $aggregate = $this->eventStore->load($command->aggregateId);
        $aggregate->checkIn(now(), $command->lat, $command->long, []);
        $this->eventStore->save($aggregate);
    });
}
```

### Phase 2: Backfill Historic Data
Script untuk mengubah data lama di tabel `attendances` menjadi event stream awal (`AttendanceCreated` events) untuk history yang sudah ada.

```php
// Backfill Script Logic
$legacyRecords = DB::table('attendances')->get();
foreach ($legacyRecords as $record) {
    $aggregateId = (string) Str::uuid();
    
    // Create 'AttendanceCreated' event representing semantic state at creation
    $event = new AttendanceCreated(
        $aggregateId, 
        $record->school_id, 
        $record->student_id, 
        // ... map other fields
    );
    
    // Save directly to event store
    DB::table('attendance_events')->insert([
        'aggregate_id' => $aggregateId,
        'event_type' => AttendanceCreated::class,
        'payload' => json_encode($event->toArray()),
        'version' => 1,
        'occurred_at' => $record->created_at
    ]);
    
    // Link legacy ID to Aggregate ID for mapping
    DB::table('legacy_id_mapping')->insert([
        'legacy_id' => $record->id,
        'aggregate_id' => $aggregateId
    ]);
}
```

### Phase 3: Switch Read
Ubah query Dashboard/Report untuk membaca dari tabel `attendance_read` (yang dibangun oleh Projector). Tulis (Write) masih ke kedua tempat.

### Phase 4: Deprecate Legacy Write
Hentikan penulisan ke tabel `attendances` lama. Event Store menjadi **Single Source of Truth**.

---

## 🛡️ Rollback Strategy

Jika terjadi masalah di tengah migrasi:

1.  **Jika Error di Phase 1 (Dual Write):**
    *   Matikan penulisan ke Event Store (Feature Flag: `EVENT_SOURCING_ENABLED=false`).
    *   Sistem kembali bekerja 100% seperti legacy.

2.  **Jika Data Read Model Corrupt:**
    *   Jalankan `php artisan event-store:replay --truncate`.
    *   Ini akan membangun ulang `attendance_read` yang bersih dari Event Log yang immutable.

3.  **Jika Bugs Logic di Projector:**
    *   Fix code bug di Projector.
    *   Deploy fix.
    *   Replay events.

---

## 📈 Analytics Power (Example)

Dengan Event Sourcing, kita bisa menjawab pertanyaan yang tidak bisa dijawab oleh "current state":

*"Berapa banyak siswa yang melakukan check-in terlambat tapi kemudian statusnya dikoreksi manual menjadi hadir tepat waktu?"*

**SQL on Events:**

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
    AND e2.version > e1.version
);
```

---

**Status**: ✅ Implementation Design Complete
**Docs**:
1. `EVENT_SOURCING_STRATEGY.md` (Part 1: Core Architecture)
2. `EVENT_SOURCING_IMPLEMENTATION.md` (Part 2: Projections & Migration)
