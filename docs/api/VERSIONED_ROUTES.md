# API Versioned Route Structure

## Overview

The API routes have been refactored into a versioned structure for better maintainability, scalability, and backward compatibility.

---

## Folder Structure

```
routes/
├── api.php                    # Legacy routes (backward compatible)
├── api/
│   ├── v1.php                 # V1 route aggregator
│   ├── v1/
│   │   ├── auth.php           # Authentication routes
│   │   ├── attendance.php     # Attendance management
│   │   ├── teacher.php        # Teacher-specific routes
│   │   ├── admin.php          # School admin routes
│   │   └── ...                # Additional domain routes
│   └── v2.php                 # V2 placeholder (future)
└── web.php                    # Web routes
```

---

## URL Structure

### V1 API Endpoints

All v1 routes are prefixed with `/api/v1/`:

| Endpoint | Description |
|----------|-------------|
| `POST /api/v1/auth/login` | User login |
| `GET /api/v1/auth/me` | Current user info |
| `POST /api/v1/attendance/scan` | Student QR scan |
| `GET /api/v1/attendance/history` | Attendance history |
| `GET /api/v1/teacher/dashboard` | Teacher dashboard |
| `GET /api/v1/admin/students` | List students |

### Legacy Routes

For backward compatibility, the original routes in `routes/api.php` are still loaded at `/api/v1/`:

```
Legacy: GET /api/v1/auth/me
New:    GET /api/v1/auth/me
```

---

## Route Files

### 1. `routes/api/v1/auth.php`

Authentication routes:

```php
// Public
POST /api/v1/auth/login          # User login

// Protected
GET  /api/v1/auth/me             # Current user info
POST /api/v1/auth/logout         # Logout
POST /api/v1/auth/refresh        # Refresh token
GET  /api/v1/auth/sessions       # List active sessions
DELETE /api/v1/auth/sessions/{id}  # Revoke session
POST /api/v1/auth/sessions/revoke-others  # Revoke all other sessions
```

### 2. `routes/api/v1/attendance.php`

Attendance management routes:

```php
// Student Routes
POST /api/v1/attendance/scan           # Scan QR code
GET  /api/v1/attendance/history        # View attendance history
GET  /api/v1/attendance/today          # Today's status

// Teacher Routes
POST /api/v1/attendance/manual         # Manual attendance input
POST /api/v1/attendance/bulk           # Bulk attendance input
GET  /api/v1/attendance/schedule/{id}  # Class attendance by schedule
PUT  /api/v1/attendance/{id}           # Update attendance

// Secure Routes (HMAC signed)
POST /api/v1/attendance/secure/scan           # Signed scan
POST /api/v1/attendance/secure/scan-encoded   # Encoded signed scan
POST /api/v1/attendance/secure/generate-qr    # Generate signed QR

// Reports (Admin)
GET  /api/v1/attendance/reports/daily    # Daily report
GET  /api/v1/attendance/reports/monthly  # Monthly summary
GET  /api/v1/attendance/reports/export   # Export data

// CRUD (Admin)
GET    /api/v1/attendance              # List all
GET    /api/v1/attendance/{id}         # View single
DELETE /api/v1/attendance/{id}         # Delete
```

### 3. `routes/api/v1/teacher.php`

Teacher-specific routes:

```php
// Dashboard
GET /api/v1/teacher/dashboard         # Dashboard stats
GET /api/v1/teacher/homeroom/summary  # Homeroom summary
GET /api/v1/teacher/my-students       # Assigned students

// Profile
GET /api/v1/teacher/profile           # Teacher profile

// Schedules
GET /api/v1/teacher/schedules/today   # Today's schedule

// Reports
GET /api/v1/teacher/reports/export-excel    # Export Excel
GET /api/v1/teacher/reports/export-pdf      # Export PDF
GET /api/v1/teacher/reports/monthly-summary # Monthly summary

// Self-Attendance
POST /api/v1/teacher/attendance/check-in    # Check in
POST /api/v1/teacher/attendance/check-out   # Check out
GET  /api/v1/teacher/attendance/today       # Today's status
GET  /api/v1/teacher/attendance/history     # History
GET  /api/v1/teacher/attendance/summary     # Summary
GET  /api/v1/teacher/attendance/devices     # Registered devices

// QR Code
POST /api/v1/teacher/qr/generate    # Generate session QR
POST /api/v1/teacher/qr/close       # Close session

// Permissions
GET   /api/v1/teacher/permissions        # List permissions
POST  /api/v1/teacher/permissions        # Create permission
PATCH /api/v1/teacher/permissions/{id}/status  # Approve/reject
```

