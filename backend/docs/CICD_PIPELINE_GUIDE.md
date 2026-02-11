# 🚀 CI/CD Pipeline - Complete Guide

**DevOps Lead**  
**Date**: 2026-02-10  
**Status**: ✅ Production-Ready

---

## 📋 Executive Summary

Production-safe CI/CD pipeline for Laravel CQRS SaaS with:
- **7 deployment stages** (Static Analysis → Blue/Green Deployment)
- **Automated rollback** (Error rate >5%, Deadlocks, Redis timeout)
- **Zero-downtime deployment** (Blue/Green strategy)
- **Comprehensive monitoring** (Horizon, Prometheus, Grafana)
- **<2 minute rollback** time

---

## 🎯 Pipeline Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    1. Static Analysis                        │
│  PHPStan Level 8 | Rector | Tenant Model Check              │
└──────────────────────┬──────────────────────────────────────┘
                       │ ✅ Pass
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                    2. Tests (Parallel)                       │
│  Unit | Domain | Integration | Concurrency | Security       │
│  Coverage: >85%                                              │
└──────────────────────┬──────────────────────────────────────┘
                       │ ✅ Pass
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                    3. Security Scan                          │
│  Composer Audit | NPM Audit | Trivy Scan                    │
└──────────────────────┬──────────────────────────────────────┘
                       │ ✅ Pass
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                    4. Build                                  │
│  Cache Config | Cache Routes | Build Assets                 │
└──────────────────────┬──────────────────────────────────────┘
                       │ ✅ Pass
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                    5. Migration (Safe Mode)                  │
│  Backup DB | Check Locks | Migrate --force                  │
└──────────────────────┬──────────────────────────────────────┘
                       │ ✅ Pass
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                    6. Blue/Green Deployment                  │
│  Deploy to Target | Health Check | Switch Traffic           │
└──────────────────────┬──────────────────────────────────────┘
                       │ ✅ Pass
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                    7. Post-Deploy Monitoring                 │
│  Monitor 30 min | Auto-Rollback if Critical                 │
└─────────────────────────────────────────────────────────────┘
```

---

## 📁 Deliverables

### 1. CI/CD Configuration ✅
- **`.github/workflows/ci-cd-pipeline.yml`** - Complete GitHub Actions workflow

### 2. Documentation ✅
- **`DEPLOYMENT_CHECKLIST.md`** - Step-by-step deployment guide
- **`ROLLBACK_DECISION_TREE.md`** - Automated rollback logic
- **`MONITORING_INTEGRATION.md`** - Monitoring setup guide

### 3. Health Check System ✅
- **`HealthCheckController.php`** - Health check endpoints

---

## 🎯 Stage Details

### Stage 1: Static Analysis (5 min)

**Purpose**: Catch code quality issues before testing

**Checks**:
- ✅ PHPStan Level 8 (strict type checking)
- ✅ Rector dry run (code modernization)
- ✅ No `DB::table` in tenant models (enforce Eloquent)
- ✅ Tenant scope validation

**Example**:
```yaml
- name: Run PHPStan (Level 8)
  run: vendor/bin/phpstan analyse --level=8 --memory-limit=2G
  continue-on-error: false
```

**Fail Criteria**:
- PHPStan errors found
- `DB::table` usage in Models
- Missing tenant scopes

---

### Stage 2: Tests (10 min)

**Purpose**: Ensure code quality and coverage

**Test Suites**:
- ✅ Unit Tests
- ✅ Domain Tests
- ✅ Integration Tests
- ✅ Concurrency Tests
- ✅ Security Tests

**Coverage**: >85% required

**Example**:
```yaml
- name: Run tests (Parallel)
  run: php artisan test --parallel --processes=4 --coverage --min=85
```

**Fail Criteria**:
- Any test fails
- Coverage <85%
- Concurrency tests fail

---

### Stage 3: Security Scan (5 min)

**Purpose**: Identify security vulnerabilities

**Scans**:
- ✅ Composer audit (PHP dependencies)
- ✅ NPM audit (JS dependencies)
- ✅ Trivy scan (container vulnerabilities)
- ✅ Secret detection (no hardcoded credentials)

**Example**:
```yaml
- name: Composer Audit
  run: composer audit --no-dev
  continue-on-error: false
```

**Fail Criteria**:
- Critical vulnerabilities found
- Secrets detected in code
- Outdated dependencies with known CVEs

---

### Stage 4: Build (5 min)

**Purpose**: Prepare optimized production build

**Steps**:
- ✅ Install production dependencies
- ✅ Cache config, routes, events, views
- ✅ Build frontend assets
- ✅ Create deployment artifact

**Example**:
```yaml
- name: Cache config
  run: php artisan config:cache

- name: Build frontend assets
  run: cd frontend-web && npm run build
```

**Artifact**: `deployment.tar.gz`

---

### Stage 5: Migration (Safe Mode) (10 min)

**Purpose**: Update database schema safely

**Steps**:
1. ✅ Backup database
2. ✅ Check for database locks
3. ✅ Run migrations (--pretend first)
4. ✅ Run migrations (--force)

**Example**:
```bash
# Backup database
php artisan backup:run --only-db

# Check for locks
php artisan db:check-locks

