# 🚀 Production Deployment Checklist

**DevOps Lead**  
**Date**: 2026-02-10  
**Version**: 1.0

---

## 📋 Pre-Deployment Checklist

### 1. Code Quality ✅

- [ ] All tests passing (>85% coverage)
- [ ] PHPStan Level 8 passing
- [ ] No DB::table usage in tenant models
- [ ] Rector dry run completed
- [ ] Code review approved
- [ ] No merge conflicts

### 2. Security ✅

- [ ] Composer audit passed
- [ ] NPM audit passed
- [ ] No secrets in code
- [ ] Dependency vulnerability scan passed
- [ ] SSL certificates valid
- [ ] API keys rotated (if needed)

### 3. Database ✅

- [ ] Database backup completed
- [ ] Migration tested in staging
- [ ] No destructive migrations (DROP, TRUNCATE)
- [ ] Rollback migration tested
- [ ] Database lock check passed
- [ ] Read replica sync verified

### 4. Infrastructure ✅

- [ ] Server resources checked (CPU, RAM, Disk)
- [ ] Redis health verified
- [ ] Queue workers running
- [ ] Cron jobs verified
- [ ] Log rotation configured
- [ ] Monitoring alerts active

### 5. Configuration ✅

- [ ] .env file updated
- [ ] Config cached
- [ ] Routes cached
- [ ] Events cached
- [ ] Views cached
- [ ] Queue connections verified

---

## 🚀 Deployment Steps

### Stage 1: Static Analysis (5 min)

```bash
# Run PHPStan
vendor/bin/phpstan analyse --level=8

# Run Rector (dry run)
vendor/bin/rector process --dry-run

# Check tenant models
grep -r "DB::table" app/Models/
```

**Success Criteria**:
- ✅ PHPStan Level 8 passes
- ✅ No DB::table in tenant models
- ✅ Rector suggests no critical changes

---

### Stage 2: Tests (10 min)

```bash
# Run all tests with coverage
php artisan test --parallel --coverage --min=85

# Run domain tests
php artisan test tests/Unit/Domain --stop-on-failure

# Run integration tests
php artisan test tests/Feature --stop-on-failure

# Run security tests
php artisan test tests/Security --stop-on-failure
```

**Success Criteria**:
- ✅ All tests pass
- ✅ Coverage >85%
- ✅ No flaky tests
- ✅ Security tests pass

---

### Stage 3: Security Scan (5 min)

```bash
# Composer audit
composer audit --no-dev

# NPM audit
npm audit --production

# Check for secrets
grep -r "APP_KEY=" . --exclude-dir={vendor,node_modules}
```

**Success Criteria**:
- ✅ No critical vulnerabilities
- ✅ No secrets in code
- ✅ Dependencies up to date

---

### Stage 4: Build (5 min)

```bash
# Install dependencies
composer install --no-dev --optimize-autoloader

# Cache everything
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

# Build frontend
cd frontend-web && npm run build
```

**Success Criteria**:
- ✅ No build errors
- ✅ All caches created
- ✅ Frontend assets compiled

---

### Stage 5: Database Migration (10 min)

```bash
# Backup database
php artisan backup:run --only-db

# Check for locks
php artisan db:check-locks

# Run migrations (dry run first)
php artisan migrate --pretend

# Run migrations
php artisan migrate --force
```

**Success Criteria**:
- ✅ Backup completed
- ✅ No database locks
- ✅ Migrations successful
- ✅ No data loss

---

### Stage 6: Blue/Green Deployment (15 min)

#### 6.1 Deploy to Target Environment

```bash
# Determine target (blue or green)
CURRENT=$(readlink current | grep -o 'blue\|green')
TARGET=$([ "$CURRENT" = "blue" ] && echo "green" || echo "blue")

# Extract deployment
tar -xzf deployment.tar.gz -C $TARGET/

# Run migrations
cd $TARGET && php artisan migrate --force

# Clear caches
php artisan cache:clear
php artisan config:cache
php artisan route:cache
```

#### 6.2 Health Checks

```bash
# Redis health
curl -f https://$TARGET.absensiqr.com/api/health/redis

# Database health
curl -f https://$TARGET.absensiqr.com/api/health/database

# Queue health
curl -f https://$TARGET.absensiqr.com/api/health/queue

# Attendance test
curl -f https://$TARGET.absensiqr.com/api/health/attendance-test
```

#### 6.3 Switch Traffic

```bash
# Switch symlink
ln -sfn $TARGET current

# Reload services
sudo systemctl reload nginx
sudo systemctl reload php8.2-fpm
sudo supervisorctl restart laravel-worker:*
```

**Success Criteria**:
- ✅ Target environment healthy
- ✅ All health checks pass
- ✅ Traffic switched successfully
- ✅ No downtime

---

### Stage 7: Post-Deploy Verification (5 min)

