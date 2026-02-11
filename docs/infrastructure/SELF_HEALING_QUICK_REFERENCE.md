# Autonomous Self-Healing Infrastructure - Quick Reference

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    MONITORING LAYER                              │
│  /health/deep → Prometheus → Alertmanager → PagerDuty/Slack    │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                  FAILURE DETECTION                               │
│  FailureClassifier → Transient | Resource | Dependency | Data   │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                  AUTO-REMEDIATION                                │
│  ┌──────────────┬──────────────┬──────────────┬──────────────┐ │
│  │ Retry        │ Auto-Scale   │ Circuit      │ Freeze       │ │
│  │ (Transient)  │ (Resource)   │ Breaker      │ (Corruption) │ │
│  │              │              │ (Dependency) │              │ │
│  └──────────────┴──────────────┴──────────────┴──────────────┘ │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                  KUBERNETES AUTO-SCALING                         │
│  HPA (Queue Workers) | HPA (Web App) | Custom Metrics           │
└─────────────────────────────────────────────────────────────────┘
```

## Key Components

### 1. Health Check System
- **Endpoint**: `GET /api/v1/health/deep`
- **Checks**: Database, Redis, Queue, Disk, Memory, Tenant Isolation
- **Status Levels**: `healthy`, `degraded`, `critical`

### 2. Failure Classifier
- **Transient**: Deadlocks, timeouts → Retry with backoff
- **Resource Exhaustion**: Memory, disk, connections → Auto-scale
- **Dependency Failure**: Redis/DB down → Circuit breaker
- **Data Corruption**: Duplicate nonce, isolation breach → Freeze writes

### 3. Circuit Breaker
- **States**: CLOSED → OPEN → HALF_OPEN → CLOSED
- **Redis**: 3 failures → open, 10s timeout, 2 successes → close
- **Database**: 5 failures → open, 30s timeout, 3 successes → close

### 4. Auto-Remediation Rules

| Condition | Threshold | Action | Cooldown |
|-----------|-----------|--------|----------|
| Queue lag high | > 500 jobs | Scale worker +1 | 5 min |
| Queue lag critical | > 2000 jobs | Scale worker +2 | 5 min |
| Redis memory high | > 85% | Flush non-critical cache | 10 min |
| Redis memory critical | > 95% | Emergency flush | 15 min |
| DB connection spike | > 80% | Enable read replica | 3 min |
| DB slow queries | P95 > 500ms | Degraded mode | 5 min |
| App crash | Exit code != 0 | Restart container | 30 sec |

### 5. Auto-Scaling (Kubernetes HPA)

**Queue Workers:**
- Min: 2, Max: 10
- Triggers: CPU > 70%, Queue lag > 100 jobs/worker
- Scale up: +50% or +2 pods (60s stabilization)
- Scale down: -10% (300s stabilization)

**Web App:**
- Min: 3, Max: 20
- Triggers: CPU > 60%, RPS > 50/pod
- Scale up: +100% or +4 pods (30s stabilization)
- Scale down: -10% (600s stabilization)

## Recovery SLA

| Failure | Detection | Remediation | Total | Data Loss |
|---------|-----------|-------------|-------|-----------|
| Redis timeout | < 5s | < 5s | < 10s | 0 |
| Redis OOM | < 10s | < 50s | < 60s | 0 |
| DB deadlock | < 1s | < 5s | < 6s | 0 |
| DB connection exhaustion | < 10s | < 20s | < 30s | 0 |
| DB primary down | < 15s | < 30s | < 45s | 0 |
| Queue worker crash | < 5s | < 30s | < 35s | 0 jobs |
| Network partition | < 10s | < 20s | < 30s | 0 |

## Quick Commands

```bash
# Check health
curl http://localhost/api/v1/health/deep | jq .

# Circuit breaker status
php artisan circuit-breaker:status redis

# Self-healing monitor
php artisan self-healing:monitor

# Run chaos tests
./infrastructure/scripts/chaos-test.sh

# Check HPA status
kubectl get hpa -n production

# Export metrics
php artisan metrics:export
```

## File Structure

```
backend/
├── app/
│   ├── Console/Commands/
│   │   ├── CircuitBreakerStatus.php
│   │   └── SelfHealingMonitor.php
│   ├── Events/
│   │   ├── CircuitBreakerOpened.php
│   │   ├── CircuitBreakerClosed.php
│   │   ├── AutoRemediationTriggered.php
│   │   ├── SystemDegradedModeEnabled.php
│   │   └── SystemDegradedModeDisabled.php
│   ├── Http/Controllers/
│   │   └── HealthCheckController.php
│   └── Services/SelfHealing/
│       ├── FailureClassifier.php
│       ├── CircuitBreaker.php
│       └── AutoRemediationService.php
├── config/
│   └── self-healing.php
└── routes/api/v1/
    └── common.php (health routes)

infrastructure/
├── k8s/
│   ├── hpa-queue-worker.yaml
│   └── hpa-web-app.yaml
└── scripts/
    └── chaos-test.sh

docs/infrastructure/
├── SELF_HEALING_ARCHITECTURE.md
└── SELF_HEALING_IMPLEMENTATION_GUIDE.md
```

## Alerts Configuration

**Critical (PagerDuty):**
- Circuit breaker opened
- Auto-failover triggered
- Tenant isolation breach
- Data corruption detected
- Auto-remediation failed

**Warning (Slack):**
- Auto-scaling triggered
- Degraded mode enabled
- Replication lag > 5s
- Failed jobs > 50 in 5 min

## Testing Checklist

- [ ] Redis failure → Circuit breaker opens → Fallback works → Auto-recovery
- [ ] DB primary down → Auto-failover → No data loss
- [ ] Queue lag spike → Workers auto-scale → Lag reduces
- [ ] Network partition → Circuit breaker → Recovery after restore
- [ ] Memory pressure → Auto-flush → System stable

## Production Deployment

1. **Deploy health checks** (Week 1-2)
2. **Enable circuit breakers** (Week 3-4)
3. **Activate auto-remediation** (Week 5-6)
4. **Configure auto-scaling** (Week 7-8)
5. **Run chaos tests** (Week 9)
6. **Monitor & tune** (Week 10+)

## Support

For issues or questions:
- Check logs: `kubectl logs -n production deployment/attendance-web-app`
- Monitor: `php artisan self-healing:monitor`
- Manual intervention: See troubleshooting guide in implementation docs