# Run migrations
php artisan migrate --force
```

**Rollback**: Restore from backup if migration fails

---

### Stage 6: Blue/Green Deployment (15 min)

**Purpose**: Zero-downtime deployment

**Steps**:

1. **Deploy to Target Environment**
   ```bash
   # Determine target (blue or green)
   CURRENT=$(readlink current | grep -o 'blue\|green')
   TARGET=$([ "$CURRENT" = "blue" ] && echo "green" || echo "blue")
   
   # Extract deployment
   tar -xzf deployment.tar.gz -C $TARGET/
   ```

2. **Health Checks**
   ```bash
   curl -f https://$TARGET.absensiqr.com/api/health/redis
   curl -f https://$TARGET.absensiqr.com/api/health/database
   curl -f https://$TARGET.absensiqr.com/api/health/queue
   curl -f https://$TARGET.absensiqr.com/api/health/attendance-test
   ```

3. **Switch Traffic**
   ```bash
   ln -sfn $TARGET current
   sudo systemctl reload nginx
   ```

**Downtime**: 0 seconds

---

### Stage 7: Post-Deploy Monitoring (30 min)

**Purpose**: Detect issues early and auto-rollback

**Metrics Monitored**:
- ✅ Error rate (<5%)
- ✅ Response time (<500ms)
- ✅ Queue depth (<5000)
- ✅ Database failures (0)
- ✅ Redis health

**Auto-Rollback Triggers**:
- ❌ Error rate >5%
- ❌ Deadlock spike (>5 in 5 min)
- ❌ Redis timeout >2 min
- ❌ Database failures >10
- ❌ Critical feature broken

**Example**:
```bash
ERROR_RATE=$(curl -s https://absensiqr.com/api/metrics/error-rate | jq -r '.rate')
if (( $(echo "$ERROR_RATE > 5" | bc -l) )); then
  echo "CRITICAL: Triggering rollback!"
  ./rollback.sh
fi
```

---

## 🔄 Rollback Strategy

### Automatic Rollback (<2 min)

**Triggers**:
1. Error rate >5%
2. Deadlock spike detected
3. Redis timeout >2 minutes
4. Database connection failures
5. Critical feature broken

**Process**:
```bash
# 1. Switch to previous environment
PREVIOUS=$([ "$CURRENT" = "blue" ] && echo "green" || echo "blue")
ln -sfn $PREVIOUS current

# 2. Reload services
sudo systemctl reload nginx
sudo systemctl reload php8.2-fpm

# 3. Clear caches
php artisan cache:clear
php artisan queue:restart

# 4. Verify
curl -f https://absensiqr.com/api/health
```

**Time**: <2 minutes

---

## 📊 Monitoring Integration

### Horizon Dashboard

**URL**: `https://absensiqr.com/horizon`

**Metrics**:
- Jobs per minute
- Failed jobs
- Queue wait times
- Memory usage

### Health Endpoints

| Endpoint | Purpose | Threshold |
|----------|---------|-----------|
| `/api/health` | Overall health | All checks OK |
| `/api/health/redis` | Redis health | Timeout <2 min |
| `/api/health/database` | DB health | Response <100ms |
| `/api/health/queue` | Queue health | Depth <5000 |
| `/api/health/attendance-test` | Critical feature | Status OK |
| `/api/metrics/error-rate` | Error rate | <5% |
| `/api/metrics/response-time` | Response time | <500ms |

### Alerting

**Slack**: Critical alerts
**PagerDuty**: Escalation for rollbacks
**SMS**: DevOps Lead for critical issues

---

## ✅ Pre-Deployment Checklist

- [ ] All tests passing (>85% coverage)
- [ ] PHPStan Level 8 passing
- [ ] Security scans passed
- [ ] Database backup completed
- [ ] Code review approved
- [ ] Staging tested
- [ ] Team notified
- [ ] Rollback plan ready

---

## 📈 Success Metrics

| Metric | Target | Current |
|--------|--------|---------|
| Deployment Frequency | Daily | TBD |
| Deployment Time | <30 min | TBD |
| Rollback Time | <2 min | TBD |
| Success Rate | >95% | TBD |
| Downtime | 0 sec | TBD |
| Test Coverage | >85% | TBD |

---

## 🎓 Best Practices

1. **Always test in staging first**
2. **Never skip health checks**
3. **Monitor for 30 minutes post-deploy**
4. **Keep rollback plan ready**
5. **Document all deployments**
6. **Review failed deployments**
7. **Update runbooks regularly**

---

## 📝 Quick Reference

### Deploy to Staging
```bash
git push origin staging
# CI/CD automatically deploys
```

### Deploy to Production
```bash
git push origin main
# CI/CD automatically deploys with blue/green
```

### Manual Rollback
```bash
ssh production
cd /var/www/production
./rollback.sh
```

### Check Health
```bash
curl https://absensiqr.com/api/health | jq
```

### Monitor Metrics
```bash
watch -n 5 'curl -s https://absensiqr.com/api/metrics | jq'
```

---

## 🆘 Emergency Contacts

| Role | Contact | Availability |
|------|---------|--------------|
| DevOps Lead | [Phone] | 24/7 |
| Backend Lead | [Phone] | 24/7 |
| Database Admin | [Phone] | On-call |

---

## 📚 Documentation

1. **[CI/CD Pipeline](.github/workflows/ci-cd-pipeline.yml)** - GitHub Actions workflow
2. **[Deployment Checklist](DEPLOYMENT_CHECKLIST.md)** - Step-by-step guide
3. **[Rollback Decision Tree](ROLLBACK_DECISION_TREE.md)** - Rollback logic
4. **[Monitoring Integration](MONITORING_INTEGRATION.md)** - Monitoring setup
5. **[Health Check Controller](app/Http/Controllers/Api/HealthCheckController.php)** - Health endpoints

---

**Status**: ✅ Production-Ready  
**Last Updated**: 2026-02-10  
**Version**: 1.0