```bash
# Check main site
curl -f https://absensiqr.com/api/health

# Check error rate
ERROR_RATE=$(curl -s https://absensiqr.com/api/metrics/error-rate | jq -r '.rate')

# Check response time
RESPONSE_TIME=$(curl -s https://absensiqr.com/api/metrics/response-time | jq -r '.avg')

# Check queue depth
QUEUE_DEPTH=$(curl -s https://absensiqr.com/api/metrics/queue-depth | jq -r '.depth')
```

**Success Criteria**:
- ✅ Site accessible
- ✅ Error rate <5%
- ✅ Response time <500ms
- ✅ Queue processing normally

---

## 📊 Monitoring Checklist (First 30 min)

### Metrics to Monitor

| Metric | Threshold | Action if Exceeded |
|--------|-----------|-------------------|
| Error Rate | <5% | Rollback |
| Response Time | <500ms | Investigate |
| CPU Usage | <70% | Scale up |
| Memory Usage | <80% | Scale up |
| Queue Depth | <1000 | Add workers |
| Deadlocks | 0 | Rollback |
| Redis Connections | <1000 | Investigate |

### Health Endpoints

```bash
# Check every 30 seconds for 5 minutes
for i in {1..10}; do
  echo "Check $i/10..."
  
  # Main health
  curl -f https://absensiqr.com/api/health
  
  # Redis
  curl -f https://absensiqr.com/api/health/redis
  
  # Database
  curl -f https://absensiqr.com/api/health/database
  
  # Queue
  curl -f https://absensiqr.com/api/health/queue
  
  sleep 30
done
```

---

## 🔄 Rollback Checklist

### When to Rollback

**Immediate Rollback** if:
- ❌ Error rate >5%
- ❌ Deadlock spike detected
- ❌ Redis connection timeout >2 min
- ❌ Database connection failures
- ❌ Critical feature broken
- ❌ Data corruption detected

**Consider Rollback** if:
- ⚠️ Response time >1000ms
- ⚠️ Queue depth >5000
- ⚠️ Memory usage >90%
- ⚠️ Customer complaints spike

### Rollback Steps

```bash
# 1. Switch back to previous environment
CURRENT=$(readlink current | grep -o 'blue\|green')
PREVIOUS=$([ "$CURRENT" = "blue" ] && echo "green" || echo "blue")

echo "Rolling back from $CURRENT to $PREVIOUS..."
ln -sfn $PREVIOUS current

# 2. Reload services
sudo systemctl reload nginx
sudo systemctl reload php8.2-fpm

# 3. Clear caches
cd $PREVIOUS
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# 4. Restart queue workers
php artisan queue:restart
sudo supervisorctl restart laravel-worker:*

# 5. Verify rollback
curl -f https://absensiqr.com/api/health

echo "✅ Rollback complete"
```

**Rollback Time**: <2 minutes

---

## 📝 Post-Deployment Tasks

### Immediate (Within 1 hour)

- [ ] Verify all critical features working
- [ ] Check error logs for anomalies
- [ ] Monitor Horizon dashboard
- [ ] Verify queue processing
- [ ] Check slow query log
- [ ] Update deployment log

### Within 24 hours

- [ ] Review error rate trends
- [ ] Check performance metrics
- [ ] Verify backup completion
- [ ] Update documentation
- [ ] Clean up old releases
- [ ] Send deployment report

### Within 1 week

- [ ] Review incident reports
- [ ] Update runbooks
- [ ] Optimize slow queries
- [ ] Review monitoring alerts
- [ ] Plan next deployment

---

## 🚨 Emergency Contacts

| Role | Contact | Availability |
|------|---------|--------------|
| DevOps Lead | [Phone] | 24/7 |
| Backend Lead | [Phone] | 24/7 |
| Database Admin | [Phone] | On-call |
| Security Team | [Email] | Business hours |

---

## 📊 Deployment Log Template

```markdown
## Deployment: [Date] [Time]

**Deployed by**: [Name]
**Branch**: main
**Commit**: [SHA]
**Environment**: Production

### Pre-Deployment
- [ ] All checks passed
- [ ] Backup completed
- [ ] Team notified

### Deployment
- Start time: [Time]
- End time: [Time]
- Duration: [Minutes]
- Downtime: [Seconds]

### Post-Deployment
- [ ] Health checks passed
- [ ] Monitoring verified
- [ ] No errors detected

### Metrics (30 min)
- Error rate: [%]
- Response time: [ms]
- Queue depth: [count]
- CPU usage: [%]
- Memory usage: [%]

### Issues
- None / [List issues]

### Rollback
- Required: Yes/No
- Reason: [If applicable]
- Time: [If applicable]
```

---

## ✅ Sign-off

**Deployment approved by**:

- [ ] DevOps Lead: _________________ Date: _______
- [ ] Backend Lead: ________________ Date: _______
- [ ] Product Owner: _______________ Date: _______

**Post-deployment verified by**:

- [ ] DevOps Lead: _________________ Date: _______
- [ ] QA Lead: ____________________ Date: _______

---

**Status**: ✅ Ready for Production  
**Last Updated**: 2026-02-10  
**Version**: 1.0
