# Microservices Migration - Implementation Guide

## Quick Start

### Phase 1: Event Foundation (Month 1-2)

#### Step 1: Install Dependencies

```bash
cd backend

# Kafka client
composer require enqueue/rdkafka

# Event store (optional for Phase 4)
composer require prooph/event-store

# Laravel Horizon for queue management
composer require laravel/horizon
```

#### Step 2: Start Kafka Infrastructure

```bash
cd infrastructure

# Start Kafka, Zookeeper, and Kafka UI
docker-compose -f docker-compose.kafka.yml up -d

# Verify Kafka is running
docker-compose -f docker-compose.kafka.yml ps

# Access Kafka UI
open http://localhost:8080
```

#### Step 3: Configure Environment

Add to `.env`:

```env
# Event Publishing
EVENT_PUBLISHING_ENABLED=true
EVENT_BROKER=kafka
EVENT_LOG_ALL=false

# Kafka Configuration
KAFKA_BROKERS=localhost:9092
KAFKA_GROUP_ID=attendance-app
KAFKA_COMPRESSION=snappy
KAFKA_AUTO_OFFSET_RESET=earliest
```

#### Step 4: Register Event Listener

Update `app/Providers/EventServiceProvider.php`:

```php
use App\Events\Attendance\AttendanceRecorded;
use App\Events\Billing\SubscriptionCreated;
use App\Listeners\PublishDomainEvent;

protected $listen = [
    AttendanceRecorded::class => [
        PublishDomainEvent::class,
    ],
    SubscriptionCreated::class => [
        PublishDomainEvent::class,
    ],
    // Add more events as needed
];
```

#### Step 5: Emit Events from Existing Code

**Before:**
```php
// app/Services/AttendanceService.php
public function recordAttendance($data)
{
    $attendance = Attendance::create($data);
    return $attendance;
}
```

**After:**
```php
use App\Events\Attendance\AttendanceRecorded;

public function recordAttendance($data)
{
    $attendance = Attendance::create($data);
    
    // Emit domain event
    event(AttendanceRecorded::fromAttendance($attendance));
    
    return $attendance;
}
```

#### Step 6: Verify Events are Published

```bash
# Monitor Kafka topics
docker exec -it kafka kafka-console-consumer \
  --bootstrap-server localhost:9092 \
  --topic attendance.events \
  --from-beginning

# Or use Kafka UI
open http://localhost:8080
```

#### Step 7: Create Monitoring Command

```bash
php artisan make:command MonitorEvents
```

```php
// app/Console/Commands/MonitorEvents.php
public function handle()
{
    $this->info('Monitoring events...');
    
    Redis::subscribe(['attendance.events'], function ($message) {
        $event = json_decode($message, true);
        $this->line("Event: {$event['event_type']} - {$event['event_id']}");
    });
}
```

---

### Phase 2: Reporting Service (Month 3-4)

#### Step 1: Create Reporting Service

```bash
# Create new Laravel project
laravel new reporting-service

cd reporting-service

# Install MongoDB
composer require mongodb/laravel-mongodb

# Install Kafka consumer
composer require enqueue/rdkafka
```

#### Step 2: Configure MongoDB

```env
# reporting-service/.env
DB_CONNECTION=mongodb
DB_HOST=localhost
DB_PORT=27017
DB_DATABASE=attendance_reporting
```

```php
// config/database.php
'mongodb' => [
    'driver' => 'mongodb',
    'host' => env('DB_HOST', 'localhost'),
    'port' => env('DB_PORT', 27017),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
],
```

#### Step 3: Create Read Models

```php
// reporting-service/app/Models/DailyAttendanceSummary.php
use MongoDB\Laravel\Eloquent\Model;

class DailyAttendanceSummary extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'daily_attendance_summaries';
    
    protected $fillable = [
        'school_id',
        'date',
        'total_students',
        'total_present',
        'total_absent',
        'total_late',
        'attendance_rate',
    ];
}
```

#### Step 4: Create Event Projections

