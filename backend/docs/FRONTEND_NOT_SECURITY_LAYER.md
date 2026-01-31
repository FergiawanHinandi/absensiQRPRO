# Frontend BUKAN Security Layer - Security Audit

**Tanggal:** 28 Januari 2026  
**Priority:** 🔴 CRITICAL  
**Prinsip:** **NEVER TRUST THE CLIENT**

---

## ⚠️ PERINGATAN KRITIS

```
┌──────────────────────────────────────────────────────────────┐
│                                                              │
│   FRONTEND ROUTE GUARDS ≠ SECURITY                          │
│                                                              │
│   ProtectedRoute, navigator.guard, role checks di React    │
│   HANYA UNTUK UX, BUKAN UNTUK SECURITY!                     │
│                                                              │
│   Attacker bisa bypass semua ini dengan:                    │
│   - curl/Postman                                             │
│   - Browser DevTools                                         │
│   - Custom HTTP client                                       │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

---

## 🎯 Prinsip Dasar

### ❌ SALAH: Mengandalkan Frontend

```typescript
// ❌ BERBAHAYA - Ini BUKAN security!
function AdminDashboard() {
    const user = useAuth();
    
    // ❌ Ini hanya UX, bukan security
    if (user.role !== 'admin') {
        return <Navigate to="/unauthorized" />;
    }
    
    // ❌ Attacker bisa bypass ini dengan DevTools
    // dan langsung panggil API
    return <div>Admin Content</div>;
}
```

**Kenapa berbahaya?**
1. Attacker bisa edit JavaScript di browser
2. Bisa panggil API langsung dengan curl/Postman
3. Bisa manipulasi localStorage/sessionStorage
4. Bisa bypass semua route guards

### ✅ BENAR: Backend Sebagai Security Layer

```php
// ✅ AMAN - Backend validation
Route::middleware(['auth:sanctum', 'role:admin'])
    ->get('/admin/dashboard', [AdminController::class, 'index']);

class AdminController extends Controller
{
    public function index(Request $request)
    {
        // ✅ LAYER 1: Middleware sudah cek auth + role
        
        // ✅ LAYER 2: Double check di controller
        if (!$request->user()->hasRole('admin')) {
            abort(403, 'Unauthorized');
        }
        
        // ✅ LAYER 3: Policy check untuk specific resource
        $this->authorize('viewDashboard', Admin::class);
        
        // ✅ AMAN: Data hanya dikirim jika semua check pass
        return response()->json(['data' => $dashboardData]);
    }
}
```

---

## 🛡️ Backend Security Layers (AbsensiQRPro)

### Layer 1: Authentication Middleware

**File:** `routes/api.php`

```php
// Semua route protected dengan auth:sanctum
Route::prefix('v1')
    ->middleware(['auth:sanctum', CheckApiMaintenance::class])
    ->group(function () {
        // Routes here
    });
```

**Validasi:**
- ✅ Token Sanctum valid?
- ✅ User exists?
- ✅ Token belum expired?
- ✅ Token belum di-revoke?

### Layer 2: Role Middleware

**File:** `app/Http/Middleware/EnsureUserHasRole.php`

```php
Route::middleware('role:admin,school_admin')
    ->prefix('admin')
    ->group(function () {
        // Only admin & school_admin can access
    });
