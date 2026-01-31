# WHATSAPP NOTIFICATION SETUP

## Prerequisites
1. WhatsApp Business API Account ATAU
2. Third-party WhatsApp Gateway Provider (Recommended)

---

## Recommended Providers

### 1. **Fonnte** (Indonesia)
- Website: https://fonnte.com
- Pricing: Rp 150.000/bulan (unlimited messages)
- Setup: 5 menit
- Support: WhatsApp & Email

### 2. **Wablas**
- Website: https://wablas.com
- Pricing: Pay per message
- Easy integration

### 3. **WhatsApp Cloud API** (Official)
- Website: https://developers.facebook.com/docs/whatsapp
- Pricing: Gratis hingga 1000 conversation/month
- Setup: Lebih kompleks

---

## Setup dengan Fonnte (Contoh)

### Step 1: Daftar Akun
1. Kunjungi https://fonnte.com
2. Daftar akun baru
3. Verifikasi nomor WhatsApp

### Step 2: Dapatkan API Key
1. Login ke dashboard Fonnte
2. Menu "API" → Copy API Token
3. Simpan token

### Step 3: Konfigurasi di AbsensiQR Pro

Edit file `.env` di folder `backend`:

```env
# WhatsApp Gateway Configuration
WHATSAPP_ENABLED=true
WHATSAPP_API_URL=https://api.fonnte.com
WHATSAPP_API_KEY=your_fonnte_token_here
```

### Step 4: Format API Fonnte

Endpoint Fonnte:
```
POST https://api.fonnte.com/send
```

Headers:
```
Authorization: your_token_here
```

Body (JSON):
```json
{
  "target": "628123456789",
  "message": "Pesan anda disini",
  "countryCode": "62"
}
```

### Step 5: Update WhatsAppService.php

Jika menggunakan Fonnte, sesuaikan `app/Services/WhatsAppService.php`:

```php
$response = Http::withHeaders([
    'Authorization' => $this->apiKey, // Fonnte tidak pakai "Bearer"
])->post($this->apiUrl . '/send', [
    'target' => $phoneNumber,
    'message' => $message,
    'countryCode' => '62', // Indonesia
]);
```

---

## Testing Notifikasi

### Manual Test via Tinker

```bash
php artisan tinker
```

```php
$service = new \App\Services\WhatsAppService();
$service->sendNotification('628123456789', 'Test dari AbsensiQR Pro!');
```

### Auto Send on Attendance

Tambahkan di `AttendanceController.php` setelah attendance berhasil:

```php
use App\Services\WhatsAppService;

public function checkIn(Request $request)
{
    // ... existing logic ...
    
    $attendance = Attendance::create([...]);
    
    // Send WhatsApp Notification
    if (config('services.whatsapp.enabled')) {
        $whatsapp = new WhatsAppService();
        $whatsapp->sendAttendanceNotification($student, $attendance);
    }
    
    return response()->json([...]);
}
```

---

## Message Templates

### Template 1: Siswa Hadir
```
*[AbsensiQR Pro - {Nama Sekolah}]*

Kepada Yth. Orang Tua/Wali
{Nama Orang Tua}

Kami informasikan bahwa:
Nama: *{Nama Siswa}*
Kelas: {Kelas}
Status: *HADIR*
Waktu: {Jam:Menit}
Tanggal: {Tanggal}

Terima kasih atas perhatian Anda.
Sistem AbsensiQR Pro
```

### Template 2: Siswa Tidak Hadir
```
*[⚠️ PERINGATAN ABSENSI]*

Kepada Yth. Orang Tua/Wali
{Nama Orang Tua}

Kami informasikan bahwa:
Nama: *{Nama Siswa}*
Kelas: {Kelas}
Status: *TIDAK HADIR (ALPHA)*
Tanggal: {Tanggal}

Mohon segera hubungi wali kelas untuk konfirmasi.

Wassalam,
Sistem AbsensiQR Pro
```

---

## Bulk Notification untuk Pengumuman

### API Endpoint (Custom)

`POST /api/v1/admin/send-bulk-whatsapp`

Request Body:
```json
{
  "recipients": [
    {
      "phone": "628123456789",
      "name": "Orang Tua 1"
    },
    {
      "phone": "628123456780",
      "name": "Orang Tua 2"
    }
  ],
  "message": "Pengumuman: Besok libur karena hari raya."
}
```

Response:
```json
{
  "success": true,
  "data": {
    "total": 2,
    "sent": 2,
    "failed": 0
  }
}
```

---

## Best Practices

1. **Rate Limiting**: Jangan kirim terlalu banyak pesan sekaligus (max 20 msg/menit)
2. **Opt-in**: Pastikan orang tua setuju menerima notifikasi WA
3. **Template Approval**: Jika pakai official API, template harus diapprove dulu
4. **Logging**: Log semua pengiriman untuk audit trail
5. **Error Handling**: Handle kasus nomor invalid atau tidak terdaftar WA

---

## Troubleshooting

### Error: "Phone number not registered"
- Pastikan nomor dalam format internasional (62xxx)
- Cek apakah nomor aktif di WhatsApp

### Error: "API Key invalid"
- Cek kembali token di dashboard provider
- Pastikan tidak ada spasi atau karakter tersembunyi

### Pesan tidak terkirim tapi tidak ada error
- Cek quota/balance di provider
- Lihat log di dashboard provider

---

## Cost Estimation

### Fonnte Example
- Paket: Rp 150.000/bulan (unlimited)
- Untuk 500 siswa × 20 hari sekolah = 10.000 pesan/bulan
- Biaya: **GRATIS** (dalam paket unlimited)

### Cloud API (Official)
- Free tier: 1000 conversations/month
- Untuk 500 siswa × 2 pesan/hari = 1000 conversation
- Biaya: **GRATIS** (dalam free tier)

---

## Further Reading

- Fonnte Docs: https://docs.fonnte.com
- WhatsApp Cloud API: https://developers.facebook.com/docs/whatsapp/cloud-api
- Laravel HTTP Client: https://laravel.com/docs/http-client

---

**Last Updated**: 2026-01-23
