# 🛡️ Zero Trust Architecture Strategy

**Zero Trust Security Architect**  
**Date**: 2026-02-10  
**Target**: Multi-Tenant Laravel SaaS  
**Principle**: "Never Trust, Always Verify"

---

## 🎯 Executive Summary

Implementasi Zero Trust ini membungkus *existing auth flow* dengan lapisan verifikasi tambahan. Kita tidak mengganti driver autentikasi (Sanctum/Passport), melainkan menambahkan **Contextual Access Policies** sebelum request mencapai Controller.

---

## 🚧 Layer 1: Middleware & Request Pipeline

Kita akan membuat Middleware Group baru: `zero_trust`.

### 1. Middleware Structure

```php
// app/Http/Kernel.php

protected $middlewareGroups = [
    'zero_trust' => [
        \App\Http\Middleware\ZeroTrust\EnforceSecureHeaders::class,
        \App\Http\Middleware\ZeroTrust\ValidateDeviceFingerprint::class, // Device validation
        \App\Http\Middleware\ZeroTrust\DetectUserAnomaly::class,         // Velocity checks
        \App\Http\Middleware\ZeroTrust\ContextualRateLimiter::class,     // Tenant-aware throttling
        \App\Http\Middleware\ZeroTrust\EnforceTenantIsolation::class,    // Strict tenant boundary
    ],
];
```

### 2. Device Fingerprint Validation `ValidateDeviceFingerprint`

Memastikan token tidak dicuri dan digunakan di mesin berbeda.

```php
public function handle($request, Closure $next)
{
    $user = $request->user();
    $currentFingerprint = hash('sha256', 
        $request->ip() . 
        $request->header('User-Agent') . 
        $request->header('X-Device-ID')
    );

    // Bandingkan dengan fingerprint saat login (disimpan di Redis/DB)
    $storedFingerprint = Cache::get("auth_device:{$user->id}");

    if ($storedFingerprint && !hash_equals($storedFingerprint, $currentFingerprint)) {
        Log::warning('Session hijacking attempt detected', ['user_id' => $user->id]);
        
        // REVOKE TOKEN
        $user->currentAccessToken()->delete();
        
        abort(403, 'Session invalid due to device change. Please login again.');
    }

    return $next($request);
}
```

### 3. Anomaly Detection `DetectUserAnomaly`

Mendeteksi perilaku tidak wajar (cth: pindah lokasi drastis dalam waktu singkat).

```php
public function handle($request, Closure $next)
{
    $key = "geo_velocity:{$request->user()->id}";
    $lastGeo = Cache::get($key);
    $currentGeo = GeoIP::getLocation($request->ip());

    if ($lastGeo) {
        $distance = $this->calculateDistance($lastGeo, $currentGeo);
        $timeDiff = now()->diffInMinutes($lastGeo['timestamp']);

        // Impossible Travel (e.g. 500km in 5 mins)
        if ($distance > 500 && $timeDiff < 60) {
            event(new SecurityAlertTriggered('impossible_travel', $request->user()));
            abort(403, 'Access denied due to travel anomaly.');
        }
    }

    Cache::put($key, ['lat' => $currentGeo->lat, 'lon' => $currentGeo->lon, 'timestamp' => now()], 300);

    return $next($request);
}
```

---

## 🔗 Layer 2: Service-to-Service Verification

Untuk komunikasi antar worker/service internal, kita **TIDAK** menggunakan User Token, melainkan **Service Identity**.

### Implementation: Signed Internal Headers

Setiap request internal (misal dari Job ke API) harus menyertakan header tanda tangan kriptografis.

```php
// ServiceRequestFactory.php
public function makeInternalRequest($method, $url, $data)
{
    $timestamp = now()->timestamp;
    $nonce = Str::random(16);
    
    // Create Signature
    $signature = hash_hmac('sha256', "{$method}|{$url}|{$timestamp}|{$nonce}", config('app.internal_secret'));

    return Http::withHeaders([
        'X-Service-ID' => config('app.service_name'),
        'X-Timestamp' => $timestamp,
        'X-Nonce' => $nonce,
        'X-Signature' => $signature,
    ])->$method($url, $data);
}
```

Middleware `VerifyInternalSignature` akan memvalidasi signature ini di sisi penerima.

---

## 🛡️ Layer 3: Database Hardening

Mencegah kebocoran data antar tenant (Cross-Tenant Leak).

