# QR Security Validation - Quick Reference

## Security Flow

```
┌─────────────┐     ┌──────────────┐     ┌─────────────────┐     ┌──────────────┐
│  Scan QR    │ ──▶ │ HMAC Verify  │ ──▶ │ Status Validate │ ──▶ │ Record       │
│  Token      │     │  (Layer 1)   │     │   (Layer 2)     │     │ Attendance   │
└─────────────┘     └──────────────┘     └─────────────────┘     └──────────────┘
                            │                      │
                            ▼                      ▼
                     ❌ Invalid            ❌ Inactive/Invalid
                      Signature             📝 Log Anomaly
                                           📊 Create Audit
```

## Key Methods

### AttendanceService::recordAttendance()
```php
// Line 42-58: Two-layer validation
$payload = $this->qrService->verify($qrToken);              // Layer 1: HMAC
$student = $this->qrService->validateStudentStatus(         // Layer 2: Status
    $payload, 
    $teacher->school_id
);
```

### StudentQrService::validateStudentStatus()
```php
public function validateStudentStatus(array $payload, int $expectedSchoolId): User
{
    // 4 critical checks in order:
    // 1. Student exists ✓
    // 2. is_active = true ✓
    // 3. school_id matches QR ✓
    // 4. school_id matches expected ✓
}
```

## Error Messages

| Condition | Error Message | Severity |
|-----------|---------------|----------|
| Student not found | "Siswa tidak ditemukan. QR Card mungkin tidak valid." | HIGH |
| Inactive account | "Akun siswa tidak aktif. Hubungi administrator." | MEDIUM |
| School mismatch in QR | "QR Card tidak sesuai dengan data sekolah siswa." | HIGH |
| Cross-school attempt | "QR Card dari sekolah lain." | CRITICAL |

## Monitoring Commands

### View Security Logs
```bash
# Today's anomalies
tail -f storage/logs/security-$(date +%Y-%m-%d).log

# Search for CRITICAL events
grep "CRITICAL" storage/logs/security-*.log

# Count anomalies by type
grep "QR Security Anomaly" storage/logs/security-*.log | cut -d: -f3 | sort | uniq -c
```

### Database Queries
```sql
-- Last 24 hours anomalies
SELECT * FROM audit_logs 
WHERE action = 'qr_security_anomaly'
AND created_at > NOW() - INTERVAL 24 HOUR
ORDER BY created_at DESC;

-- Repeat offenders
SELECT user_id, COUNT(*) as attempts
FROM audit_logs
WHERE action = 'qr_security_anomaly'
GROUP BY user_id
HAVING attempts > 3
ORDER BY attempts DESC;

-- Cross-school attempts
SELECT * FROM audit_logs
WHERE action = 'qr_security_anomaly'
AND description LIKE '%CRITICAL%'
ORDER BY created_at DESC;
```

## Test Commands

```bash
# Run QR security tests only
php artisan test tests/Feature/Security/QrStudentStatusValidationTest.php

# Run with human-readable output
php artisan test tests/Feature/Security/QrStudentStatusValidationTest.php --testdox

# Run specific test
php artisan test --filter=inactive_student_with_valid_hmac_fails_validation

# Run all security tests
php artisan test tests/Feature/Security/
```

## Common Scenarios

### Scenario 1: Student Account Suspended
```
1. Admin sets is_active = false
2. Student scans QR with valid HMAC
3. Validation fails at Check #2
4. Error: "Akun siswa tidak aktif"
5. Log: MEDIUM severity anomaly
6. Audit: Record created
```

### Scenario 2: Student Transfers Schools
```
1. Student moves from School A to School B
2. Still has old QR code for School A
3. Tries to scan at School B
4. HMAC verifies (still valid signature)
5. Validation fails at Check #4
6. Error: "QR Card dari sekolah lain"
7. Log: CRITICAL severity anomaly
```

### Scenario 3: Deleted Student (Manual QR)
```
1. Attacker creates valid HMAC for deleted student
2. Student record doesn't exist in DB
3. Validation fails at Check #1
4. Error: "Siswa tidak ditemukan"
5. Log: HIGH severity anomaly
6. Audit: Record with student_id
```

## Integration Points

### Frontend Error Handling
```javascript
try {
    const response = await api.post('/v1/attendance/scan', {
        qr_token: scannedToken,
        request_id: uuid()
    });
} catch (error) {
    if (error.response?.data?.message?.includes('tidak aktif')) {
        // Show "Account Inactive" message
    } else if (error.response?.data?.message?.includes('sekolah lain')) {
        // Show "Wrong School" warning
    } else {
        // Generic error
    }
}
```

### Mobile App (React Native)
```typescript
// AbsensiQRMobile/src/api/attendance.ts
const response = await attendanceApi.scan(qrToken, requestId);

if (!response.success) {
    if (response.message.includes('tidak aktif')) {
        Alert.alert('Akun Tidak Aktif', 'Hubungi administrator');
    }
}
```

## Troubleshooting

### Issue: All scans failing with "tidak ditemukan"
**Cause**: QR_SECRET_KEY mismatch  
**Solution**: Verify .env has correct QR_SECRET_KEY

### Issue: Logs not appearing
**Cause**: Security channel not configured  
**Solution**: Check config/logging.php has 'security' channel

### Issue: False positives for school mismatch
**Cause**: Student school_id not updated after transfer  
**Solution**: Ensure school_id updated when student transfers

### Issue: Performance degradation
**Cause**: Multiple validation queries  
**Solution**: Ensure User table has index on (id, role_type)

## Performance Notes

- **Additional latency**: ~30-50ms per scan
- **Database impact**: 1 additional SELECT query
- **Log volume**: ~1KB per anomaly
- **Recommended index**: 
  ```sql
  CREATE INDEX idx_users_role_active ON users(id, role_type, is_active);
  ```

## Security Considerations

### ✅ Protected Against
- Inactive account abuse
- Cross-school QR usage
- Deleted student access
- Stale credential attacks

### ⚠️ Not Protected Against
- Valid QR stolen from active student
- Man-in-the-middle attacks (use HTTPS)
- Replay attacks within same day (use request_id)
- Physical QR card theft (requires additional MFA)

### 🔐 Best Practices
1. Rotate QR_SECRET_KEY annually
2. Monitor CRITICAL anomalies daily
3. Auto-disable accounts with >5 failed scans
4. Implement IP rate limiting
5. Add device fingerprinting for mobile apps

## Related Documentation

- [QR_SECURITY_ENHANCEMENT.md](QR_SECURITY_ENHANCEMENT.md) - Full implementation details
- [backend/docs/09_security_improvements.md](09_security_improvements.md) - Overall security architecture
- [backend/docs/10_FINAL_DECISIONS.md](10_FINAL_DECISIONS.md) - QR design decisions
- [backend/docs/12_AUDIT_FINDINGS.md](12_AUDIT_FINDINGS.md) - Security audit results

---

**Last Updated**: January 2026  
**Maintainer**: Security Team  
**Priority**: CRITICAL
