# 📈 Scalability Analysis & Growth Strategy (10x Growth)

## 📊 Overview
Dokumen ini menganalisis kesiapan arsitektur untuk menangani estimasi **5.000.000 record attendance/tahun** (10x current load).

---

## 1. Database scalability

### A. Partitioning
**Verdict:** ⚠️ **Recommended for Archival Strategy**
Tabel `attendances` akan tumbuh sangat cepat.
- **Current:** Single Table.
- **Risk:** Delete old data (maintenance) menjadi lambat dan mengunci tabel.
- **Solution:** Partitioning by Range (`YEAR(attendance_date)`).
  - Partition 2026, 2027, etc.
  - Query harian otomatis hanya scan partisi tahun berjalan (Partition Pruning).

### B. Index Optimization
**Verdict:** ❌ **Critical Update Needed**
Index saat ini mengandalkan Foreign Key. Perlu **Composite Indexes** untuk query spesifik:

1. **Dashboard Query:**
   ```sql
   CREATE INDEX idx_attendance_school_date_status 
   ON attendances (school_id, attendance_date, status);
   ```
   *Impact:* Query dashboard menjadi "Covering Index" (tidak perlu baca row data, cukup index).

2. **Duplicate Check & History:**
   ```sql
   CREATE INDEX idx_attendance_student_date 
   ON attendances (student_id, attendance_date);
   ```

### C. Summary Tables
**Verdict:** ✅ **Highly Recommended**
Menghitung `COUNT(*)` setiap refresh dashboard adalah pemborosan resource DB yang tidak perlu.
- **Feature:** `attendance_daily_summaries` table.
- **Columns:** `school_id`, `date`, `present`, `late`, `sick`, `alpha`.
- **Update Mechanism:**
  - *Real-time:* Increment via Model Observer.
  - *Async:* Recalculate job per 10 menit untuk akurasi data.

---

## 2. Application Scalability

### A. Locking Mechanism
**Verdict:** ✅ **Redis Lock is Sufficient**
Redis Atomic Lock (`SET NX`) sangat scalable dan ringan.
- **Limit:** Redis Single Thread mampu menangani 50k+ ops/detik.
- **Requirement:** Gunakan Redis dedicated (terpisah dari Cache umum jika traffic sangat tinggi).

### B. Queue Workers
**Verdict:** ⚠️ **Horizontal Scaling Needed**
Saat jam masuk sekolah (07:00 - 08:00), ribuan request akan membanjiri sistem secara bersamaan (Burst Traffic).
- **Strategy:** Priority Queues.
  - `queue:high`: Check-in processing (User expect fast response).
  - `queue:low`: Email notif, Push notif, Reports.
- **Tool:** Laravel Horizon (Auto-scaling based on queue depth).

---

## 3. Architecture Roadmap

### Phase 1: Optimization (Immediate)
1. Add Composite Indexes.
2. Implement Caching untuk Dashboard (TTL 5 menit).
3. Enable Opcache di PHP Production.

### Phase 2: Decoupling (Growth)
1. Implement Summary Tables.
2. Pindahkan Notifikasi ke Queue Worker.
3. Pisahkan Database Web & Database Reporting (Read Replica).

### Phase 3: Sharding (Massive Scale)
1. Jika 1 DB server tidak muat, lakukan **Tenant Sharding**.
   - DB Server 1: School ID 1-100.
   - DB Server 2: School ID 101-200.
   - Logic di App Layer (`School::getConnectionName()`).

---

## 4. Bottleneck Risk Assessment

| Component | Risk Level | Impact | Solution |
|-----------|------------|--------|----------|
| **DB CPU** | High | Dashboard slow load | Summary Table + Caching |
| **DB IOPS** | Medium | Check-in timeout | SSD + Write Optimization |
| **Web CPU** | Low | Application slow | Horizontal Scaling (Add Servers) |
| **Queue** | High | Notification delay | Add Workers + Horizon |

---

## 5. Conclusion
Sistem saat ini mampu menangani 2x-3x load. Untuk 10x load, **Wajib** menerapkan:
1. Composite Indexing.
2. Summary Table untuk Dashboard.
3. Asynchronous Processing untuk non-critical task.
