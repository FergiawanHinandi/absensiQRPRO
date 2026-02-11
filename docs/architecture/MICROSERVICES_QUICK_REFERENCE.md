# Microservices Migration - Quick Reference

## Migration Overview

**Goal:** Evolve Laravel monolith to event-driven microservices without breaking production

**Timeline:** 6-9 months  
**Strategy:** Strangler Fig Pattern + Event Sourcing  
**Risk Level:** Medium (with mitigation)

---

## Service Boundaries

| Service | Responsibility | Database | Status |
|---------|---------------|----------|--------|
| **Monolith** | User management, orchestration | MySQL | Active |
| **Attendance Service** | QR scanning, check-in/out, validation | PostgreSQL + Event Store | Phase 4 |
| **Billing Service** | Subscriptions, payments, invoicing | PostgreSQL | Phase 3 |
| **Reporting Service** | Dashboards, analytics, exports | MongoDB | Phase 2 |
| **Notification Service** | Email, SMS, push notifications | Redis | Phase 2-3 |
| **Audit Service** | Event logging, compliance | Event Store | Phase 1 |

---

## Event Catalog

### Attendance Events

```
attendance.recorded.v1
attendance.validated.v1
attendance.anomaly.detected.v1
```

### Billing Events

```
billing.subscription.created.v1
billing.payment.settled.v1
billing.subscription.expired.v1
```

### Notification Events

```
notification.requested.v1
notification.sent.v1
notification.failed.v1
```

### Audit Events

```
audit.security.logged.v1
audit.action.recorded.v1
```

---

## Event Structure

```json
{
  "event_id": "uuid-v7",
  "event_type": "attendance.recorded.v1",
  "event_version": "1.0.0",
  "timestamp": "2026-02-11T09:24:14+08:00",
  "trace_id": "uuid-v4",
  "correlation_id": "uuid-v4",
  "causation_id": "uuid-v4",
  "aggregate_type": "Attendance",
  "aggregate_id": "att-123",
  "school_id": "school-456",
  "tenant_id": "tenant-789",
  "user_id": "user-101",
  "metadata": {
    "source": "attendance-app",
    "environment": "production",
    "ip_address": "192.168.1.1"
  },
  "payload": {
    // Event-specific data
  }
}
```

---

## Migration Phases

### Phase 1: Event Foundation (Month 1-2)

**Goal:** Emit domain events from monolith

**Steps:**
1. Install Kafka infrastructure
2. Create event base classes
3. Emit events from existing code
4. Verify events are published

**Deliverables:**
- ✅ Kafka running
- ✅ Events emitted for all domain actions
- ✅ Event monitoring dashboard

**Rollback:** Stop publishing events

---

### Phase 2: Reporting Service (Month 3-4)

**Goal:** Extract read-only reporting

**Architecture:**
```
Monolith (Writes) → Events → Reporting Service (Reads) → MongoDB
```

**Steps:**
1. Create reporting service
2. Build event projections
3. Implement dual-read pattern
4. Gradual rollout with feature flag

**Deliverables:**
- ✅ Reporting service deployed
- ✅ MongoDB read models
- ✅ Dashboard loads < 200ms

**Rollback:** Disable feature flag

---

### Phase 3: Billing Service (Month 5-6)

**Goal:** Extract billing with dual-write

**Architecture:**
```
Monolith Controller → Dual Write → [Monolith DB, Billing Service]
```

**Steps:**
1. Create billing service
2. Implement dual-write
3. Sync existing data
4. Gradual cutover (monolith → service primary)

**Deliverables:**
- ✅ Billing service deployed
- ✅ 100% data sync
- ✅ Payment gateway migrated

**Rollback:** Switch to monolith primary

---

### Phase 4: Attendance Core (Month 7-9)

**Goal:** Extract core attendance with event sourcing

**Architecture:**
```
Mobile App → API Gateway → Attendance Service → Event Store
```

**Steps:**
1. Create attendance service
2. Implement event sourcing
3. API gateway routing
4. Shadow mode testing

**Deliverables:**
- ✅ Attendance service with event sourcing
- ✅ QR scan latency < 500ms
- ✅ 100% attendance records captured

**Rollback:** API gateway routes to monolith

---

## Data Consistency

### Patterns Used

1. **Eventual Consistency** (across services)
   - Events processed asynchronously
   - Idempotent event handlers
   - Retry failed events

2. **Strong Consistency** (within service)
   - Database transactions
   - Event store as source of truth

3. **Dual Write** (during migration)
   - Write to both old and new systems
   - Verify consistency
   - Gradual cutover

4. **Saga Pattern** (distributed transactions)
   - Orchestrator coordinates steps
   - Compensating transactions on failure

---

## Communication Patterns

### Synchronous (HTTP)

```
API Gateway → Service → Response
```

**Use for:**
- User-facing operations
- Immediate feedback required
- Read operations

### Asynchronous (Events)

```
Service A → Event → Kafka → Service B
```

