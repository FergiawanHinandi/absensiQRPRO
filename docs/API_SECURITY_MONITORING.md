# Security Monitoring Dashboard API Documentation

## Overview

Security Monitoring Dashboard provides fraud detection and abuse monitoring for the school attendance system.

**Base URL**: `/api/v1/admin/security`

**Authorization**: Requires `school_admin` or `super_admin` role

---

## API Endpoints

### 1. Get Security Events

**Endpoint**: `GET /api/v1/admin/security/events`

**Description**: Retrieve security events with filtering options

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| start_date | date | No | Filter events from this date (YYYY-MM-DD) |
| end_date | date | No | Filter events until this date (YYYY-MM-DD) |
| severity | string | No | Filter by severity: `low`, `medium`, `high`, `critical` |
| event_type | string | No | Filter by event type |
| page | integer | No | Page number (default: 1) |
| per_page | integer | No | Items per page (default: 20, max: 100) |

**Response**:
```json
{
  "success": true,
  "data": {
    "events": [
      {
        "id": 1,
        "school_id": 1,
        "user_id": 10,
        "student_id": 50,
        "event_type": "invalid_qr_attempt",
        "severity": "medium",
        "ip_address": "192.168.1.100",
        "device_id": "device_abc123",
        "user_agent": "Mozilla/5.0...",
        "details": {
          "qr_nonce": "abc123",
          "reason": "QR code expired"
        },
        "reviewed": false,
        "created_at": "2026-02-02T07:30:00Z",
        "user": {
          "id": 10,
          "name": "Ahmad Rizki",
          "username": "ahmad_rizki"
        },
        "student": {
          "id": 50,
          "name": "Ahmad Rizki",
          "class": {
            "name": "XII IPA 1"
          }
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 150,
      "last_page": 8
    }
  }
}
```

**Event Types**:
- `invalid_qr_attempt` - Invalid QR code scan attempt
- `expired_qr_scan` - Expired QR code scanned
- `location_mismatch` - GPS location outside school radius
- `rate_limit_violation` - Too many scan attempts
- `duplicate_face_detection` - Duplicate face photo detected
- `qr_sharing_suspected` - QR code sharing suspected
- `unauthorized_access` - Unauthorized access attempt
- `payload_tampering` - QR payload tampering detected
- `brute_force_attempt` - Brute force login attempt
- `idor_attempt` - IDOR attack attempt
- `export_abuse` - Mass data export abuse

---

### 2. Get Daily Security Summary

**Endpoint**: `GET /api/v1/admin/security/summary`

**Description**: Get summary of security events for a specific date

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| date | date | No | Date for summary (default: today) |

**Response**:
```json
{
  "success": true,
  "data": {
    "invalid_qr_attempts": 15,
    "expired_qr_scans": 8,
    "location_mismatch": 5,
    "rate_limit_violations": 3,
    "duplicate_face_detections": 2,
    "total_events_today": 33,
    "high_severity_alerts": 7,
    "flagged_students_count": 4,
    "suspicious_devices_count": 2
  }
}
```

---

### 3. Get Suspicious Students

**Endpoint**: `GET /api/v1/admin/security/suspicious-students`

**Description**: Get list of students flagged for suspicious behavior

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| status | string | No | Filter by status: `flagged`, `under_review`, `cleared`, `confirmed` |
| page | integer | No | Page number |
| per_page | integer | No | Items per page |

**Response**:
```json
{
  "success": true,
  "data": {
    "suspicious_students": [
      {
        "id": 1,
        "school_id": 1,
        "student_id": 50,
        "flag_reason": "multiple_invalid_scans",
        "violation_count": 5,
        "evidence": [
          {
            "reason": "multiple_invalid_scans",
            "count": 5,
            "detected_at": "2026-02-02T07:00:00"
          }
        ],
        "status": "flagged",
        "flagged_at": "2026-02-02T07:00:00Z",
        "student": {
          "id": 50,
          "name": "Ahmad Rizki",
          "username": "ahmad_rizki",
          "class": {
            "name": "XII IPA 1"
          }
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 10,
      "last_page": 1
    }
  }
}
```

**Flag Reasons**:
- `multiple_invalid_scans` - More than 3 invalid scans in a week
- `multiple_devices` - Used more than 3 different devices
- `location_mismatch_pattern` - Repeated location violations
- `qr_sharing_suspected` - Suspected QR code sharing
- `automated_behavior` - Automated/bot-like behavior detected

---

### 4. Get Suspicious Devices

**Endpoint**: `GET /api/v1/admin/security/suspicious-devices`

**Description**: Get list of devices flagged for suspicious activity

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| risk_level | string | No | Filter by risk: `low`, `medium`, `high`, `critical` |
| page | integer | No | Page number |
| per_page | integer | No | Items per page |