```php
// reporting-service/app/Projections/AttendanceSummaryProjection.php
class AttendanceSummaryProjection
{
    public function handle(array $event)
    {
        if ($event['event_type'] !== 'attendance.recorded.v1') {
            return;
        }
        
        $payload = $event['payload'];
        $date = Carbon::parse($event['timestamp'])->toDateString();
        
        DailyAttendanceSummary::raw(function ($collection) use ($event, $date, $payload) {
            $collection->updateOne(
                [
                    'school_id' => $event['school_id'],
                    'date' => $date,
                ],
                [
                    '$inc' => [
                        'total_present' => $payload['status'] === 'present' ? 1 : 0,
                        'total_absent' => $payload['status'] === 'absent' ? 1 : 0,
                    ],
                    '$setOnInsert' => [
                        'created_at' => now(),
                    ],
                    '$set' => [
                        'updated_at' => now(),
                    ],
                ],
                ['upsert' => true]
            );
        });
    }
}
```

#### Step 5: Create Kafka Consumer

```php
// reporting-service/app/Console/Commands/ConsumeEvents.php
use Enqueue\RdKafka\RdKafkaConnectionFactory;

class ConsumeEvents extends Command
{
    protected $signature = 'events:consume {topic}';
    
    public function handle()
    {
        $topic = $this->argument('topic');
        
        $connectionFactory = new RdKafkaConnectionFactory([
            'global' => [
                'group.id' => 'reporting-service',
                'metadata.broker.list' => config('kafka.brokers'),
            ],
        ]);
        
        $context = $connectionFactory->createContext();
        $queue = $context->createQueue($topic);
        $consumer = $context->createConsumer($queue);
        
        $this->info("Consuming events from {$topic}...");
        
        while (true) {
            $message = $consumer->receive(1000);
            
            if ($message) {
                $event = json_decode($message->getBody(), true);
                
                app(AttendanceSummaryProjection::class)->handle($event);
                
                $consumer->acknowledge($message);
                
                $this->line("Processed: {$event['event_type']}");
            }
        }
    }
}
```

#### Step 6: Deploy Reporting Service

```bash
# Run consumer as daemon
php artisan events:consume attendance.events &

# Or use Supervisor
sudo supervisorctl start reporting-consumer
```

#### Step 7: Add Feature Flag to Monolith

```php
// monolith/app/Http/Controllers/DashboardController.php
public function index()
{
    if (config('features.reporting_service_enabled')) {
        // Call reporting service
        $response = Http::get('http://reporting-service/api/dashboard', [
            'school_id' => auth()->user()->school_id,
        ]);
        
        return view('dashboard', $response->json());
    } else {
        // Fallback to monolith
        return $this->legacyDashboard();
    }
}
```

---

### Phase 3: Billing Service (Month 5-6)

#### Step 1: Create Billing Service

```bash
laravel new billing-service

cd billing-service

composer require stripe/stripe-php
composer require xendit/xendit-php
```

#### Step 2: Implement Dual Write

```php
// monolith/app/Services/BillingService.php
public function createSubscription($data)
{
    DB::transaction(function () use ($data) {
        // 1. Write to monolith (primary during migration)
        $subscription = Subscription::create($data);
        
        // 2. Write to billing service (shadow write)
        if (config('features.billing_service_enabled')) {
            try {
                Http::post('http://billing-service/api/subscriptions', [
                    'subscription_id' => $subscription->id,
                    'school_id' => $subscription->school_id,
                    'plan_id' => $subscription->plan_id,
                    'price' => $subscription->price,
                    // ... other fields
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to sync subscription to billing service', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the main transaction
            }
        }
        
        // 3. Emit event
        event(SubscriptionCreated::fromSubscription($subscription));
        
        return $subscription;
    });
}
```

#### Step 3: Data Sync Script

```php
// monolith/app/Console/Commands/SyncBillingData.php
class SyncBillingData extends Command
{
    protected $signature = 'billing:sync {--batch=1000}';
    
    public function handle()
    {
        $batch = $this->option('batch');
        
        Subscription::chunk($batch, function ($subscriptions) {
            foreach ($subscriptions as $subscription) {
                Http::post('http://billing-service/api/subscriptions', [
                    'subscription_id' => $subscription->id,
                    // ... fields
                ]);
                
                $this->line("Synced: {$subscription->id}");
            }
        });
        
        $this->info('Sync complete!');
    }
}
```

---

### Phase 4: Attendance Core (Month 7-9)

#### Step 1: Create Attendance Service with Event Sourcing

```bash
laravel new attendance-service

composer require prooph/event-store
composer require prooph/pdo-event-store
```

#### Step 2: Implement Aggregate Root

