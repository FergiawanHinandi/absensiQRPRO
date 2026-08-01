# Final Deployment Checklist - AbsensiQRPro

## Overview
Checklist ini harus di-check dan di-verify SEBELUM deployment ke production. Setiap item harus ditandai dengan ✅ sebelum melanjutkan ke item berikutnya.

---

## Pre-Deployment (1-2 Hari Sebelum)

### Infrastructure
- [ ] Domain terdaftar dan DNS terkonfigurasi
- [ ] Server production siap (VPS/Cloud)
- [ ] SSL certificate terpasang (auto-renew)
- [ ] PostgreSQL terinstall dan dikonfigurasi
- [ ] Redis dengan Sentinel terkonfigurasi
- [ ] Supervisor terinstall
- [ ] Nginx terkonfigurasi
- [ ] Firewall dikonfigurasi (UFW/iptables)
- [ ] SSH key-based authentication aktif

### Application
- [ ] .env.production sudah dibuat dari template
- [ ] APP_KEY digenerate di production
- [ ] APP_DEBUG=false
- [ ] APP_URL sesuai domain production
- [ ] SESSION_SECURE_COOKIE=true
- [ ] SESSION_ENCRYPT=true
- [ ] DB_SSLMODE=require
- [ ] Semua API keys diisi (Midtrans, FCM, Sentry)
- [ ] CORS allowed_origins dikonfigurasi
- [ ] Sanctum stateful domains dikonfigurasi

### Database
- [ ] Database production dibuat
- [ ] Database user dengan privileges minimal
- [ ] Migrations siap di-run
- [ ] Seeders siap di-run
- [ ] Backup schedule dikonfigurasi
- [ ] Backup restoration teruji

### Mobile
- [ ] Production keystore dibuat
- [ ] Keystore.properties dikonfigurasi
- [ ] Proguard diaktifkan
- [ ] Release build siap
- [ ] .env.production untuk mobile siap
- [ ] SSL pinning aktif

### Security
- [ ] Penetration testing selesai
- [ ] Security scan bersih (OWASP ZAP)
- [ ] Semua critical vulnerabilities fixed
- [ ] Security headers aktif
- [ ] Rate limiting aktif
- [ ] Audit trail aktif

### Monitoring
- [ ] Prometheus metrics exposed
- [ ] Grafana dashboard aktif
- [ ] Alerting dikonfigurasi (Telegram/Slack)
- [ ] Sentry DSN aktif
- [ ] Health check endpoint berfungsi
- [ ] On-call schedule ditentukan

### Backup & DR
- [ ] Daily backup scheduled
- [ ] Incremental backup configured
- [ ] Backup restoration tested
- [ ] DR runbook siap
- [ ] Rollback procedure teruji

### Documentation
- [ ] Deployment docs updated
- [ ] User manual updated
- [ ] API documentation updated
- [ ] DR runbook reviewed

---

## Deployment Day

### Morning (H-4)
- [ ] Tim deployment berkumpul
- [ ] Final briefing dan task assignment
- [ ] Backup current production (if exists)
- [ ] Notify stakeholders about maintenance window

### Deployment (H-0)
- [ ] Put application in maintenance mode
- [ ] Pull latest code
- [ ] Install dependencies
- [ ] Run migrations
- [ ] Run seeders (if needed)
- [ ] Cache configuration
- [ ] Deploy frontend
- [ ] Restart queue workers
- [ ] Restart Horizon
- [ ] Take application out of maintenance mode

### Post-Deployment (H+1)
- [ ] Health check passed
- [ ] Login test successful (all roles)
- [ ] QR scan test successful
- [ ] Dashboard load test passed
- [ ] API response time < 500ms
- [ ] No errors in application logs
- [ ] No errors in server logs
- [ ] Monitoring dashboards green
- [ ] Alerts not firing

### Final Verification (H+2)
- [ ] All critical paths tested
- [ ] Mobile app connects successfully
- [ ] Payment gateway test (if applicable)
- [ ] Push notifications working
- [ ] Email notifications working
- [ ] Backup running successfully
- [ ] Performance acceptable under load

---

## Post-Deployment (1-2 Hari Setelah)

### Monitoring
- [ ] Monitor application metrics
- [ ] Monitor server resources
- [ ] Check error rates
- [ ] Review security logs
- [ ] Check backup completion

### User Feedback
- [ ] Collect user feedback
- [ ] Address any critical issues
- [ ] Document any lessons learned

### Documentation
- [ ] Update deployment documentation
- [ ] Document any configuration changes
- [ ] Update runbooks if needed

---

## Rollback Criteria

**Trigger rollback if:**
- [ ] Health check fails for > 5 minutes
- [ ] Error rate > 5%
- [ ] Response time > 2 seconds (p95)
- [ ] Critical security vulnerability found
- [ ] Data corruption detected
- [ ] More than 10% of users affected

**Rollback procedure:**
1. Put application in maintenance mode
2. Reset to last known good commit
3. Reinstall dependencies
4. Rollback migrations (if needed)
5. Clear caches
6. Restart services
7. Take application out of maintenance mode
8. Verify health check
9. Notify stakeholders

---

## Emergency Contacts

| Role | Name | Phone | Email |
|------|------|-------|-------|
| Deployment Lead | [NAME] | [PHONE] | [EMAIL] |
| Backend Dev | [NAME] | [PHONE] | [EMAIL] |
| Frontend Dev | [NAME] | [PHONE] | [EMAIL] |
| DevOps | [NAME] | [PHONE] | [EMAIL] |
| Database Admin | [NAME] | [PHONE] | [EMAIL] |

---

## Sign-off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Project Manager | | | |
| Tech Lead | | | |
| Security Lead | | | |
| QA Lead | | | |

**Deployment Status:** [ ] GO [ ] NO-GO [ ] GO WITH CONDITIONS

**Notes:** _________________________________________________
