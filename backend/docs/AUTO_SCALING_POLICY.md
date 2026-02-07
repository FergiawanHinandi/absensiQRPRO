# Auto-Scaling Policy — AbsensiQRPro

## Overview

Auto-scaling policy for Laravel app servers and queue workers to handle variable workloads while maintaining performance and cost efficiency.

---

## Part 1: App Server Scaling

### Instance Limits

| Parameter | Value | Description |
|-----------|-------|-------------|
| **Minimum Instances** | 2 | Always maintain at least 2 instances for HA |
| **Maximum Instances** | 6 | Cost cap / resource limit |
| **Cooldown Period** | 5 minutes | Wait time between scaling actions |

---

## Scaling Triggers — Summary Table

| Trigger | Metric | Scale UP Threshold | Scale DOWN Threshold | Evaluation Periods | Weight |
|---------|--------|-------------------|---------------------|-------------------|--------|
| **CPU Usage** | Percentage | > 70% | < 30% | 3 / 5 | 30% |
| **HTTP Request Rate** | RPS per instance | > 100 RPS | < 20 RPS | 2 / 5 | 25% |
| **Response Time** | Milliseconds (avg) | > 500ms | < 100ms | 3 / 5 | 25% |
| **Queue Backlog** | Pending jobs | > 500 jobs | < 50 jobs | 2 / 5 | 20% |

---

## Detailed Scaling Rules

### 1. CPU Usage Scaling

| Direction | Condition | Action | Cooldown |
|-----------|-----------|--------|----------|
| **Scale UP** | CPU > 70% for 3 consecutive minutes | Add 1 instance | 5 min |
| **Scale DOWN** | CPU < 30% for 5 consecutive minutes | Remove 1 instance | 5 min |
| **Emergency** | CPU > 90% | Add 2 instances (bypass cooldown) | — |

**Rationale**: CPU is the primary indicator of compute saturation. 70% provides headroom for traffic spikes.

---

### 2. HTTP Request Rate Scaling

| Direction | Condition | Action | Cooldown |
|-----------|-----------|--------|----------|
| **Scale UP** | > 100 RPS/instance for 2 minutes | Add 1 instance | 5 min |
| **Scale DOWN** | < 20 RPS/instance for 5 minutes | Remove 1 instance | 5 min |

**Rationale**: Each Laravel instance (PHP-FPM) handles ~100 concurrent requests optimally. Scale proactively before saturation.

---

### 3. Response Time Scaling

| Direction | Condition | Action | Cooldown |
|-----------|-----------|--------|----------|
| **Scale UP** | Avg response > 500ms for 3 minutes | Add 1 instance | 5 min |
| **Scale DOWN** | Avg response < 100ms for 5 minutes | Remove 1 instance | 5 min |
| **Emergency** | Avg response > 2000ms | Add 2 instances (bypass cooldown) | — |

**Rationale**: Response time directly impacts user experience. 500ms is the "noticeable delay" threshold.

---

### 4. Queue Job Backlog Scaling

| Direction | Condition | Action | Cooldown |
|-----------|-----------|--------|----------|
| **Scale UP** | > 500 pending jobs for 2 minutes | Add 2 instances | 5 min |
| **Scale DOWN** | < 50 pending jobs for 5 minutes | Remove 1 instance | 5 min |
| **Emergency** | > 2000 pending jobs | Add 2 instances (bypass cooldown) | — |

**Rationale**: Queue backlog indicates async processing pressure. Aggressive scale-up (2 instances) to clear backlogs quickly.

---

## Emergency/Aggressive Scaling

Bypass cooldown period when critical thresholds are reached:

| Metric | Emergency Threshold | Action |
|--------|---------------------|--------|
| CPU Usage | > 90% | +2 instances immediately |
| Response Time | > 2000ms | +2 instances immediately |
| Queue Backlog | > 2000 jobs | +2 instances immediately |
| Error Rate | > 5% | +2 instances immediately |

---

## Scheduled Scaling (Predictive)

Pre-scale for known traffic patterns (school attendance times):

| Schedule | Time | Target Instances | Rationale |
|----------|------|------------------|-----------|
| School Start | 06:00 Mon-Fri | 4 | Prepare for morning attendance |
| Peak Hours | 07:00-08:00 Mon-Fri | 6 | Maximum attendance scanning |
| Afternoon | 15:00 Mon-Fri | 3 | End of school day |
| Off Hours | 20:00 Daily | 2 | Minimum for maintenance |

---

## Scaling Decision Flowchart

