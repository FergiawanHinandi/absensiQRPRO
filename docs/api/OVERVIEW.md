# API Overview - AbsensiQRPro

**Last Updated:** January 28, 2026  
**Version:** 1.0.0  
**Base URL:** `https://api.school.com/api/v1`

---

## 📋 Table of Contents

1. [Introduction](#introduction)
2. [Authentication](#authentication)
3. [Security Layers](#security-layers)
4. [Rate Limiting](#rate-limiting)
5. [Response Format](#response-format)
6. [Error Handling](#error-handling)
7. [Endpoints Overview](#endpoints-overview)

---

## 🎯 Introduction

AbsensiQRPro API adalah RESTful API yang menyediakan akses ke sistem absensi sekolah dengan keamanan multi-layer.

### Key Features

- ✅ **RESTful Design** - Standard HTTP methods (GET, POST, PUT, DELETE)
- ✅ **JWT Authentication** - Laravel Sanctum tokens
- ✅ **Role-Based Access** - Admin, Teacher, Student, Parent
- ✅ **Multi-Tenant** - Automatic school isolation
- ✅ **Rate Limited** - Protection against abuse
- ✅ **Secure** - 5-layer security implementation

### API Characteristics

```
Protocol:      HTTPS only
Format:        JSON
Authentication: Bearer Token (Sanctum)
Versioning:    URL-based (/api/v1/)
Rate Limit:    60 requests/min per user
```

---

## 🔑 Authentication

### Login

**Endpoint:** `POST /auth/login`

**Request:**
```json
{
  "username": "admin",
  "password": "password"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "name": "Admin User",
      "username": "admin",
      "email": "admin@school.com",
      "role_type": "school_admin",
      "school_id": 1
    },
    "access_token": "1|abc123...",
    "token_type": "Bearer"
  }
}
```

### Using Token

**All authenticated requests must include:**

```http
Authorization: Bearer 1|abc123...
```

**Example:**
```bash
curl https://api.school.com/api/v1/auth/me \
  -H "Authorization: Bearer 1|abc123..."
```

### Token Refresh

**Endpoint:** `POST /auth/refresh`

**Response:**
```json
{
  "success": true,
  "data": {
    "access_token": "2|xyz789...",
    "token_type": "Bearer"
  }
}
```

### Logout

**Endpoint:** `POST /auth/logout`

**Response:**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

---

## 🛡️ Security Layers

### Layer 1: HTTPS Only

```
All API requests MUST use HTTPS
HTTP requests are automatically redirected to HTTPS
```

### Layer 2: Authentication

```
Middleware: auth:sanctum
Validates: Bearer token
Rejects: Unauthenticated requests (401)
```

### Layer 3: Role-Based Access

```
Middleware: role:admin|teacher|student
Validates: User has required role
Rejects: Unauthorized roles (403)
```

### Layer 4: Policy Authorization

```
Controller: $this->authorize('view', $resource)
Validates: User can access specific resource
Rejects: Cross-school access (403)
```

### Layer 5: Rate Limiting

```
Middleware: rate.limit:api
Limit: 60 requests per minute per user
Rejects: Excessive requests (429)
```

**See:** [`SECURITY.md`](SECURITY.md) for details

---

## ⚡ Rate Limiting

### Rate Limits by Endpoint Type

| Endpoint Type | Limit | Decay | Header |
|---------------|-------|-------|--------|
| **Global** | 1000/min per IP | 1 min | `X-RateLimit-Limit: 1000` |
| **Login** | 5 per 5min | 5 min | `X-RateLimit-Limit: 5` |
| **QR Scan** | 10/min per user+device | 1 min | `X-RateLimit-Limit: 10` |
| **API** | 60/min per user | 1 min | `X-RateLimit-Limit: 60` |

### Rate Limit Headers

**Every response includes:**

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
```

**When limit exceeded:**

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 60
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0

{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "retry_after": 60
}
```

**See:** [`RATE_LIMITING.md`](RATE_LIMITING.md) for details

---

## 📦 Response Format

### Success Response

```json
{
  "success": true,
  "message": "Operation successful",
  "data": {
    // Response data here
  }
}
```

### Success with Pagination

```json
{
  "success": true,
  "data": {
    "data": [
      // Array of items
    ],
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 73
  }
}
```

### Error Response

```json
{
  "success": false,
  "message": "Error message",
  "errors": {
    "field": ["Validation error message"]
  }
}
```

---

## ❌ Error Handling

### HTTP Status Codes

| Code | Meaning | Description |
|------|---------|-------------|
| **200** | OK | Request successful |
| **201** | Created | Resource created |
| **400** | Bad Request | Invalid request |
| **401** | Unauthorized | Not authenticated |
| **403** | Forbidden | Not authorized |
| **404** | Not Found | Resource not found |
| **422** | Unprocessable | Validation failed |
| **429** | Too Many Requests | Rate limit exceeded |
| **500** | Server Error | Internal error |

### Error Examples

#### 401 Unauthorized

```json
{
  "success": false,
  "message": "Unauthenticated"
}
```

#### 403 Forbidden

```json
{
  "success": false,
  "message": "Unauthorized"
}
```

#### 422 Validation Error

```json
{
  "success": false,
  "message": "Validation Error",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

#### 429 Rate Limit

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "retry_after": 60
}
```

---

## 📡 Endpoints Overview

### Authentication

| Method | Endpoint | Description | Auth | Rate Limit |
|--------|----------|-------------|------|------------|
| POST | `/auth/login` | Login | No | 5/5min |
| POST | `/auth/logout` | Logout | Yes | 60/min |
| POST | `/auth/refresh` | Refresh token | Yes | 60/min |
| GET | `/auth/me` | Get current user | Yes | 60/min |

### Attendance

| Method | Endpoint | Description | Auth | Rate Limit |
|--------|----------|-------------|------|------------|
| POST | `/attendance/scan` | Scan QR code | Yes | 10/min |
| GET | `/attendance/history` | Get history | Yes | 60/min |

### Admin - Students

| Method | Endpoint | Description | Auth | Rate Limit |
|--------|----------|-------------|------|------------|
| GET | `/admin/students` | List students | Admin | 60/min |
| POST | `/admin/students` | Create student | Admin | 60/min |
| GET | `/admin/students/{id}` | Get student | Admin | 60/min |
| PUT | `/admin/students/{id}` | Update student | Admin | 60/min |
| DELETE | `/admin/students/{id}` | Delete student | Admin | 60/min |

### Admin - Teachers

| Method | Endpoint | Description | Auth | Rate Limit |
|--------|----------|-------------|------|------------|
| GET | `/admin/teachers` | List teachers | Admin | 60/min |
| POST | `/admin/teachers` | Create teacher | Admin | 60/min |
| GET | `/admin/teachers/{id}` | Get teacher | Admin | 60/min |
| PUT | `/admin/teachers/{id}` | Update teacher | Admin | 60/min |
| DELETE | `/admin/teachers/{id}` | Delete teacher | Admin | 60/min |

### Admin - Classes

| Method | Endpoint | Description | Auth | Rate Limit |
|--------|----------|-------------|------|------------|
| GET | `/admin/classes` | List classes | Admin | 60/min |
| POST | `/admin/classes` | Create class | Admin | 60/min |
| GET | `/admin/classes/{id}` | Get class | Admin | 60/min |
| PUT | `/admin/classes/{id}` | Update class | Admin | 60/min |
| DELETE | `/admin/classes/{id}` | Delete class | Admin | 60/min |

### Teacher

| Method | Endpoint | Description | Auth | Rate Limit |
|--------|----------|-------------|------|------------|
| GET | `/teacher/dashboard` | Dashboard stats | Teacher | 60/min |
| GET | `/teacher/my-students` | My students | Teacher | 60/min |
| GET | `/teacher/schedules/today` | Today's schedule | Teacher | 60/min |

### Parent

| Method | Endpoint | Description | Auth | Rate Limit |
|--------|----------|-------------|------|------------|
| GET | `/parent/my-children` | My children | Parent | 60/min |
| GET | `/parent/children/{id}/attendance` | Child attendance | Parent | 60/min |

**See:** [`ENDPOINTS.md`](ENDPOINTS.md) for complete API reference

---

## 🔒 Security Best Practices

### For API Consumers

#### DO ✅

1. **Always use HTTPS**
   ```javascript
   const API_URL = 'https://api.school.com/api/v1';
   ```

2. **Store tokens securely**
   ```javascript
   // Mobile: Use Keychain/Keystore
   await SecureStorage.storeTokens(access, refresh);
   
   // Web: Use httpOnly cookies or secure storage
   ```

3. **Handle token refresh**
   ```javascript
   axios.interceptors.response.use(
     response => response,
     async error => {
       if (error.response?.status === 401) {
         // Refresh token
         await refreshToken();
         // Retry request
       }
     }
   );
   ```

4. **Respect rate limits**
   ```javascript
   const remaining = response.headers['x-ratelimit-remaining'];
   if (remaining < 5) {
     // Slow down requests
   }
   ```

#### DON'T ❌

1. **Never log tokens**
   ```javascript
   // ❌ DANGEROUS
   console.log('Token:', token);
   
   // ✅ SAFE
   console.log('Token stored successfully');
   ```

2. **Never store tokens in localStorage (web)**
   ```javascript
   // ❌ VULNERABLE
   localStorage.setItem('token', token);
   
   // ✅ SECURE
   // Use httpOnly cookies or secure storage
   ```

3. **Never ignore 403 errors**
   ```javascript
   // ❌ WRONG
   if (error.status === 403) {
     // Ignore and continue
   }
   
   // ✅ CORRECT
   if (error.status === 403) {
     // User disabled or unauthorized
     logout();
   }
   ```

---

## 📚 Additional Resources

### Documentation
- **Complete Endpoints:** [`ENDPOINTS.md`](ENDPOINTS.md)
- **Authentication:** [`AUTHENTICATION.md`](AUTHENTICATION.md)
- **Security:** [`SECURITY.md`](SECURITY.md)
- **Rate Limiting:** [`RATE_LIMITING.md`](RATE_LIMITING.md)

### Code Examples
- **Backend Routes:** `backend/routes/api.php`
- **Controllers:** `backend/app/Http/Controllers/Api/V1/*`
- **Tests:** `backend/tests/Feature/*Test.php`

### Tools
- **Postman Collection:** Available in `docs/postman/`
- **OpenAPI Spec:** Available in `docs/openapi.yaml`

---

## 🎉 Summary

```
┌──────────────────────────────────────────────────────┐
│                                                      │
│  AbsensiQRPro API                                   │
│                                                      │
│  Base URL: https://api.school.com/api/v1           │
│  Auth: Bearer Token (Sanctum)                       │
│  Format: JSON                                        │
│  Security: 5 layers                                  │
│  Rate Limit: 60 req/min                             │
│                                                      │
│  Status: Production Ready ✅                        │
│                                                      │
└──────────────────────────────────────────────────────┘
```

---

**Last Updated:** January 28, 2026  
**Maintained by:** API Team  
**For Support:** See [`../FAQ.md`](../FAQ.md)