### 1. Global Scope Strictness

```php
// App/Traits/BelongsToSchool.php

public static function bootBelongsToSchool()
{
    static::addGlobalScope('school_scope', function (Builder $builder) {
        if (auth()->check()) {
            // ALWAYS filter by current user's school
            $builder->where('school_id', auth()->user()->school_id);
        } elseif (app()->runningInConsole() && !app()->has('current_school_context')) {
            // Panic if a worker runs a query without explicit school context
            throw new MissingTenantContextException("Worker attempted DB access without school context!");
        }
    });
}
```

### 2. Forbid `DB::table` (Code Policy)

Penggunaan `DB::table('attendances')` membypass Global Scope.
*   **Action**: Tambahkan custom rule di PHPStan/Larastan.
*   **CI/CD Block**: Build gagal jika ditemukan `DB::table` pada tabel-tabel multi-tenant.

---

## 🔒 Layer 4: Attendance Hardening Matrix

Tabel `attendances` adalah aset paling kritis. Validasi diperketat.

| Attribute | Zero Trust Check | Action if Fail |
|:---|:---|:---|
| **School ID** | Match `user->school_id` AND `student->school_id` | **BAN IP** (Criticial Tampering) |
| **Schedule ID** | Check Schedule Time Window (± 30 mins) | Reject (Business Logic) |
| **Location** | Radius Check + Mock Location Flag | Reject |
| **Nonce** | Check strict uniqueness in Redis (Atomic Lock) | Reject (Replay Attack) |
| **Signature** | Verify `HMAC(student_id + time, secret)` | **BAN USER** (Client Tampering) |

---

## 🚨 Layer 5: Monitoring & Alerting

Mengubah log pasif menjadi active defense.

### Incident Detection Rules

| Event | Threshold | Response |
|:---|:---|:---|
| **Failed Scans** | 5 failures / 10s | Temporary lockout (1 min) |
| **IP Hopping** | > 3 IPs / 1 min (same user) | Invalidate all sessions |
| **Cross-Tenant Query** | 1 occurrence | **CRITICAL ALERT** to Slack |
| **Admin Login** | New IP / Device | MFA Challenge required |

### Infrastructure Monitoring (Redis/DB)

Menggunakan Laravel Event Listeners untuk mendeteksi anomali pada query.

```php
// App/Listeners/SecurityQueryMonitor.php

public function handle(QueryExecuted $query)
{
    // Detect Cross-Tenant Access attempt
    if (str_contains($query->sql, 'select * from `attendances`') && !str_contains($query->sql, 'school_id')) {
        Log::critical('Potential Unscoped Query Detected', [
            'sql' => $query->sql,
            'url' => request()->fullUrl(),
            'user' => auth()->id()
        ]);
        
        Alert::to('security-channel')->send('Unscoped Query Detected!');
    }
}
```

---

## 📑 False Positive & User Experience Strategy

Zero Trust yang terlalu agresif akan mengganggu UX. Strategi penanganannya:

1.  **Step-Up Authentication (Not Block)**
    *   Jika anomali terdeteksi (device baru, lokasi aneh), jangan langsung blokir.
    *   Arahkan user ke halaman: "Entrer OTP sent to email to verify this new device."

2.  **Grace Period for Clock Skew**
    *   Izinkan toleransi waktu (± 1 menit) untuk validasi signature TOTP/HMAC untuk menangani jam device yang tidak sinkron.

3.  **Human Readable Error**
    *   Jangan tampilkan "403 Forbidden".
    *   Tampilkan: "We detected a security change. Please refresh and login again."

---

## 📉 Policy Enforcement Matrix

| Request Type | Auth | Context Check | Rate Limit | Risk Level |
|:---|:---|:---|:---|:---|
| **Login** | Credential | IP Reputation | Strict (5/min) | High |
| **Scan QR** | Token | Geo + Device + Schedule | Loose (60/min) | Critical |
| **View Dashboard** | Token | Tenant Scope | Normal | Low |
| **Export Data** | Token | Admin Role + MFA | Strict (1/min) | High |
| **Webhook In** | Signature | IP Whitelist | Bursty | Medium |

---

**Next Steps:**
1.  Implementasi `ZeroTrustMiddlewareGroup` di `Kernel.php`.
2.  Refactor `AttendanceController` untuk validasi Layer 4.
3.  Pasang `SecurityQueryMonitor` listener.