```
┌─────────────────────────────────────────────────────────┐
│                   Evaluate Metrics                       │
│    (CPU, RPS, Response Time, Queue Backlog)             │
└─────────────────────────────────────────────────────────┘
                          │
                          ▼
              ┌───────────────────────┐
              │ Emergency Threshold?  │
              │ (CPU>90%, RT>2s, etc) │
              └───────────────────────┘
                    │         │
                   YES        NO
                    │         │
                    ▼         ▼
          ┌─────────────┐  ┌──────────────────────┐
          │ Bypass      │  │ Within Cooldown?     │
          │ Cooldown    │  │ (Last action < 5min) │
          │ Add 2 inst  │  └──────────────────────┘
          └─────────────┘        │         │
                                YES        NO
                                 │         │
                                 ▼         ▼
                           ┌─────────┐  ┌──────────────────┐
                           │ Skip    │  │ Calculate Score  │
                           │ Action  │  │ (Weighted avg)   │
                           └─────────┘  └──────────────────┘
                                                │
                                                ▼
                                   ┌─────────────────────┐
                                   │ Score > Scale Up?   │
                                   │ Score < Scale Down? │
                                   └─────────────────────┘
                                      │    │    │
                                     UP   OK   DOWN
                                      │    │    │
                                      ▼    ▼    ▼
                              ┌────────┐ ┌───┐ ┌─────────┐
                              │+1 inst │ │NOP│ │-1 inst  │
                              │if <max │ │   │ │if >min  │
                              └────────┘ └───┘ └─────────┘
```

---

## Cloud Provider Implementation

### AWS Auto Scaling Group

```json
{
  "AutoScalingGroupName": "absensi-api-asg",
  "MinSize": 2,
  "MaxSize": 6,
  "DesiredCapacity": 2,
  "DefaultCooldown": 300,
  "HealthCheckGracePeriod": 120,
  "TargetGroupARNs": ["arn:aws:elasticloadbalancing:..."],
  "Policies": [
    {
      "PolicyName": "cpu-scale-up",
      "PolicyType": "TargetTrackingScaling",
      "TargetTrackingConfiguration": {
        "PredefinedMetricSpecification": {
          "PredefinedMetricType": "ASGAverageCPUUtilization"
        },
        "TargetValue": 70.0
      }
    }
  ]
}
```

### Kubernetes HPA (Horizontal Pod Autoscaler)

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: absensi-api-hpa
  namespace: production
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: absensi-api
  minReplicas: 2
  maxReplicas: 6
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 70
    - type: Pods
      pods:
        metric:
          name: http_requests_per_second
        target:
          type: AverageValue
          averageValue: 100
  behavior:
    scaleDown:
      stabilizationWindowSeconds: 300
      policies:
        - type: Pods
          value: 1
          periodSeconds: 300
    scaleUp:
      stabilizationWindowSeconds: 60
      policies:
        - type: Pods
          value: 2
          periodSeconds: 60
```

---

## Monitoring & Alerting

### Metrics to Monitor

| Metric | Source | Scrape Interval |
|--------|--------|-----------------|
| CPU Utilization | CloudWatch / Prometheus | 30s |
| Request Rate | ALB / nginx metrics | 30s |
| Response Time (p95) | APM / CloudWatch | 30s |
| Queue Size | Redis / Laravel Horizon | 15s |
| Instance Count | ASG / K8s | 60s |

### Alert Thresholds

| Alert | Condition | Severity |
|-------|-----------|----------|
| Max Instances Reached | instances == 6 | Warning |
| Scaling Stuck | desired != actual for 10min | Critical |
| High Error Rate | 5xx > 5% | Critical |
| Cooldown Bypass Triggered | emergency scaling | Warning |

---

## Environment Configuration

```env
# Autoscaling
AUTOSCALING_ENABLED=true
AUTOSCALING_MIN_INSTANCES=2
AUTOSCALING_MAX_INSTANCES=6
AUTOSCALING_COOLDOWN_UP=300
AUTOSCALING_COOLDOWN_DOWN=300
AUTOSCALING_SCHEDULED=true

# Cloud Provider
AUTOSCALING_PROVIDER=aws
AWS_ASG_NAME=absensi-api-asg
AWS_LAUNCH_TEMPLATE_ID=lt-0123456789

# Notifications
SLACK_SCALING_WEBHOOK=https://hooks.slack.com/services/...
```

---

## Cost Optimization

| Instances | Estimated Monthly Cost (AWS t3.medium) |
|-----------|----------------------------------------|
| 2 (minimum) | ~$60 |
| 4 (average) | ~$120 |
| 6 (maximum) | ~$180 |

**Strategies**:
1. Use Spot Instances for 2 of max 6 (30% savings)
2. Reserved Instances for minimum 2 (up to 40% savings)
3. Scheduled scaling to minimize during off-hours

---

---

## Stateless Server Requirements

For auto-scaling to work correctly, app servers **MUST** be stateless:

| Requirement | Configuration | Purpose |
|-------------|---------------|---------|
| **Session Storage** | `SESSION_DRIVER=redis` | Sessions accessible from any instance |
| **Cache Storage** | `CACHE_DRIVER=redis` | Cache shared across instances |
| **Queue Connection** | `QUEUE_CONNECTION=redis` | Jobs processed by any worker |
| **File Storage** | S3/GCS/NFS | Shared storage for uploads |
| **Logs** | `LOG_CHANNEL=stderr` | Centralized logging (CloudWatch/Stackdriver) |

### Laravel Configuration Checklist

```env
# Required for stateless operation
SESSION_DRIVER=redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
FILESYSTEM_DISK=s3
LOG_CHANNEL=stderr

