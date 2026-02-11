# 🛡️ Zero-Trust Architecture: Attendance System

## 1. 🌐 Trust Boundary Diagram

```mermaid
graph TD
    subgraph "Untrusted Zone (Public Internet)"
        Mobile[Mobile App (User/Device)]
        Hacker[Attacker / Replay Bot]
    end

    subgraph "Perimeter Defense (DMZ)"
        WAF[WAF / API Gateway]
        Throttle[Rate Limiter]
    end

    subgraph "Trust Verification Layer (Middleware)"
        Auth[Sanctum Auth (Token)]
        Device[Device Fingerprint Check]
        Idem[Idempotency Check]
        Scope[Tenant Isolation Scope]
    end

    subgraph "Trusted Zone (Core Backend)"
        Policy[Policy Gate (Authorization)]
        Service[Attendance Service (Logic)]
        Agg[Aggregate Root (State Machine)]
        Audit[Audit Logger]
    end

    subgraph "Data Persistence"
        DB[(Database)]
    end

    Mobile -->|HTTPS + Signed Payload| WAF
    Hacker -.->|DoS / Injection| WAF
    WAF --> Throttle
    Throttle --> Auth
    Auth -->|Valid Token| Device
    Device -->|Valid Fingerprint| Idem
    Idem -->|Unique Key| Scope
    Scope -->|Isolated Context| Policy
    Policy -->|Authorized| Service
    Service -->|Decision| Agg
    Agg -->|State Change| DB
    Agg -.->|Log| Audit
```

## 2. 🏛️ Core Zero-Trust Pillars Implemented

### A. Identity & Access (Never Trust Payload)
1.  **Strict Token Validation:**
    -   Token Expiry: 60 minutes (Short-lived).
    -   Signature: SHA-256 (Sanctum default).
    -   **Token Rotation:** Refresh token mechanism enforced on mobile via interceptor.
2.  **Least Privilege:**
    -   `Student`: Can ONLY `scan`. Cannot `create` manual or `update`.
    -   `Teacher`: Can `scan_student` or `manual_entry`. Cannot `delete`.
    -   `Admin`: Can `update` but with Audit Log.

### B. Device Integrity
1.  **Passive Observation:**
    -   Backend **TIDAK** mempercayai `status` dari client.
    -   Client hanya kirim: `qr_token`, `lat`, `lng`, `images`, `device_signature`.
    -   **Backend determines:** `status = (distance < radius) ? PRESENT : LATE/INVALID`.
2.  **Anti-Spoofing:**
    -   Device ID wajib terdaftar (Teacher).
    -   Anomaly detection (Speed check: distance/time).

### C. Data Integrity (Tenant Isolation)
1.  **Database Level Isolation:**
    -   Global Scope `SchoolScope` diterapkan otomatis.
    -   Query tanpa `school_id` injeksi akan otomatis difilter based on User's School.
    -   **Validation:** Cross-tenant access returns `404 Not Found` (Not 403, to prevent enumeration).

## 3. 🔐 Refactoring Changes

| Component | Before | After (Zero-Trust) |
|-----------|--------|---------------------|
| **Decision** | Client sent `status='present'` | Service calculates status based on Rules |
| **Auth** | Role Middleware | Granular `Gate::authorize('scan', $schedule)` |
| **Trust** | Trusted Client Input | Verified Input only (Signature check) |
| **Logging** | On Error/Success | Audit Log for EVERY privileged write op |
| **Endpoint** | Public route mapping | All routes wrapped in `auth:sanctum` + `ability` |

## 4. 🚨 Residual Risks & Mitigation

1.  **Compromised Device (Rooted + Credential Theft):**
    -   *Risk:* Attacker clones valid session.
    -   *Mitigation:* **Behavioral Analysis** (e.g., Impossible Travel). Jika user login di Jakarta, lalu 5 menit kemudian absen di Bandung -> Block Account.

2.  **Key Logger on User Device:**
    -   *Risk:* Password theft.
    -   *Mitigation:* MFA (Multi-Factor Authentication) required for Admin/Teacher login.

## 5. Implementation Validation
- **No Logical Decisions on Client:** Mobile app is "dumb terminal".
- **Strict Scope:** `User::withoutGlobalScope()` is banned in Controllers.
