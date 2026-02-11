# Quick Reference: Replay Attack Prevention

## 🚀 Quick Start

### 1. Jalankan Migration
```bash
cd backend
php artisan migrate
```

### 2. Test Endpoint
```bash
curl -X POST http://localhost:8000/api/v1/attendance/scan \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"qr_payload": "test", "lat": -6.2088, "lng": 106.8456}'
```

---

## 📋 Headers yang Diperlukan

| Header | Required | Format | Contoh |
|--------|----------|--------|--------|
| `Authorization` | ✅ Yes | Bearer token | `Bearer eyJ0eXAiOiJKV1...` |
| `X-Idempotency-Key` | ⚠️ Recommended | UUID v4 | `550e8400-e29b-41d4-a716-446655440000` |
| `X-Device-ID` | ⚠️ Recommended | String | `device-12345` |
| `Content-Type` | ✅ Yes | application/json | `application/json` |

---

## 🔐 Middleware Stack

### Student Scan
```php
'role:student'
'attendance.security'
'idempotency:60'              // 60 menit TTL
'attendance.rate.limit:qr-scan' // 10 req/min
```

### Teacher Scan
```php
'role:teacher,homeroom_teacher'
'attendance.security'
'idempotency:60'
'attendance.rate.limit:qr-scan'
```

### Manual Entry
```php
'idempotency:30'              // 30 menit TTL
'attendance.rate.limit:manual-entry' // 30 req/min
```

---

## 📊 Rate Limits

| Endpoint Type | Limit | Window |
|---------------|-------|--------|
| QR Scan | 10 requests | per minute |
| Manual Entry | 30 requests | per minute |
| Report Export | 5 requests | per minute |
| Generate QR | 20 requests | per minute |

---

## 🔄 Response Codes

| Code | Status | Meaning |
|------|--------|---------|
| 200 | OK | Request berhasil |
| 201 | Created | Attendance berhasil dicatat (first time) |
| 400 | Bad Request | Invalid idempotency key format |
| 401 | Unauthorized | Token tidak valid |
| 429 | Too Many Requests | Rate limit exceeded |

---

## 💾 Idempotency Key Behavior

### First Request
```json
{
  "success": true,
  "message": "Absensi berhasil dicatat.",
  "data": { ... }
}
```
**HTTP Status:** 201 Created

### Retry (Same Key)
```json
{
  "success": true,
  "message": "Absensi berhasil dicatat.",
  "data": { ... },
  "_idempotent_replay": true,
  "_original_timestamp": "2026-02-07T13:41:17+08:00"
}
```
**HTTP Status:** 200 OK  
**Header:** `X-Idempotent-Replay: true`

---

## 🧹 Maintenance Commands

### Cleanup Expired Keys
```bash
# Manual cleanup
php artisan idempotency:cleanup

# Force (no confirmation)
php artisan idempotency:cleanup --force

# Custom retention
php artisan idempotency:cleanup --days=7
```

### Schedule (add to Kernel.php)
```php
$schedule->command('idempotency:cleanup --force')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
```

---

## 🧪 Testing

### Run PHPUnit Tests
```bash
php artisan test --filter ReplayAttackPreventionTest
```

### Run Bash Test Script
```bash
chmod +x tests/security/test_replay_prevention.sh
./tests/security/test_replay_prevention.sh
```

---

## 📈 Monitoring Queries

### Active Keys
```sql
SELECT COUNT(*) FROM idempotency_keys WHERE expires_at > NOW();
```

### Top Users
```sql
SELECT user_id, COUNT(*) as total
FROM idempotency_keys
WHERE expires_at > NOW()
GROUP BY user_id
ORDER BY total DESC
LIMIT 10;
```

### Cleanup Stats
```sql
SELECT 
    DATE(created_at) as date,
    COUNT(*) as total_keys,
    COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired
FROM idempotency_keys
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 7;
```

---

## 🔍 Debug Checklist

- [ ] Migration sudah dijalankan?
- [ ] Middleware terdaftar di bootstrap/app.php?
- [ ] Routes menggunakan middleware yang benar?
- [ ] Client mengirim X-Idempotency-Key header?
- [ ] Key format UUID v4 yang valid?
- [ ] Database connection OK?
- [ ] Cleanup job scheduled?

---

## 📞 Common Issues

### "Request without idempotency key"
➡️ Add `X-Idempotency-Key` header

### "Invalid idempotency key format"
➡️ Use UUID v4 format

### "Rate limit exceeded"
➡️ Wait for retry_after_seconds

### Keys tidak ter-cleanup
➡️ Check scheduled job running

---

## 🎯 Best Practices

1. **Always send idempotency key** untuk request yang penting
2. **Generate UUID client-side** sebelum request
3. **Simpan key untuk retry** jika network error
4. **Monitor rate limit headers** untuk adaptive throttling
5. **Schedule cleanup job** untuk prevent table bloat

---

**Last Updated:** 2026-02-07  
**Version:** 1.0.0