**Use for:**
- Background processing
- Cross-service updates
- Audit logging
- Analytics

---

## Technology Stack

| Component | Technology | Purpose |
|-----------|-----------|---------|
| **Message Broker** | Kafka | Event streaming |
| **Event Store** | PostgreSQL + Prooph | Event sourcing |
| **Read Models** | MongoDB | Reporting/analytics |
| **Cache** | Redis | Notifications, sessions |
| **API Gateway** | Kong / Laravel | Routing, auth |
| **Monitoring** | Prometheus + Grafana | Metrics, dashboards |
| **Tracing** | Jaeger | Distributed tracing |
| **Logging** | ELK Stack | Centralized logs |

---

## Feature Flags

```env
# Phase 1
EVENT_PUBLISHING_ENABLED=true

# Phase 2
FEATURE_REPORTING_SERVICE_ENABLED=true

# Phase 3
FEATURE_BILLING_SERVICE_ENABLED=true
FEATURE_BILLING_PRIMARY=false  # Switch to true for cutover

# Phase 4
FEATURE_ATTENDANCE_SERVICE_ENABLED=true
FEATURE_ATTENDANCE_SHADOW=true  # Test in shadow mode first
```

---

## Quick Commands

### Start Kafka

```bash
docker-compose -f infrastructure/docker-compose.kafka.yml up -d
```

### Publish Event

```php
event(AttendanceRecorded::fromAttendance($attendance));
```

### Consume Events

```bash
php artisan events:consume attendance.events
```

### Monitor Events

```bash
# Kafka console
docker exec -it kafka kafka-console-consumer \
  --bootstrap-server localhost:9092 \
  --topic attendance.events

# Kafka UI
open http://localhost:8080
```

### Sync Data

```bash
php artisan billing:sync --batch=1000
```

### Verify Consistency

```bash
php artisan billing:verify-consistency
```

### Rollback

```bash
# Emergency rollback script
./infrastructure/scripts/emergency-rollback.sh
```

---

## Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| **Data Loss** | Dual-write, event replay, backups |
| **Downtime** | Feature flags, gradual rollout, blue-green deployment |
| **Inconsistency** | Reconciliation jobs, consistency checks |
| **Event Loss** | Kafka replication, dead letter queue |
| **Breaking Changes** | Event versioning, backward compatibility |

---

## Success Criteria

### Phase 1
- [ ] 100% of domain actions emit events
- [ ] Events published with < 100ms latency
- [ ] Zero event loss

### Phase 2
- [ ] Dashboard loads < 200ms
- [ ] 99.9% data consistency
- [ ] Zero downtime

### Phase 3
- [ ] All payments processed successfully
- [ ] 100% data sync
- [ ] Zero revenue loss

### Phase 4
- [ ] QR scan latency < 500ms
- [ ] 100% attendance records captured
- [ ] Zero duplicate records

---

## Monitoring Dashboards

### Event Publishing Metrics
- Events published/sec
- Publishing latency (P50, P95, P99)
- Failed events (dead letter queue size)

### Service Health
- Service uptime (%)
- API response time (P50, P95, P99)
- Error rate (%)

### Data Consistency
- Dual-write consistency (%)
- Event processing lag (seconds)
- Projection staleness (seconds)

---

## File Structure

```
backend/
├── app/
│   ├── Events/
│   │   ├── DomainEvent.php                    ✓
│   │   ├── Attendance/
│   │   │   └── AttendanceRecorded.php         ✓
│   │   └── Billing/
│   │       └── SubscriptionCreated.php        ✓
│   ├── Services/Events/
│   │   └── EventPublisher.php                 ✓
│   └── Listeners/
│       └── PublishDomainEvent.php             ✓
├── config/
│   └── events.php                             ✓
└── routes/api.php

infrastructure/
├── docker-compose.kafka.yml                   ✓
└── scripts/
    └── emergency-rollback.sh

docs/architecture/
├── MICROSERVICES_MIGRATION_STRATEGY.md        ✓
└── MICROSERVICES_IMPLEMENTATION_GUIDE.md      ✓
```

---

## Support & Resources

**Documentation:**
- Migration Strategy: `docs/architecture/MICROSERVICES_MIGRATION_STRATEGY.md`
- Implementation Guide: `docs/architecture/MICROSERVICES_IMPLEMENTATION_GUIDE.md`

**Monitoring:**
- Kafka UI: http://localhost:8080
- Grafana: http://localhost:3000
- Jaeger: http://localhost:16686

**Team Contacts:**
- Architecture Lead: [Name]
- DevOps Lead: [Name]
- On-call Engineer: [PagerDuty]

---

## Next Actions

1. **Week 1**: Review and approve strategy
2. **Week 2**: Set up Kafka infrastructure
3. **Month 1**: Implement event foundation
4. **Month 2**: Test and validate events
5. **Month 3-4**: Deploy reporting service
6. **Month 5-6**: Extract billing service
7. **Month 7-9**: Migrate attendance core
8. **Month 10+**: Optimize and scale
