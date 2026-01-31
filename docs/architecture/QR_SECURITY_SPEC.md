# QR Security Specification (Secure Presence Protocol)

Dokumen ini menjelaskan mekanisme keamanan untuk mencegah pemalsuan dan penyalahgunaan QR Code pada sistem AbsensiQRPro.

## 1. Ancaman & Mitigasi

| Ancaman | Deskripsi | Mitigasi |
| :--- | :--- | :--- |
| **Screencap Sharing** | Siswa memfoto QR dan mengirim via WA ke teman di rumah. | **Dynamic QR (TOTP-like)**: QR berubah setiap 15 detik. Foto akan basi saat diterima teman. |
| **Absen Joki** | Teman login pakai akun siswa lain di HP-nya. | **Device Lock**: Akun siswa terkunci pada 1 `UUID` perangkat keras (IMEI/AndroidID). Reset butuh Admin. |
| **Fake GPS** | Siswa menggunakan aplikasi Mock Location. | **Server-Side Geofencing**: Validasi jarak Haversine di Backend, toleransi maksimal 50-100m. |
| **Replay Attack** | Hacker merekam packet network QR yang valid untuk dikirim ulang. | **Token Jitter**: Backend menolak token dengan timestamp > 30 detik yang lalu. |

---

## 2. Secure QR Payload Structure (Long-Term Schema)

Payload QR dirancang dengan prinsip **Compact & Extensible**.
Format String Akhir: `v{VERSI}|{IV_BASE64}|{ENCRYPTED_DATA_BASE64}`.

### A. Decrypted JSON Schema (Versi 1)
Struktur data di dalam enkripsi. Gunakan nama field pendek untuk menghemat ukuran QR (scan lebih cepat).

```json
{
  "v": 1,                     // Protocol Version (Future proofing)
  "t": "cls",                 // Type: 'cls' (Kelas), 'gate' (Gerbang), 'evt' (Event)
  "id": 105,                  // Context ID (ScheduleID atau EventID)
  "ts": 1705763000,           // Timestamp Generate (Unix Epoch)
  "exp": 1705763030,          // Expires At (Unix Epoch). Explicit expiry is safer.
  "geo": {                    // Location Context (Optional/Dynamic)
    "lat": -6.200123,         // Latitude Guru/Titik Absen
    "lng": 106.816123,        // Longitude Guru/Titik Absen
    "rad": 50                 // Radius toleransi (meter)
  },
  "n": "a7x9"                 // Nonce (Random 4-8 chars -> Anti Replay)
}
```

### B. API Request Payload (Mobile to Backend)
Struktur JSON yang dikirim aplikasi mobile ke endpoint `/scan`.

```json
{
  "qr_payload": "v1|iv_string|encrypted_string",  // Raw QR String
  "client_ctx": {                                 // Konteks Perangkat Siswa
    "ts": 1705763010,                             // Waktu HP Siswa (Deteksi jam ngaco)
    "loc": {
      "lat": -6.200200,                           // GPS Siswa
      "lng": 106.816200,
      "acc": 12.4,                                // Akurasi GPS (meter). Reject jika > 50m.
      "mock": false                               // Is Mock Location detected? (Dari Native Code)
    },
    "dev": {
      "id": "hw-id-hash-xxx",                     // Device Fingerprint
      "model": "Samsung SM-A505F",                // Model HP (Forensik)
      "os": "Android 13",
      "ver": "1.2.5"                              // Versi Aplikasi
    }
  }
}
```

---

## 3. Strategi Migrasi (Future Proofing)
1.  **Versi Protokol (`v`)**: Backend selalu cek `v` pertama kali. Jika rilis fitur baru (misal `v2` dengan kompresi gzip), backend bisa handle `v1` dan `v2` secara paralel.
2.  **Tipe Absensi (`t`)**: `cls` (default) masuk ke tabel `attendances`. `gate` bisa masuk ke `gate_logs`. `evt` masuk ke `event_attendances`. Satu endpoint scan bisa routing ke logic berbeda.
3.  **Dynamic Geo (`geo`)**: Jika field `geo` ada di QR, validasi pake koordinat itu. Jika tidak, fallback ke koordinat statis Sekolah di database. Ini memungkinkan "Absensi Kegiatan Luar Sekolah" (misal Study Tour).

## 4. Client Side (Mobile App) Logic

1.  **Get Location High Accuracy**: Jangan izinkan scan jika akurasi GPS > 20 meter.
2.  **Mock Location Detection**: Gunakan plugin native untuk mendeteksi `IsMockLocation` flag.
3.  **One-Time Scan**: Setelah berhasil scan, blokir tombol scan selama 5 menit untuk mencegah double click/spam.

---

## 5. Rotasi Key & Secret

*   `APP_KEY` Laravel digunakan untuk enkripsi QR.
*   Jangan pernah hardcode key di source code aplikasi mobile.
*   Aplikasi mobile hanya bertugas **mengirim** string QR apa adanya ke backend. Mobile **TIDAK PERLU** bisa mendekripsi QR. (Security by Isolation).
