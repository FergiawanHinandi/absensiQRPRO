# absensiQRPRO - User Guide

## Untuk Administrator (Website)

### Mengakses Dashboard

1. Buka browser dan akses:
   ```
   http://localhost/absensiQRPRO/website/frontend/index.html
   ```

2. Dashboard menampilkan:
   - Total siswa
   - Jumlah siswa hadir hari ini
   - Siswa tidak hadir
   - Persentase kehadiran

### Mengelola Data Siswa

1. **Melihat Data Siswa:**
   - Klik menu "Data Siswa"
   - Daftar semua siswa akan ditampilkan
   - Gunakan pencarian untuk mencari siswa tertentu
   - Filter berdasarkan kelas

2. **Generate QR Code:**
   - Di halaman Data Siswa
   - Klik tombol "Tampilkan QR" pada siswa yang diinginkan
   - QR code akan muncul di tabel
   - Gunakan fitur Print untuk mencetak kartu pelajar

3. **Mencetak Kartu Pelajar:**
   - Klik tombol "Cetak QR Code" di pojok kanan atas
   - Browser akan membuka dialog print
   - Pilih printer dan pengaturan yang diinginkan
   - Cetak kartu pelajar dengan QR code

### Melihat Data Absensi

1. **Absensi Harian:**
   - Klik menu "Absensi"
   - Pilih tanggal yang diinginkan
   - Klik "Tampilkan"
   - Data absensi akan ditampilkan

2. **Informasi yang Ditampilkan:**
   - NIS siswa
   - Nama siswa
   - Kelas
   - Waktu absensi
   - Status (Hadir/Terlambat/Tidak Hadir/Izin)
   - Nama guru yang mencatat
   - Catatan tambahan

### Membaca Statistik

1. **Ringkasan Harian:**
   - Total Hadir: Siswa yang hadir tepat waktu
   - Terlambat: Siswa yang terlambat
   - Tidak Hadir: Siswa yang tidak masuk
   - Izin: Siswa yang izin dengan keterangan

## Untuk Guru (Mobile App)

### Login ke Aplikasi

1. Buka aplikasi absensiQRPRO
2. Masukkan email dan password
3. Klik tombol "Login"

**Kredensial Demo:**
- Email: `budi@school.com`
- Password: `teacher123`

### Home Screen

Setelah login, Anda akan melihat:
- Nama Anda di bagian atas
- Total siswa di sekolah
- Jumlah siswa yang sudah absen hari ini
- Menu utama:
  - Scan QR Code
  - Riwayat Absensi
  - Logout

### Melakukan Absensi

1. **Scan QR Code:**
   - Tap menu "Scan QR Code"
   - Izinkan akses kamera jika diminta
   - Arahkan kamera ke QR code pada kartu pelajar siswa
   - Tunggu hingga QR code terdeteksi

2. **Konfirmasi Data Siswa:**
   - Setelah scan berhasil, muncul dialog konfirmasi
   - Periksa data siswa:
     - Nama
     - NIS
     - Kelas
   - Pastikan data sudah benar

3. **Pilih Status Kehadiran:**
   - **Ya, Hadir**: Siswa hadir tepat waktu
   - **Terlambat**: Siswa terlambat masuk
   - **Batal**: Jika ingin membatalkan

4. **Konfirmasi:**
   - Setelah memilih status, absensi akan tercatat
   - Muncul notifikasi "Absensi berhasil dicatat"
   - Klik OK untuk melanjutkan scan siswa lain

### Melihat Riwayat Absensi

1. **Akses Riwayat:**
   - Dari home screen, tap "Riwayat Absensi"
   - Secara default menampilkan data hari ini

2. **Informasi yang Ditampilkan:**
   - Header menunjukkan tanggal dan total siswa
   - Daftar siswa dengan:
     - Nama siswa
     - Status (dengan warna badge)
     - NIS
     - Kelas
     - Waktu absensi
     - Nama guru
     - Catatan (jika ada)

3. **Membaca Status:**
   - 🟢 **Hijau** (Hadir): Siswa hadir
   - 🟡 **Kuning** (Terlambat): Siswa terlambat
   - 🔴 **Merah** (Tidak Hadir): Siswa tidak hadir
   - 🔵 **Biru** (Izin): Siswa izin

### Tips Penggunaan

1. **Pencahayaan:**
   - Pastikan area cukup terang
   - Hindari scan di area gelap
   - Jangan gunakan flash kecuali perlu

2. **Jarak Scan:**
   - Jaga jarak optimal 15-30 cm dari QR code
   - Pastikan QR code terlihat jelas di layar
   - Hindari gerakan berlebihan saat scan

3. **Scan Berulang:**
   - Setelah berhasil scan satu siswa, langsung bisa scan siswa berikutnya
   - Tidak perlu keluar dari halaman scanner

4. **Koneksi Internet:**
   - Pastikan terhubung ke internet
   - Absensi akan langsung tersimpan ke server
   - Jika offline, akan ada notifikasi error

### Troubleshooting

**Problem:** QR Code tidak terdeteksi
- **Solusi:**
  - Pastikan kamera tidak tertutup
  - Coba adjust jarak ke QR code
  - Pastikan QR code tidak rusak atau kotor

**Problem:** Error "Siswa tidak ditemukan"
- **Solusi:**
  - Pastikan QR code adalah kartu pelajar yang valid
  - Cek dengan admin apakah data siswa sudah terdaftar

**Problem:** Error "Student may have already been marked today"
- **Solusi:**
  - Siswa sudah melakukan absensi hari ini
  - Cek di Riwayat Absensi untuk konfirmasi

**Problem:** Tidak bisa login
- **Solusi:**
  - Periksa koneksi internet
  - Pastikan email dan password benar
  - Hubungi admin jika masih bermasalah

### Logout

1. Dari home screen, scroll ke bawah
2. Tap tombol "Logout" dengan icon 🚪
3. Konfirmasi dengan tap "Ya"
4. Anda akan kembali ke halaman login

## FAQ (Frequently Asked Questions)

**Q: Apakah bisa scan multiple siswa sekaligus?**
A: Tidak, sistem dirancang untuk scan satu per satu untuk akurasi.

**Q: Apakah bisa ubah status absensi setelah tercatat?**
A: Saat ini tidak tersedia di mobile app. Hubungi admin untuk perubahan.

**Q: Berapa lama QR code valid?**
A: QR code pada kartu pelajar berlaku sepanjang siswa masih terdaftar.

**Q: Apakah bisa absen untuk hari sebelumnya?**
A: Tidak, sistem hanya mencatat absensi untuk hari ini.

**Q: Bagaimana jika siswa lupa bawa kartu?**
A: Hubungi admin untuk solusi alternatif (manual entry).

**Q: Apakah data tersimpan offline?**
A: Session login tersimpan offline, tapi absensi harus online.

---

Untuk pertanyaan lebih lanjut, hubungi administrator sekolah Anda.