```php
// attendance-service/app/Aggregates/Attendance.php
use Prooph\EventSourcing\AggregateRoot;

class Attendance extends AggregateRoot
{
    private string $attendanceId;
    private string $studentId;
    private string $status;
    
    public static function record(array $data): self
    {
        $self = new self();
        
        $self->recordThat(AttendanceRecorded::occur($data['attendance_id'], [
            'student_id' => $data['student_id'],
            'status' => $data['status'],
            'check_in_time' => $data['check_in_time'],
        ]));
        
        return $self;
    }
    
    protected function whenAttendanceRecorded(AttendanceRecorded $event): void
    {
        $this->attendanceId = $event->aggregateId();
        $this->studentId = $event->payload()['student_id'];
        $this->status = $event->payload()['status'];
    }
    
    protected function aggregateId(): string
    {
        return $this->attendanceId;
    }
}
```

#### Step 3: API Gateway Routing

```php
// api-gateway/routes/api.php
Route::post('/attendance/scan', function (Request $request) {
    if (config('features.attendance_service_enabled')) {
        return Http::post('http://attendance-service/api/scan', $request->all());
    } else {
        return app(MonolithAttendanceController::class)->scan($request);
    }
});
```

---

## Testing Strategy

### Unit Tests

```php
// tests/Unit/Events/AttendanceRecordedTest.php
class AttendanceRecordedTest extends TestCase
{
    public function test_event_has_required_fields()
    {
        $attendance = Attendance::factory()->create();
        $event = AttendanceRecorded::fromAttendance($attendance);
        
        $this->assertNotEmpty($event->eventId);
        $this->assertEquals('attendance.recorded.v1', $event->eventType);
        $this->assertEquals('Attendance', $event->aggregateType);
        $this->assertEquals($attendance->id, $event->aggregateId);
    }
}
```

### Integration Tests

```php
// tests/Integration/EventPublishingTest.php
class EventPublishingTest extends TestCase
{
    public function test_attendance_event_is_published()
    {
        Event::fake();
        
        $attendance = Attendance::factory()->create();
        
        Event::assertDispatched(AttendanceRecorded::class, function ($event) use ($attendance) {
            return $event->aggregateId === $attendance->id;
        });
    }
}
```

---

## Monitoring & Observability

### Metrics to Track

1. **Event Publishing**
   - Events published per second
   - Event publishing latency
   - Failed events (dead letter queue size)

2. **Service Health**
   - Service uptime
   - API response times
   - Error rates

3. **Data Consistency**
   - Dual-write consistency checks
   - Event processing lag
   - Projection staleness

### Grafana Dashboard

```yaml
# prometheus/attendance-metrics.yml
- job_name: 'attendance-services'
  static_configs:
    - targets:
      - 'monolith:9090'
      - 'reporting-service:9090'
      - 'billing-service:9090'
      - 'attendance-service:9090'
```

---

## Rollback Procedures

### Phase 1 Rollback

```bash
# Disable event publishing
php artisan config:set events.publishing_enabled false
php artisan config:cache
```

### Phase 2 Rollback

```bash
# Disable reporting service
php artisan config:set features.reporting_service_enabled false

# Stop consumer
supervisorctl stop reporting-consumer
```

### Phase 3 Rollback

```bash
# Switch billing service to shadow mode
php artisan config:set features.billing_service_primary false
```

### Phase 4 Rollback

```bash
# Route traffic back to monolith
kubectl set env deployment/api-gateway \
  FEATURE_ATTENDANCE_SERVICE_ENABLED=false
```

---

## Troubleshooting

### Events Not Publishing

```bash
# Check Kafka is running
docker-compose -f docker-compose.kafka.yml ps

# Check Kafka logs
docker logs kafka

# Test event publishing
php artisan tinker
>>> event(new \App\Events\Attendance\AttendanceRecorded(['student_id' => 1]));
```

### Consumer Not Processing Events

```bash
# Check consumer is running
supervisorctl status reporting-consumer

# Check consumer logs
tail -f storage/logs/consumer.log

# Check Kafka consumer group
docker exec -it kafka kafka-consumer-groups \
  --bootstrap-server localhost:9092 \
  --describe --group reporting-service
```

### Data Inconsistency

```bash
# Run consistency check
php artisan billing:verify-consistency

# Reconcile data
php artisan billing:reconcile --dry-run
php artisan billing:reconcile
```

---

## Next Steps

1. ✅ Review migration strategy
2. ✅ Set up Kafka infrastructure
3. ✅ Implement event foundation
4. ⏳ Deploy reporting service
5. ⏳ Extract billing service
6. ⏳ Migrate attendance core
7. ⏳ Decommission monolith (optional)