```

**Validasi:**
- ✅ User punya role yang sesuai?
- ✅ Role aktif?
- ✅ User aktif (`is_active = true`)?

### Layer 3: Policy Authorization

**File:** `app/Policies/*Policy.php`

```php
// Di controller
$this->authorize('view', $student);

// Di policy
public function view(User $user, User $student): bool
{
    // ✅ CRITICAL: School ID check
    if ($user->school_id !== $student->school_id) {
        return false;
    }
    
    // ✅ Role-based check
    return $user->hasRole('admin');
}
```

**Validasi:**
- ✅ User punya permission untuk action ini?
- ✅ Resource belong to same school?
- ✅ User aktif?

### Layer 4: Global Scopes

**File:** `app/Traits/BelongsToSchool.php`

```php
// Otomatis filter semua query by school_id
User::all(); // Hanya return users dari school yang sama
```

**Validasi:**
- ✅ Automatic `school_id` filtering
- ✅ Prevent cross-school data access

### Layer 5: Rate Limiting

**File:** `routes/api.php`

```php
Route::post('/attendance/scan', [AttendanceController::class, 'scan'])
    ->middleware('throttle:scan'); // 5 requests per minute
```

**Validasi:**
- ✅ Prevent brute force
- ✅ Prevent DDoS
- ✅ Per-user rate limiting

---

## 📊 Security Audit Results

### ✅ Backend Protection (AMAN)

| Endpoint | Auth | Role Check | Policy | Global Scope | Rate Limit |
|----------|------|------------|--------|--------------|------------|
| `/admin/students` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/teachers` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/classes` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/attendance/scan` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/teacher/dashboard` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/parent/my-children` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/super-admin/*` | ✅ | ✅ | ✅ | N/A | ✅ |

**Kesimpulan:** Semua endpoint protected dengan minimal 3 layers.

### ⚠️ Frontend Protection (HANYA UX)

**File:** `frontend-web/src/components/ProtectedRoute.tsx`

```typescript
// ⚠️ INI HANYA UX, BUKAN SECURITY!
export function ProtectedRoute({ children, allowedRoles }) {
    const { user } = useAuth();
    
    if (!user) {
        return <Navigate to="/login" />;
    }
    
    if (!allowedRoles.includes(user.role)) {
        return <Navigate to="/unauthorized" />;
    }
    
    return children;
}
```

**Fungsi:**
- ✅ Improve UX (tidak perlu load page yang tidak bisa diakses)
- ✅ Prevent accidental navigation
- ❌ **BUKAN untuk security**
- ❌ **Bisa di-bypass dengan mudah**

---

## 🚨 Attack Scenarios & Defense

### Scenario 1: Bypass Frontend Route Guard

**Attack:**
```bash
# Attacker bypass React router dan langsung panggil API
curl -X GET https://api.school.com/v1/admin/students \
  -H "Authorization: Bearer STUDENT_TOKEN"
```

**Defense:**
```php
// ✅ Backend middleware blocks this
Route::middleware(['auth:sanctum', 'role:admin'])
    ->get('/admin/students', [StudentController::class, 'index']);

// Response: 403 Forbidden
{
    "message": "Unauthorized. Required role: admin"
}
```

### Scenario 2: Manipulate localStorage Role

**Attack:**
```javascript
// Attacker edit localStorage di browser
localStorage.setItem('user', JSON.stringify({
    id: 123,
    role: 'super_admin' // ← Fake role
}));

// Frontend akan show admin menu
// Tapi API tetap reject!
```

**Defense:**
```php
// ✅ Backend tidak percaya client data
// Token Sanctum berisi user_id, backend load fresh data
$user = $request->user(); // Load dari database
$role = $user->role_type; // Bukan dari localStorage!

if ($role !== 'super_admin') {
    abort(403);
}
```

### Scenario 3: Tamper API Request

**Attack:**
```javascript
// Attacker intercept request dan ubah student_id
fetch('/api/v1/admin/students/999/update', {
    method: 'PUT',
    body: JSON.stringify({
        student_id: 999, // ← Student dari sekolah lain
        name: 'Hacked'
    })
});
```

**Defense:**
```php
// ✅ LAYER 1: Policy check
$this->authorize('update', $student);

// ✅ LAYER 2: School ownership validation
$this->validateSchoolOwnership($student);

// ✅ LAYER 3: Global scope
// Student::find(999) akan return null jika beda school

// Response: 403 Forbidden
```

### Scenario 4: Replay Attack

**Attack:**
```bash
# Attacker capture valid request dan replay
curl -X POST https://api.school.com/v1/attendance/scan \
  -H "Authorization: Bearer VALID_TOKEN" \
  -d '{"qr_token": "captured_token"}'
```

**Defense:**
```php
// ✅ Nonce validation di QrService
if (Cache::has("qr_nonce:{$nonce}")) {
    throw new InvalidQrException('Replay attack detected');
}

// ✅ Idempotency check
if ($requestId && $this->attendanceRepo->findByRequestId($requestId)) {
    return $existing; // Return existing, tidak create duplicate
}
```

---

## 📋 Security Checklist

### Backend (WAJIB)

- [x] ✅ Semua route protected dengan `auth:sanctum`
- [x] ✅ Role middleware untuk semua protected endpoints
- [x] ✅ Policy authorization untuk resource access
- [x] ✅ Global scope untuk multi-tenant isolation
- [x] ✅ Rate limiting untuk prevent abuse
- [x] ✅ Input validation dengan FormRequest
- [x] ✅ CSRF protection (Sanctum SPA)
- [x] ✅ SQL injection prevention (Eloquent)
- [x] ✅ XSS prevention (output escaping)
- [x] ✅ Security headers (HSTS, CSP, etc)

### Frontend (UX Only)

- [x] ✅ ProtectedRoute untuk UX
- [x] ✅ Role-based menu visibility
- [x] ✅ Conditional rendering based on permissions
- [ ] ⚠️ **JANGAN** gunakan frontend check sebagai security
- [ ] ⚠️ **JANGAN** simpan sensitive data di localStorage
- [ ] ⚠️ **JANGAN** trust data dari localStorage/sessionStorage

---

## 🔍 Code Review Guidelines

### ❌ Red Flags (BAHAYA)

```typescript
// ❌ BAHAYA: Security logic di frontend
if (user.role === 'admin') {
    // Fetch sensitive data
    const secrets = await api.get('/secrets');
}

// ❌ BAHAYA: Mengandalkan frontend validation
if (amount > 0) {
    await api.post('/payment', { amount });
}

// ❌ BAHAYA: Client-side authorization
if (canDelete) {
    await api.delete(`/students/${id}`);
}
```

### ✅ Best Practices

```typescript
// ✅ BAIK: Frontend hanya untuk UX
if (user.role === 'admin') {
    // Show admin menu (UX only)
    return <AdminMenu />;
}

// ✅ BAIK: Backend akan validate ulang
try {
    const response = await api.get('/admin/secrets');
    // Backend sudah cek role, policy, dll
} catch (error) {
    // Handle 403 Forbidden
}

// ✅ BAIK: Let backend decide
try {
    await api.delete(`/students/${id}`);
    // Backend akan cek policy, ownership, dll
} catch (error) {
    if (error.status === 403) {
        toast.error('Tidak ada permission');
    }
}
```

---

## 🧪 Testing Security

### Test 1: Bypass Frontend Guard

```bash
# Test: Student token mencoba akses admin endpoint
curl -X GET https://api.school.com/v1/admin/students \
  -H "Authorization: Bearer STUDENT_TOKEN"

# Expected: 403 Forbidden
```

### Test 2: Cross-School Access

```bash
# Test: Admin School A mencoba akses data School B
curl -X GET https://api.school.com/v1/admin/students/999 \
  -H "Authorization: Bearer ADMIN_SCHOOL_A_TOKEN"

# Expected: 404 Not Found (global scope filter)
# atau 403 Forbidden (policy check)
```

### Test 3: Expired Token

```bash
# Test: Token yang sudah expired
curl -X GET https://api.school.com/v1/admin/students \
  -H "Authorization: Bearer EXPIRED_TOKEN"

# Expected: 401 Unauthorized
```

### Test 4: Tampered Payload

```bash
# Test: Ubah student_id di payload
curl -X PUT https://api.school.com/v1/admin/students/123 \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -d '{"student_id": 999, "name": "Hacked"}'

# Expected: 403 Forbidden (policy check)
```

---

## 📚 Developer Guidelines

### Rule 1: Never Trust The Client

```
┌─────────────────────────────────────────┐
│  SEMUA input dari client HARUS         │
│  divalidasi di backend!                 │
│                                         │
│  - Form data                            │
│  - Query parameters                     │
│  - Headers                              │
│  - Cookies                              │
│  - localStorage data                    │
└─────────────────────────────────────────┘
```

### Rule 2: Defense in Depth

```
Request → Auth → Role → Policy → Global Scope → Business Logic
   ↓       ↓      ↓       ↓           ↓              ↓
  401    403    403     403         404           200 OK
```

Setiap layer adalah checkpoint. Jika satu layer gagal, layer berikutnya tetap protect.

### Rule 3: Fail Secure

```php
// ✅ BAIK: Default deny
if (!$user->hasPermission('delete_student')) {
    abort(403); // Deny by default
}

// ❌ BAHAYA: Default allow
if ($user->hasPermission('delete_student')) {
    // Allow
} else {
    // Lupa handle else = allow!
}
```

### Rule 4: Log Security Events

```php
// ✅ Log semua security-related events
Log::warning('Unauthorized access attempt', [
    'user_id' => $user->id,
    'endpoint' => $request->path(),
    'ip' => $request->ip(),
]);
```

---

## ✅ Kesimpulan

### Frontend Role

```
Frontend = UX Layer
- Show/hide menu based on role
- Redirect ke login jika belum auth
- Show loading states
- Handle error responses
- Improve user experience
```

### Backend Role

```
Backend = Security Layer
- Authenticate user
- Authorize actions
- Validate input
- Enforce business rules
- Protect data
- Log security events
```

### Golden Rule

```
┌──────────────────────────────────────────────────────┐
│                                                      │
│  JIKA ADA SECURITY CHECK DI FRONTEND,               │
│  HARUS ADA YANG SAMA (ATAU LEBIH KETAT)             │
│  DI BACKEND!                                         │
│                                                      │
│  Frontend check = UX                                 │
│  Backend check = SECURITY                            │
│                                                      │
└──────────────────────────────────────────────────────┘
```

---

**Status:** ✅ **BACKEND AMAN**  
**Frontend:** ⚠️ **HANYA UX (By Design)**  
**Security Posture:** 🟢 **STRONG**

**Prinsip:** **NEVER TRUST THE CLIENT**  
**Implementasi:** **DEFENSE IN DEPTH**  
**Result:** **SECURE BY DEFAULT**