# Optional: Sticky sessions NOT recommended
SESSION_SECURE_COOKIE=true
```

---

## Health Check Endpoints

Existing endpoints for load balancer integration:

| Endpoint | Purpose | Response |
|----------|---------|----------|
| `GET /health` | Basic liveness | `{"status": "healthy"}` |
| `GET /health/detailed` | Full dependency check | All services status |
| `GET /health/load-balancer` | LB health check | DB + Redis quick check |

---

## Infrastructure Files

| Provider | File | Purpose |
|----------|------|---------|
| **Kubernetes** | [infrastructure/kubernetes/hpa.yaml](../../infrastructure/kubernetes/hpa.yaml) | HPA + Deployment + Service |
| **AWS** | [infrastructure/aws/autoscaling.tf](../../infrastructure/aws/autoscaling.tf) | ASG + Scaling Policies |
| **AWS** | [infrastructure/aws/userdata.sh](../../infrastructure/aws/userdata.sh) | Bootstrap script |
| **GCP** | [infrastructure/gcp/instance-group.tf](../../infrastructure/gcp/instance-group.tf) | MIG + Autoscaler |
| **GCP** | [infrastructure/gcp/startup-script.sh](../../infrastructure/gcp/startup-script.sh) | Bootstrap script |
| **Supervisor** | [infrastructure/supervisor/queue-workers.conf](../../infrastructure/supervisor/queue-workers.conf) | Queue worker config |
| **Kubernetes** | [infrastructure/kubernetes/queue-worker-autoscaler.yaml](../../infrastructure/kubernetes/queue-worker-autoscaler.yaml) | KEDA queue scaling |

---

## Part 2: Queue Worker Scaling

Attendance scanning generates many async jobs (notifications, logging, etc.). Auto-scale workers to prevent queue buildup.

### Queue Worker Limits

| Parameter | Value | Description |
|-----------|-------|-------------|
| **Minimum Workers** | 1 | At least 1 worker always running |
| **Maximum Workers** | 10 | Cost cap for workers |
| **Scale Up Cooldown** | 1 minute | Quick response to backlog |
| **Scale Down Cooldown** | 5 minutes | Avoid premature removal |

### Queue Worker Scaling Rules

| Direction | Condition | Action | Cooldown |
|-----------|-----------|--------|----------|
| **Scale UP** | > 500 pending jobs | Add 1 worker | 1 min |
| **Scale DOWN** | < 100 jobs for 10 minutes | Remove 1 worker | 5 min |
| **Emergency** | > 2000 pending jobs | Add 3 workers (bypass cooldown) | — |

### Scaling Decision Flow

```
┌─────────────────────────────────────────────────────────┐
│          Monitor Queue Length (every 60s)               │
│          Queues: default, high, low, notifications      │
└─────────────────────────────────────────────────────────┘
                          │
                          ▼
              ┌───────────────────────┐
              │ Pending Jobs > 500?   │
              └───────────────────────┘
                    │         │
                   YES        NO
                    │         │
                    ▼         ▼
          ┌─────────────┐  ┌──────────────────────┐
          │ Scale UP    │  │ Pending Jobs < 100?  │
          │ +1 Worker   │  └──────────────────────┘
          └─────────────┘        │         │
                                YES        NO
                                 │         │
                                 ▼         ▼
                    ┌────────────────┐  ┌─────────┐
                    │ Low for 10min? │  │  HOLD   │
                    └────────────────┘  └─────────┘
                          │         │
                         YES        NO
                          │         │
                          ▼         ▼
                   ┌────────────┐ ┌─────────────┐
                   │ Scale DOWN │ │ Track time  │
                   │ -1 Worker  │ │ (wait more) │
                   └────────────┘ └─────────────┘
```

### Queue Worker Commands

```bash
# Check current status
php artisan queue:scale --status

# Run single evaluation
php artisan queue:scale

# Run as daemon (continuous monitoring)
php artisan queue:scale --daemon --interval=60

# JSON output for CI/CD
php artisan queue:scale --json
```

### Environment Configuration

```env
# Queue Worker Auto-Scaling
QUEUE_SCALING_ENABLED=true
QUEUE_SCALE_UP_THRESHOLD=500
QUEUE_SCALE_DOWN_THRESHOLD=100
QUEUE_SCALE_DOWN_DURATION=600
QUEUE_MIN_WORKERS=1
QUEUE_MAX_WORKERS=10

# Provider: supervisor, kubernetes, aws, horizon
QUEUE_SCALING_PROVIDER=supervisor
SUPERVISOR_WORKER_PROGRAM=laravel-worker
```

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-02-03 | Initial scaling policy |
| 1.1 | 2026-02-03 | Added K8s, AWS, GCP implementations |
| 1.2 | 2026-02-03 | Added queue worker auto-scaling |
