# 🚀 Infrastructure Readiness Plan: 10x Growth Strategy

**Target Capacity:** 5,000,000 - 10,000,000 Attendance Records / Year
**Peak Load:** 2,000 - 5,000 Check-ins / Minute (07:00 - 07:30 AM)

---

## 1. 🏗️ New Infrastructure Architecture

```mermaid
graph TD
    User[Mobile/Web Users]
    LB[Load Balancer (Nginx/AWS ALB)]
    
    subgraph "Application Layer (Auto-Scaling)"
        App1[App Server 1]
        App2[App Server 2]
        AppWorker[Worker Nodes (Horizon)]
    end
    
    subgraph "Data Layer"
        Redis[(Redis Cluster)]
        DB_Master[(DB Primary - WRITE)]
        DB_Replica[(DB Replica - READ)]
    end
    
    subgraph "Storage"
        S3[Object Storage (Images)]
    end

    User -->|HTTPS| LB
    LB --> App1 & App2
    App1 & App2 -->|Cache/Lock/Session| Redis
    App1 & App2 -->|Write Check-in| DB_Master
    App1 & App2 -->|Read Dashboard| DB_Replica
    
    App1 & App2 -.->|Dispatch Jobs| Redis
    Redis -.->|Process Jobs| AppWorker
    AppWorker -->|Update Summary| DB_Master
```

### Key Changes:
1.  **Read/Write Splitting:** Memisahkan beban query Dashboard (berat) dari Check-in (kritis).
2.  **Summary Tables:** Menghilangkan query agregasi `COUNT(*)` realtime.
3.  **Partitioning:** Memecah tabel `attendances` per tahun untuk kemudahan maintenance.

---

## 2. 🔍 Deep Dive Analysis

### A. Database Partitioning (Attendance Table)
**Verdict:** ✅ **REQUIRED for Maintenance**
- **Why?** Dengan 10M record, operasi `DELETE` (archiving) atau `ALTER TABLE` akan mengunci database terlalu lama.
- **Strategy:** Partition by Range (Year).
  ```sql
  ALTER TABLE attendances PARTITION BY RANGE (YEAR(attendance_date)) (
      PARTITION p2025 VALUES LESS THAN (2026),
      PARTITION p2026 VALUES LESS THAN (2027),
      PARTITION p_future VALUES LESS THAN MAXVALUE
  );
  ```

### B. Read Replica (Database Split)
**Verdict:** ✅ **REQUIRED for Dashboard Performance**
- **Why?** Query dashboard guru/admin (kompleks) tidak boleh memperlambat proses Check-in siswa.
- **Config:** Laravel mendukung native Read/Write split.

### C. Redis Lock Scalability
**Verdict:** 🟢 **SUFFICIENT (No Change Needed)**
- **Analysis:** Redis mampu menangani ~50k ops/sec. Load 5k check-in/menit (~83 req/sec) sangat kecil bagi Redis.
- **Note:** Pastikan Redis dikonfigurasi dengan *eviction policy* yang tepat (`volatile-lru`).

### D. Queue Worker Auto-Scaling
**Verdict:** ✅ **REQUIRED (Laravel Horizon)**
- **Why?** Burst traffic pagi hari (07:00) akan membanjiri antrian notifikasi.
- **Config:** Gunakan `balance: auto` pada Horizon.

### E. Dashboard Summary Table
**Verdict:** 🚨 **CRITICAL / MANDATORY**
- **Why?** Menghitung `COUNT(*)` pada 10M baris setiap kali dashboard dibuka akan mematikan DB CPU.
- **Implementation:** Tabel `attendance_summaries` yang di-update via event `AttendanceCheckedIn`.

---

## 3. ⚙️ Configuration Changes

### A. Database Config (`config/database.php`)
Setup Read/Write split:
```php
'mysql' => [
    'read' => [
        'host' => [env('DB_READ_HOST_1'), env('DB_READ_HOST_2')],
    ],
    'write' => [
        'host' => [env('DB_HOST')],
    ],
    'sticky' => true, // Immediate consistency for same request
    // ...
],
```

### B. Horizon Config (`config/horizon.php`)
Setup auto-scaling policies:
```php
'production' => [
    'attendance-checkin' => [
        'connection' => 'redis',
        'queue' => ['high', 'default'],
        'balance' => 'auto',
        'minProcesses' => 5,
        'maxProcesses' => 20, // Scale up during morning rush
        'balanceMaxShift' => 1,
        'balanceCooldown' => 3,
    ],
],
```

---

## 4. ⚠️ Risks of Staying with Current Architecture

Jika tetap menggunakan Single DB, No Partition, No Summary Table pada volume 5M-10M record:

1.  **Dashboard Timeout (504):** Query agregasi akan memakan waktu > 5 detik, menyebabkan timeout pada Nginx/PHP.
2.  **Check-in Failure (Morning locks):** Database yang sibuk dengan query dashboard akan mengunci row/table, menyebabkan check-in siswa gagal (Connection Timeout).
3.  **Maintenance Nightmare:** Backup database akan memakan waktu berjam-jam, restore akan memakan waktu seharian. Schema change hampir mustahil dilakukan tanpa downtime panjang.
4.  **Worker Lag:** Notifikasi "Anak Anda sudah sampai" mungkin baru terkirim 2 jam kemudian.

---

## 5. Capacity Estimation (1 Year Data)

| Metric | Value | Size Estimation |
|--------|-------|-----------------|
| **Total Check-ins** | 5,000,000 | ~2.5 GB (Data + Index) |
| **Images (S3)** | 5,000,000 | ~500 GB (@100KB thumbnail) |
| **Audit Logs** | 15,000,000 | ~5 GB |
| **Redis Keys** | ~50,000 (Daily) | ~500 MB (RAM) |

**Recommendation:**
- DB Server: 16GB RAM, 4 vCPU (Primary), 8GB RAM (Replica).
- Redis: 4GB RAM.
- App Server: 2x Nodes (4GB RAM, 2 vCPU each).