**Response**:
```json
{
  "success": true,
  "data": {
    "suspicious_devices": [
      {
        "id": 1,
        "device_id": "device_abc123",
        "unique_students_count": 5,
        "scan_attempt_count": 50,
        "failed_attempt_count": 20,
        "risk_level": "high",
        "blocked": false,
        "student_names": [
          "Ahmad Rizki",
          "Budi Santoso",
          "Citra Dewi",
          "Dian Pratama",
          "Eko Wijaya"
        ],
        "ip_addresses": [
          "192.168.1.100",
          "192.168.1.101"
        ],
        "first_seen_at": "2026-01-15T08:00:00Z",
        "last_seen_at": "2026-02-02T07:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 5,
      "last_page": 1
    }
  }
}
```

**Risk Levels**:
- `low` - 1 student, normal behavior
- `medium` - 2 students using same device
- `high` - 3-4 students using same device
- `critical` - 5+ students using same device

---

### 5. Get Top Flagged Students

**Endpoint**: `GET /api/v1/admin/security/top-flagged-students`

**Description**: Get top flagged students for dashboard display

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| limit | integer | No | Number of students to return (default: 10) |

**Response**:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "student_id": 50,
      "flag_reason": "multiple_invalid_scans",
      "violation_count": 8,
      "status": "flagged",
      "student": {
        "name": "Ahmad Rizki",
        "class": {
          "name": "XII IPA 1"
        }
      }
    }
  ]
}
```

---

### 6. Review Suspicious Student

**Endpoint**: `POST /api/v1/admin/security/suspicious-students/{id}/review`

**Description**: Update review status of a suspicious student

**Request Body**:
```json
{
  "status": "cleared",
  "notes": "False positive - student had legitimate issues with GPS"
}
```

**Parameters**:
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| status | string | Yes | New status: `under_review`, `cleared`, `confirmed` |
| notes | string | No | Review notes (max 1000 chars) |

**Response**:
```json
{
  "success": true,
  "message": "Status siswa berhasil diupdate",
  "data": {
    "id": 1,
    "status": "cleared",
    "reviewed_by": 5,
    "reviewed_at": "2026-02-02T08:00:00Z",
    "review_notes": "False positive - student had legitimate issues with GPS"
  }
}
```

---

### 7. Block/Unblock Device

**Endpoint**: `POST /api/v1/admin/security/suspicious-devices/{id}/block`

**Description**: Block or unblock a suspicious device

**Request Body**:
```json
{
  "blocked": true
}
```

**Parameters**:
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| blocked | boolean | Yes | `true` to block, `false` to unblock |

**Response**:
```json
{
  "success": true,
  "message": "Perangkat berhasil diblokir",
  "data": {
    "id": 1,
    "device_id": "device_abc123",
    "blocked": true,
    "blocked_at": "2026-02-02T08:00:00Z"
  }
}
```

---

## Auto-Flagging Rules

### Student Flagging Thresholds

Students are automatically flagged when they exceed these thresholds:

1. **Multiple Invalid Scans**
   - Threshold: >3 invalid scan attempts in 7 days
   - Flag Reason: `multiple_invalid_scans`

2. **Multiple Devices**
   - Threshold: >3 different devices used in 7 days
   - Flag Reason: `multiple_devices`

3. **Location Mismatch Pattern**
   - Threshold: >2 location violations in 7 days
   - Flag Reason: `location_mismatch_pattern`

### Device Risk Level Calculation

Devices are automatically assigned risk levels based on:

1. **Student Count**
   - 1 student: `low`
   - 2 students: `medium`
   - 3-4 students: `high`
   - 5+ students: `critical`

2. **Failure Rate**
   - >50% failed scans (min 10 attempts): Escalate risk level

---

## Logging

All security dashboard access is logged with:
- User ID and name
- Role
- School ID
- IP address
- Timestamp

Example log entry:
```
[2026-02-02 08:00:00] INFO: Security dashboard accessed
{
  "user_id": 5,
  "user_name": "Admin Sekolah",
  "role": "school_admin",
  "school_id": 1,
  "ip_address": "192.168.1.50"
}
```

---

## Error Responses

### 401 Unauthorized
```json
{
  "success": false,
  "message": "Unauthenticated"
}
```

### 403 Forbidden
```json
{
  "success": false,
  "message": "Akses ditolak"
}
```

### 404 Not Found
```json
{
  "success": false,
  "message": "Resource tidak ditemukan"
}
```

### 422 Validation Error
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "status": ["The status field is required"],
    "date": ["The date must be a valid date"]
  }
}
```

---

## Usage Examples

### Get Today's Security Summary
```bash
curl -X GET "https://api.school.com/api/v1/admin/security/summary" \
  -H "Authorization: Bearer {token}"
```

### Get High Severity Events
```bash
curl -X GET "https://api.school.com/api/v1/admin/security/events?severity=high" \
  -H "Authorization: Bearer {token}"
```

### Review Flagged Student
```bash
curl -X POST "https://api.school.com/api/v1/admin/security/suspicious-students/1/review" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "cleared",
    "notes": "Verified with student - legitimate GPS issues"
  }'
```

### Block Suspicious Device
```bash
curl -X POST "https://api.school.com/api/v1/admin/security/suspicious-devices/1/block" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "blocked": true
  }'
```

---

**Last Updated**: February 2, 2026  
**API Version**: 1.0.0
