# Arsitektur Backend AbsensiQRPro

Dokumen ini menjelaskan rancangan arsitektur backend untuk sistem absensi multi-tenant berbasis Laravel. Arsitektur ini mengadopsi prinsip **Clean Architecture** dan **Modular Monolith**, yang memprioritaskan skalabilitas, maintainability, dan pemisahan concerns.

## 1. High-Level Overview

Sistem dirancang sebagai **Modular Monolith**. Setiap modul memiliki tanggung jawab yang jelas dan terisolasi, namun tetap berada dalam satu codebase (repo) untuk kemudahan deployment awal. Komunikasi antar modul dilakukan melalui **Services/Interfaces**, bukan direct database query lintas domain.

### Diagram Layering

```mermaid
graph TD
    Client[Client Apps<br/>Web/Mobile] -->|JSON/HTTPS| Presentation
    
    subgraph "Interface / Presentation Layer"
        API[API Controllers]
        CLI[Console Commands]
        Jobs[Queue Workers]
    end
    
    subgraph "Application Layer (Use Cases)"
        Service[Services / Actions]
        DTO[Data Transfer Objects]
        Events[Event Listener]
    end
    
    subgraph "Domain Layer (Business Logic)"
        Entities[Entities / Models]
        Contracts[Repository Interfaces]
        Rules[Validation Rules]
    end
    
    subgraph "Infrastructure Layer"
        RepoImpl[Eloquent Repositories]
        ExtService[External APIs (FCM, Payment)]
        Cache[Redis / Cache]
        DB[(PostgreSQL)]
    end
    
    Presentation --> Service
    Service --> Domain
    Service --> Contracts
    Contracts -.-> RepoImpl
    RepoImpl --> DB
    RepoImpl --> Cache
```

---

## 2. Struktur Modul & Tanggung Jawab

Sistem dibagi menjadi modul-modul berikut untuk mencegah *spaghetti code*:

### A. Core / IAM (Identity & Access Management)
*   **Tanggung Jawab**: Autentikasi (Sanctum/JWT), Role & Permission (RBAC), Multi-tenancy Scoping.
*   **Key Components**: `User`, `Role`, `School`, `TenantScope`.

### B. Attendance Engine (Jantung Sistem)
*   **Tanggung Jawab**: Memproses scan QR, validasi geolokasi, kalkulasi status kehadiran (tepat waktu/telat), manajemen izin/sakit.
*   **Key Components**: `AttendanceService`, `QrCodeGenerator`, `GeoFencingStrategy`.

### C. Academic Core
*   **Tanggung Jawab**: Data master pendidikan. Jadwal pelajaran, pembagian kelas, tahun ajaran.
*   **Key Components**: `Schedule`, `ClassRoom`, `Subject`, `AcademicYear`.

### D. Reporting & Analytics
*   **Tanggung Jawab**: Mengolah raw data absensi menjadi laporan rekapitulasi. Modul ini bekerja berat di *Read Operation*.
*   **Key Components**: `ReportGenerator`, `DashboardAggregator`.

---

## 3. Alur Request Absensi QR End-to-End

Berikut adalah alur *Critical Path* saat siswa melakukan scan QR. Proses ini harus **Atomic**, **Cepat**, dan **Aman** dari kecurangan.

### Flow Diagram

1.  **Request Masuk**
    *   **Endpoint**: `POST /api/v1/attendance/scan`
    *   **Payload**: `{ token: "encrypted_string", lat: -6.200, long: 106.816, device_fingerprint: "u-123" }`
    *   **Context**: User (Siswa) terautentikasi.

2.  **Layer Presentation (Controller)**
    *   Validasi input dasar (required fields, format koordinat).
    *   Memanggil `ScanAttendanceAction->execute($user, $dto)`.

3.  **Layer Application (Service/Action)**
    *   **Step 1: Decryption**: Dekripsi `token` untuk mendapatkan `schedule_id` dan `timestamp_expiry`.
    *   **Step 2: Security Validation**:
        *   *Expiry Check*: Apakah token sudah kadaluarsa (misal > 10 detik)?
        *   *Replay Attack Check*: Cek di Redis apakah token ini sudah pernah dipakai?
        *   *Geo-Check*: Hitung jarak Haversine antara `lat/long` siswa dengan koordinat Sekolah/Kelas. (Max 50-100m).
        *   *Device Lock*: Pastikan `device_fingerprint` cocok dengan device terdaftar siswa (opsional/strict mode).
    *   **Step 3: Business Logic**:
        *   Cek apakah siswa sudah absen sebelumnya untuk jadwal ini? (Idempotency).
        *   Tentukan status: `Present` atau `Late` berdasarkan aturan jam masuk sekolah.

4.  **Layer Infrastructure (Repository & DB)**
    *   **Atomic Transaction**:
        *   Insert ke tabel `attendances`.
        *   Log ke tabel `attendance_logs` (audit trail).
    *   **Cache Update**: Invalidate cache rekap harian guru terkait.

5.  **Post-Process (Async Events)**
    *   Fire Event: `StudentAttended`.
    *   **Listener 1 (Notification)**: Kirim Push Notification / WA ke Orang Tua ("Anak Anda telah tiba di sekolah Pukul 07:05").
    *   **Listener 2 (Analytics)**: Update counter dashboard realtime statistik sekolah.

---

## 4. Prinsip Clean Code yang Diterapkan

1.  **Dependency Injection**: Controller tidak boleh `new Service()`. Gunakan interface.
    *   *Bad*: `$service = new AttendanceService();`
    *   *Good*: `public function __construct(AttendanceServiceInterface $service)`
2.  **Thin Controllers, Fat Services**: Controller hanya mengurus HTTP (Request/Response). Logika "apakah siswa boleh absen" ada di Service.
3.  **Repository Pattern**: Query Eloquent (`User::where(...)->get()`) dibungkus dalam Repository. Ini memudahkan jika nanti database perlu di-tuning atau diganti sebagian dengan Redis.
4.  **DTO (Data Transfer Object)**: Mengirim data antar layer menggunakan Class/Object terstruktur, bukan Array asosiatif yang rawan typo.
