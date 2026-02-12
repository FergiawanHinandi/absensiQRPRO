# Template Audit Kepatuhan Data Pribadi & Keamanan Informasi (Sovereign Cloud)

**Dokumen Kontrol Internal**
**Versi:** 1.0
**Tanggal:** [Tanggal Hari Ini]
**Auditor:** [Nama Auditor]

---

## 1. Identitas Sistem Elektronik
*   **Nama Sistem:** AbsensiQR Pro
*   **Kategori:** Strategis / Tinggi / Rendah
*   **Lokasi Data Center:** [Sebutkan Lokasi e.g. Jakarta, Cibinong]
*   **Penyedia Cloud:** [AWS Region Jakarta / Google Cloud Jakarta / Alibaba Cloud]

## 2. Inventaris Data Pribadi (PII Inventory)
*Sesuai UU Perlindungan Data Pribadi (PDP)*

| Jenis Data | Kategori (Umum/Spesifik) | Lokasi Penyimpanan | Enkripsi (Ya/Tidak) | Retensi (Tahun) |
| :--- | :--- | :--- | :--- | :--- |
| Nama Lengkap | Umum | DB:users | [ ] | 5 |
| NIK / NISN | Spesifik | DB:user_profiles | [ ] | 5 |
| Biometrik (Wajah) | Spesifik | DB:embeddings | [ ] | Selama Aktif |
| Lokasi (GPS) | Spesifik | DB:security_events | [ ] | 1 |
| Data Keuangan | Spesifik | DB:payments | [ ] | 10 |

## 3. Checklist Kontrol Keamanan (ISO 27001 / SOC 2)

### A. Kontrol Akses (Access Control)
- [ ] Multi-Factor Authentication (MFA) diaktifkan untuk semua Admin.
- [ ] Review akses pengguna dilakukan setiap 3 bulan.
- [ ] Prinsip *Least Privilege* diterapkan pada Service Account.

### B. Keamanan Jaringan & Data
- [ ] Enkripsi data *in-transit* menggunakan TLS 1.3.
- [ ] Enkripsi data *at-rest* menggunakan AES-256 (Database & Object Storage).
- [ ] Kunci enkripsi (KMS) dikelola dan dirotasi setiap tahun.
- [ ] Penetration Testing dilakukan minimal 1x setahun.

### C. Kelangsungan Bisnis (BCP/DR)
- [ ] Backup database harian (Full) dan per jam (Incremental).
- [ ] Uji coba restore (Disaster Recovery Drill) dilakukan per kuartal.
- [ ] RTO (Recovery Time Objective): < 4 Jam.
- [ ] RPO (Recovery Point Objective): < 15 Menit.

## 4. Temuan dan Rencana Perbaikan (CAPA)

| No | Temuan (Non-Compliance) | Risiko | Rencana Tindakan | Pemilik (PIC) | Target Selesai |
| :--- | :--- | :--- | :--- | :--- | :--- |
| 1 | Database SSL Mode 'prefer' | MITM Attack | Ubah config ke 'verify-full' | DevOps | 1 Minggu |
| 2 | PII Address Plaintext | Kebocoran Data | Enkripsi kolom Address | Backend | 2 Minggu |

---

**Persetujuan Manajemen:**

Tanda Tangan: ____________________
Nama: ____________________
Jabatan: CISO / CTO
Tanggal: ____________________