### 4. `routes/api/v1/admin.php`

School administration routes:

```php
// Dashboard
GET /api/v1/admin/dashboard/class-attendance  # Class attendance
GET /api/v1/admin/dashboard/teacher-absent    # Absent teachers
GET /api/v1/admin/dashboard/late-alpha        # Late/absent students
GET /api/v1/admin/dashboard/anomalies         # Anomalies

// Teachers Management
GET    /api/v1/admin/teachers                     # List all
POST   /api/v1/admin/teachers                     # Create
POST   /api/v1/admin/teachers/import              # Import
PUT    /api/v1/admin/teachers/{id}                # Update
PATCH  /api/v1/admin/teachers/{id}/status         # Toggle status
GET    /api/v1/admin/teachers/assignments         # Assignments
POST   /api/v1/admin/teachers/assignments         # Create assignment
DELETE /api/v1/admin/teachers/assignments/{id}    # Remove assignment
POST   /api/v1/admin/teachers/homeroom            # Set homeroom

// Students Management
GET   /api/v1/admin/students                   # List all
POST  /api/v1/admin/students                   # Create
POST  /api/v1/admin/students/import            # Import
PUT   /api/v1/admin/students/{id}              # Update
GET   /api/v1/admin/students/placement         # Placements
PATCH /api/v1/admin/students/{id}/placement    # Update placement
GET   /api/v1/admin/students/mutations         # Mutations
PATCH /api/v1/admin/students/{id}/mutation     # Update mutation
GET   /api/v1/admin/students/{id}/qr-card      # QR card
POST  /api/v1/admin/students/verify-qr-card    # Verify QR

// Classes Management
GET    /api/v1/admin/classes              # List all
POST   /api/v1/admin/classes              # Create
PUT    /api/v1/admin/classes/{id}         # Update
PATCH  /api/v1/admin/classes/{id}/status  # Toggle status
DELETE /api/v1/admin/classes/{id}         # Delete

// Schedules
GET  /api/v1/admin/schedules        # List all
POST /api/v1/admin/schedules        # Create
PUT  /api/v1/admin/schedules/{id}   # Update

// Settings
GET /api/v1/admin/settings/profile              # School profile
PUT /api/v1/admin/settings/profile              # Update profile
GET /api/v1/admin/settings/config               # Configuration
PUT /api/v1/admin/settings/config               # Update config
GET /api/v1/admin/settings/academic-year        # Academic years
POST /api/v1/admin/settings/academic-year       # Create year
PUT  /api/v1/admin/settings/academic-year/{id}  # Update year
PATCH /api/v1/admin/settings/academic-year/{id}/activate  # Activate
DELETE /api/v1/admin/settings/academic-year/{id}  # Delete

// Others
GET /api/v1/admin/subjects    # List subjects
GET /api/v1/admin/parents     # List parents
GET /api/v1/admin/reports     # Reports
```

---

## Adding New Routes

### To V1

1. Create new route file in `routes/api/v1/`:
   ```php
   // routes/api/v1/newfeature.php
   Route::middleware(['auth:sanctum'])->group(function () {
       Route::get('/new', [NewController::class, 'index']);
   });
   ```

2. Include in `routes/api/v1.php`:
   ```php
   Route::prefix('newfeature')->group(
       base_path('routes/api/v1/newfeature.php')
   );
   ```

### To V2 (Future)

1. Enable v2 in `RouteServiceProvider`:
   ```php
   Route::middleware('api')
       ->prefix('api/v2')
       ->group(base_path('routes/api/v2.php'));
   ```

2. Add routes to `routes/api/v2.php`

---

## Rate Limiting

Rate limits are configured in `RouteServiceProvider`:

| Limiter | Limit | Scope |
|---------|-------|-------|
| `api` | 60/min | Per user/IP |
| `login` | 5/min | Per username/IP |
| `scan` | 30/min | Per user+IP |
| `global` | 100/min | Per IP |
| `export` | 10/hour | Per user |
| `school` | 100/min | Per school |

---

## Migration Guide

### From Legacy to v1

All endpoints remain the same. The legacy `routes/api.php` is still loaded for backward compatibility.

### Planned v2 Changes

- Standardized JSend response format
- Improved error codes
- Better pagination (cursor-based)
- GraphQL support (optional)

---

## Testing Routes

```bash
# List all v1 routes
php artisan route:list --path=api/v1

# Check specific route
php artisan route:list --path=api/v1/attendance

# Cache routes for production
php artisan route:cache

# Clear route cache
php artisan route:clear
```
