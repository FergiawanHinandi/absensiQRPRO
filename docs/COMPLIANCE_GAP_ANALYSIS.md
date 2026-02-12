# Audit Kepatuhan Sovereign Cloud Indonesia - Hasil Gap Analysis

**Tanggal Audit:** 2026-02-12
**Target Kepatuhan:** PP 71/2019 (Penyelenggaraan Sistem dan Transaksi Elektronik), PDP (UU Perlindungan Data Pribadi), ISO 27001
**Lingkup:** AbsensiQR Pro SaaS Backend

## I. Ringkasan Eksekutif

Audit ini menemukan bahwa AbsensiQR Pro memiliki fondasi keamanan yang kuat dengan fokus pada **Data Integrity** (melalui `ImmutableSecurityLog`) dan **Disaster Recovery** otomatis. Namun, terdapat celah signifikan pada **Data Encryption At-Rest** untuk PII pengguna dan validasi teknis **Data Residency**.

| Area Audit | Skor Risiko | Status | Temuan Utama |
| :--- | :---: | :---: | :--- |
| **A. Data Residency** | 🟠 Tinggi | Partial | Tidak ada validasi teknis region bucket S3 (harus `ap-southeast-3`). |
| **B. Encryption** | 🔴 Kritis | Fail | PII sensitif (NIK/NISN, Phone, Alamat) tersimpan *plain-text*. SSL DB `prefer` (bukan `verify-full`). |
| **C. Access Control** | 🟢 Rendah | Pass | Multi-tenancy isolation via `SchoolScope` aktif. Token hardening solid. |
| **D. Audit Trail** | 🟢 Rendah | Pass | Implementasi Blockchain-style hash chain pada log sangat baik. |
| **E. Disaster Recovery** | 🟢 Rendah | Pass | Automated DR testing mingguan berjalan dengan validasi integritas data. |

---

## II. Detail Temuan & Rekomendasi

### A. Data Residency & Sovereignty
**Status:** ⚠️ Risiko Tinggi

1.  **Temuan:** Konfigurasi `filesystems.php` menggunakan `env('AWS_DEFAULT_REGION')` tanpa validasi.
    *   **Risiko:** Developer bisa salah konfigurasi ke region non-Indonesia (misal: `us-east-1`), melanggar regulasi lokalisasi data publik.
    *   **Rekomendasi:** Hardcode atau validasi `ap-southeast-3` (Jakarta) pada level aplikasi/CI pipeline.

2.  **Temuan:** Geo-fencing penyimpanan data statis belum diterapkan secara teknis.
    *   **Rekomendasi:** Implementasi Bucket Policy yang menolak `PutObject` jika IP uploader bukan dari IP Indonesia/VPN korporat.

### B. Access Control & Isolation (RBAC)
**Status:** ✅ Aman

1.  **Temuan:** `SchoolScope` diterapkan global untuk user non-SuperAdmin.
    *   **Kekuatan:** Mencegah kebocoran data antar sekolah (horizontal privilege escalation).
    *   **Catatan:** Pastikan scope ini juga diterapkan saat `creating` data untuk mencegah penyisipan cross-tenant.

2.  **Temuan:** Token Hardening (Device Binding).
    *   **Kekuatan:** `TokenHardeningService` mengikat token ke fingerprint device dan IP negara, mencegah session hijacking.

### C. Encryption At-Rest & In-Transit
**Status:** ❌ Kritis (Perlu Perbaikan Segera)

1.  **Temuan:** Kolom PII Sensitif Tidak Dienkripsi di App-Level.
    *   **Model:** `UserProfile` (`nisn`, `phone`, `address`, `birth_date`).
    *   **Masalah:** Data dapat dibaca langsung oleh admin database atau jika dump bocor. Hanya `StudentCard.qr_token_encrypted` yang aman.
    *   **Rekomendasi:** Gunakan Laravel Attribute Casting `encrypted` untuk kolom-kolom ini.

2.  **Temuan:** Koneksi Database SSL Mode `prefer`.
    *   **Masalah:** Rentan terhadap downgrade attack (MITM) di dalam jaringan internal cloud.
    *   **Rekomendasi:** Ubah `DB_SSLMODE` menjadi `verify-full` atau minimal `require` di environment produksi.

### D. Audit Trail & Integrity
**Status:** ✅ Sangat Baik

1.  **Temuan:** `ImmutableSecurityLog` menggunakan Hash Chaining.
    *   **Kekuatan:** Perubahan historis akan merusak rantai hash, membuat tampering terdeteksi otomatis. Ini memenuhi standar pembuktian hukum.
    *   **Rekomendasi:** Lakukan backup harian hash terakhir ke penyimpanan WORM (Write Once Read Many) terpisah (misal: S3 Object Lock).

### E. Disaster Recovery (DR)
**Status:** ✅ Sangat Baik

1.  **Temuan:** `DisasterRecoveryTestService` melakukan restore test otomatis.
    *   **Kekuatan:** Memastikan backup *valid* dan bisa di-restore, bukan hanya file ada.
    *   **Rekomendasi:** Tambahkan simulasi RTO (waktu restore nyata) ke dalam laporan mingguan.

---

## III. Implementation Roadmap

### Phase 1: Immediate Remediation (Minggu 1)
- [ ] **Encryption:** Implementasi `encrypted` cast pada `UserProfile` (`nisn`, `phone`, `address`).
- [ ] **DB Security:** Ubah config DB production ke `sslmode=require`.
- [ ] **Residency Check:** Tambahkan test di CI/CD yang menolak deploy jika region != `ap-southeast-3`.

### Phase 2: Strengthening (Minggu 2-3)
- [ ] **Policy Enforcement:** Buat middleware untuk menolak akses admin dari IP luar Indonesia (Geo-IP blocking).
- [ ] **WORM Storage:** Aktifkan S3 Object Lock untuk backup audit logs.

### Phase 3: Continuous Monitoring (Ongoing)
- [ ] Jalankan script `audit:compliance` setiap hari.
- [ ] Review laporan DR mingguan.
