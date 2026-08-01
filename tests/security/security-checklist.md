# Security Checklist - AbsensiQRPro

## Overview
Checklist keamanan untuk deployment production. Setiap item harus diuji dan diverifikasi sebelum go-live.

---

## 1. Authentication & Authorization

### 1.1 Login Security
- [ ] Rate limiting aktif (5 attempts per minute)
- [ ] Account lockout setelah 5x failed login
- [ ] Brute force protection bekerja
- [ ] Login attempts logged
- [ ] Password minimum 8 characters enforced
- [ ] Password complexity requirements (optional)

### 1.2 Token Security
- [ ] JWT token expiry: 30 menit
- [ ] Refresh token rotation aktif
- [ ] Token revocation bekerja
- [ ] Multi-session management aktif
- [ ] Token binding validation aktif

### 1.3 Role-Based Access Control
- [ ] Super Admin tidak bisa diakses oleh role lain
- [ ] School Admin hanya bisa akses sekolah sendiri
- [ ] Teacher hanya bisa akses kelas sendiri
- [ ] Student tidak bisa akses endpoint admin
- [ ] Parent hanya bisa lihat anak sendiri

---

## 2. QR Code Security

### 2.1 QR Generation
- [ ] QR code dinamis (per session)
- [ ] HMAC-SHA256 signature aktif
- [ ] Signature expiration: 10 detik
- [ ] QR code school-scoped

### 2.2 QR Validation
- [ ] Anti-replay protection (SETNX) bekerja
- [ ] Timing-safe comparison (hash_equals) digunakan
- [ ] 2-layer validation (HMAC + Database)
- [ ] One-time use enforcement
- [ ] QR expiry check aktif

### 2.3 QR Card Security
- [ ] Student card QR berbeda dari session QR
- [ ] Card expiry enforcement
- [ ] Card deactivation bekerja

---

## 3. API Security

### 3.1 Input Validation
- [ ] SQL injection prevention (Eloquent ORM)
- [ ] XSS prevention (CSP + input sanitization)
- [ ] CSRF protection (Sanctum tokens)
- [ ] File upload validation (Excel import)
- [ ] Request size limits enforced

### 3.2 Rate Limiting
- [ ] Global rate limiting aktif
- [ ] Per-school rate limiting aktif
- [ ] Login rate limiting: 5/min
- [ ] QR scan rate limiting: 30/min
- [ ] Export rate limiting: 10/hour

### 3.3 HTTP Security Headers
- [ ] X-Frame-Options: DENY
- [ ] X-Content-Type-Options: nosniff
- [ ] X-XSS-Protection: 1; mode=block
- [ ] Strict-Transport-Security: max-age=31536000
- [ ] Content-Security-Policy aktif
- [ ] Referrer-Policy: no-referrer
- [ ] Permissions-Policy aktif

---

## 4. Data Protection

### 4.1 Encryption
- [ ] Database connection SSL (DB_SSLMODE=require)
- [ ] Redis password configured
- [ ] Session encryption aktif (SESSION_ENCRYPT=true)
- [ ] Secure cookie (SESSION_SECURE_COOKIE=true)
- [ ] Backup encryption (AES-256)

### 4.2 Sensitive Data
- [ ] APP_KEY generated (bukan default)
- [ ] JWT_SECRET generated
- [ ] API keys di environment (bukan di code)
- [ ] Password hashed dengan BCrypt (12 rounds)
- [ ] Sensitive data tidak di-log

---

## 5. Infrastructure Security

### 5.1 Server Security
- [ ] SSH key-based authentication
- [ ] Root login disabled
- [ ] Firewall configured (UFW/iptables)
- [ ] Unnecessary ports closed
- [ ] Automatic security updates enabled

### 5.2 Database Security
- [ ] Database user has minimal privileges
- [ ] Database not exposed to public
- [ ] Strong database password
- [ ] Regular database backups
- [ ] Backup restoration tested

### 5.3 Redis Security
- [ ] Redis password configured
- [ ] Redis not exposed to public
- [ ] Redis TLS enabled (optional)

---

## 6. Mobile App Security

### 6.1 App Security
- [ ] Root/jailbreak detection aktif
- [ ] SSL pinning aktif
- [ ] Encrypted storage aktif
- [ ] Debug mode disabled in release
- [ ] Proguard enabled in release

### 6.2 API Communication
- [ ] HTTPS enforced
- [ ] Certificate pinning active
- [ ] API keys secured
- [ ] No sensitive data in logs

---

## 7. Monitoring & Logging

### 7.1 Security Monitoring
- [ ] Audit trail aktif
- [ ] Security logs immutable
- [ ] Failed login attempts logged
- [ ] Anomaly detection aktif
- [ ] Alert system configured

### 7.2 Error Handling
- [ ] Debug mode disabled
- [ ] Error messages not exposing sensitive data
- [ ] Exception logging active
- [ ] Sentry integration (optional)

---

## 8. Backup & Recovery

### 8.1 Backup Security
- [ ] Backups encrypted
- [ ] Backup storage secured
- [ ] Backup access restricted
- [ ] Regular backup testing

### 8.2 Recovery Procedures
- [ ] DR runbook documented
- [ ] Rollback procedure tested
- [ ] Recovery time documented
- [ ] Recovery point documented

---

## 9. Compliance

### 9.1 Data Privacy (UU PDP)
- [ ] Data collection consent
- [ ] Data retention policy
- [ ] Data deletion capability
- [ ] Privacy policy documented

### 9.2 Audit
- [ ] All actions logged
- [ ] Logs tamper-proof
- [ ] Audit trail available
- [ ] Compliance reporting

---

## 10. Pre-Deployment Verification

### 10.1 Final Checks
- [ ] All security tests passed
- [ ] Penetration testing completed
- [ ] Security scan clean (OWASP ZAP)
- [ ] No critical vulnerabilities
- [ ] All high vulnerabilities addressed

### 10.2 Documentation
- [ ] Security architecture documented
- [ ] Incident response plan documented
- [ ] Access control matrix documented
- [ ] Security contact information available

---

## Sign-off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Security Lead | | | |
| Tech Lead | | | |
| DevOps | | | |
| Project Manager | | | |

**Status:** [ ] PASS [ ] FAIL

**Notes:** _________________________________________________
