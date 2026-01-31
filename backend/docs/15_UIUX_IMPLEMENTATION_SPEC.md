# 📐 Complete UI/UX Implementation Spec

> **Purpose:** Implementation-ready wireframes, flows, and copy  
> **Target:** Designers OR direct implementation  
> **Language:** Indonesian (user-facing text)

---

## 📋 TABLE OF CONTENTS

1. **Text Wireframes** (Web & Mobile)
2. **User Flows** (Per Role with Time Targets)
3. **Age-Specific UX** (SD vs SMP vs SMA)
4. **UI Copywriting Guide** (Buttons, Messages, Errors)
5. **UX Test Scenarios** (Edge Cases & User Stories)

---

# 1️⃣ TEXT-BASED WIREFRAMES

## 🖥️ WEB - TEACHER INTERFACE

### Page 1: Teacher Dashboard (P0 - CRITICAL)

```
╔═══════════════════════════════════════════════════════════════╗
║ HEADER                                                        ║
║ [📚 AbsensiQR]          Dashboard Guru      [👤 Pak Budi ▼] ║
╠═══════════════════════════════════════════════════════════════╣
║ SIDEBAR    │ MAIN CONTENT                                    ║
║            │                                                  ║
║ 📊 Dashboard│ ┌─────────────────────────────────────────────┐║
║ 📝 Absensi │ │ Jadwal Hari Ini - Rabu, 19 Jan 2026        │║
║ 📱 QR Code │ └─────────────────────────────────────────────┘║
║ 📖 Riwayat │                                                  ║
║ 🚪 Keluar  │ ┌─────────────────────────────────────────────┐║
║            │ │ MATEMATIKA - VII A                          │║
║            │ │ 07:00 - 08:30 | Ruang 12                   │║
║            │ │                                             │║
║            │ │ Status: 📊 23/30 siswa sudah absen          │║
║            │ │                                             │║
║            │ │ [  GENERATE QR  ]  [  LIHAT ABSENSI  ]     │║
║            │ └─────────────────────────────────────────────┘║
║            │                                                  ║
║            │ ┌─────────────────────────────────────────────┐║
║            │ │ BAHASA INDONESIA - VII B                    │║
║            │ │ 09:00 - 10:30 | Ruang 8                    │║
║            │ │                                             │║
║            │ │ Status: ⏰ Akan dimulai                     │║
║            │ │                                             │║
║            │ │ [  GENERATE QR  ]  [  LIHAT ABSENSI  ]     │║
║            │ └─────────────────────────────────────────────┘║
╚═══════════════════════════════════════════════════════════════╝
```

**UX Goals:**
- ✅ Teacher sees schedule immediately
- ✅ One-click access to critical actions
- ✅ Real-time attendance count visible
- ✅ No scrolling needed for today

---

### Page 2: Generate QR Code

```
╔═══════════════════════════════════════════════════════════════╗
║ Generate QR Code Absensi                                      ║
╠═══════════════════════════════════════════════════════════════╣
║                                                               ║
║  Kelas     : VII A                                            ║
║  Mata Pelajaran : Matematika                                  ║
║  Waktu     : Rabu, 19 Jan 2026 | 07:00 - 08:30               ║
║                                                               ║
║  ┌─────────────────────────────────────────────────────────┐ ║
║  │ Pengaturan Otomatis                                     │ ║
║  │                                                         │ ║
║  │ ✅ Validasi Lokasi: Aktif (dalam radius 100m)          │ ║
║  │ ✅ Masa Berlaku: 10 menit                               │ ║
║  │ ✅ Maksimal Scan: 30 siswa                              │ ║
║  │                                                         │ ║
║  │ [💡 Pengaturan Lanjut ▼] (collapsed)                   │ ║
║  └─────────────────────────────────────────────────────────┘ ║
║                                                               ║
║              [     GENERATE QR CODE SEKARANG     ]            ║
║                                                               ║
║  ── Setelah Generate ──                                       ║
║                                                               ║
║  ┌─────────────────────────────────────────────────────────┐ ║
║  │                                                         │ ║
║  │              [ QR CODE BESAR 300x300px ]                │ ║
║  │                                                         │ ║
║  │         Scan sebelum: 07:10 WIB                         │ ║
║  │         Siswa terse timbangan: 15/30                              │ ║
║  │                                                         │ ║
║  └─────────────────────────────────────────────────────────┘ ║
║                                                               ║
║         [  REFRESH QR  ]         [  TUTUP QR  ]              ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
```

**Smart Defaults:**
- ✅ No manual parameter input required
- ✅ Auto-calculate based on class size
- ✅ Advanced settings hidden by default

---

### Page 3: Class Attendance View

```
╔═══════════════════════════════════════════════════════════════╗
║ Absensi - Matematika VII A                                    ║
║ Rabu, 19 Januari 2026                                         ║
╠═══════════════════════════════════════════════════════════════╣
║                                                               ║
║  📊 Ringkasan:                                                ║
║  [🟢 Hadir: 23] [🟡 Terlambat: 2] [🔴 Belum Hadir: 5]       ║
║                                                               ║
║  ┌─────────────────────────────────────────────────────────┐ ║
║  │ No │ Nama Siswa      │ Status      │ Waktu │ Aksi      │ ║
║  ├────┼─────────────────┼─────────────┼───────┼───────────┤ ║
║  │ 1  │ Ahmad Fauzi     │ 🟢 Hadir    │ 07:02 │    -      │ ║
║  │ 2  │ Budi Santoso    │ 🟡 Terlambat│ 07:15 │    -      │ ║
║  │ 3  │ Citra Dewi      │ 🔴 Belum    │   -   │ [Manual▼] │ ║
║  │ 4  │ Dina Permata    │ 🟢 Hadir    │ 07:03 │    -      │ ║
║  │ 5  │ Eka Prasetyo    │ 🔴 Belum    │   -   │ [Manual▼] │ ║
║  │... │ ...             │ ...         │ ...   │ ...       │ ║
║  └─────────────────────────────────────────────────────────┘ ║
║                                                               ║
║  [  EXPORT EXCEL  ]  [  CETAK  ]                             ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝

── Manual Input Modal (when click "Manual▼") ──

┌─────────────────────────────────────┐
│ Input Manual - Citra Dewi           │
├─────────────────────────────────────┤
│                                     │
│ Status: [ Sakit    ▼ ]              │
│         (Sakit/Izin/Alpa)           │
│                                     │
│ Catatan:                            │
│ [____________________________]      │
│                                     │
│ Lampiran: [📎 Upload (opsional)]   │
│                                     │
│     [  BATAL  ]    [  SIMPAN  ]    │
│                                     │
└─────────────────────────────────────┘
```

**Manual Input Rules:**
- ❌ Cannot set "Hadir" manually (QR only)
- ✅ Only: Sakit, Izin, Alpa
- ✅ Notes optional but recommended
- ✅ Attachment support (surat dokter)

---

## 📱 MOBILE - STUDENT INTERFACE

### Screen 1: Home / Dashboard

```
┌─────────────────────────────────────┐
│  [Status Bar]                       │
├─────────────────────────────────────┤
│                                     │
│  👋 Halo, Ahmad Fauzi               │
│  Kelas VII A                        │
│                                     │
│  ┌───────────────────────────────┐ │
│  │  Status Hari Ini              │ │
│  │                               │ │
│  │  ❌ Belum Absen                │ │
│  │                               │ │
│  │  Matematika - 07:00           │ │
│  │                               │ │
│  └───────────────────────────────┘ │
│                                     │
│  ┌─────────────────────────────────┐│
│  │                                  ││
│  │      [    📷 SCAN QR    ]        ││
│  │                                  ││
│  └─────────────────────────────────┘│
│                                     │
│  Jadwal Berikutnya:                 │
│  IPA - 08:30 - 09:30                │
│                                     │
├─────────────────────────────────────┤
│ [🏠 Home]  [📷 Scan]  [📋 Riwayat] │
└─────────────────────────────────────┘
```

**Key UX:**
- ✅ Student name prominent
- ✅ Today's status obvious
- ✅ Large scan button
- ✅ Next schedule visible

---

### Screen 2: Scan QR (CRITICAL)

```
┌─────────────────────────────────────┐
│  [Status Bar]                       │
├─────────────────────────────────────┤
│                                     │
│  ┌───────────────────────────────┐ │
│  │                               │ │
│  │                               │ │
│  │    [ CAMERA VIEWFINDER ]      │ │
│  │                               │ │
│  │    ┌─────────────────┐        │ │
│  │    │                 │        │ │
│  │    │  [QR FRAME]     │        │ │
│  │    │                 │        │ │
│  │    └─────────────────┘        │ │
│  │                               │ │
│  │  Arahkan kamera ke QR Code    │ │
│  │                               │ │
│  └───────────────────────────────┘ │
│                                     │
│  🟢 GPS: Aktif                      │
│  📍 Lokasi: Di Sekolah              │
│                                     │
│  ┌─────────────────────────────────┐│
│  │       [    BATALKAN    ]         ││
│  └─────────────────────────────────┘│
│                                     │
└─────────────────────────────────────┘
```

**Auto-behaviors:**
- ✅ Camera opens immediately
- ✅ GPS checked automatically
- ✅ Auto-scan when QR detected
- ✅ Haptic feedback on scan

---

### Screen 3: Scan Success Feedback

```
┌─────────────────────────────────────┐
│  [Status Bar]                       │
├─────────────────────────────────────┤
│                                     │
│                                     │
│              ✅                      │
│                                     │
│       ABSENSI BERHASIL              │
│                                     │
│     ───────────────────────         │
│                                     │
│     Matematika - VII A              │
│     Rabu, 19 Jan 2026               │
│     07:05 WIB                       │
│                                     │
│     Status: Hadir                   │
│                                     │
│     ───────────────────────         │
│                                     │
│  ┌─────────────────────────────────┐│
│  │      [  KEMBALI KE HOME  ]       ││
│  └─────────────────────────────────┘│
│                                     │
│                                     │
└─────────────────────────────────────┘
```

**Full-screen feedback (NOT toast):**
- ✅ Large checkmark icon
- ✅ Clear success message
- ✅ All relevant details visible
- ✅ Large close button

---

### Screen 4: Scan Error Feedback

```
┌─────────────────────────────────────┐
│  [Status Bar]                       │
├─────────────────────────────────────┤
│                                     │
│                                     │
│              ❌                      │
│                                     │
│      DI LUAR AREA SEKOLAH           │
│                                     │
│     ───────────────────────         │
│                                     │
│   Pastikan GPS aktif dan            │
│   kamu berada di sekolah            │
│                                     │
│   Jarak dari sekolah: 250m          │
│   (maksimal: 100m)                  │
│                                     │
│     ───────────────────────         │
│                                     │
│  ┌─────────────────────────────────┐│
│  │       [   COBA LAGI   ]          ││
│  └─────────────────────────────────┘│
│                                     │
│  ┌─────────────────────────────────┐│
│  │       [     TUTUP     ]          ││
│  └─────────────────────────────────┘│
│                                     │
└─────────────────────────────────────┘
```

**Error types:**
- GPS not enabled
- Out of range
- QR expired
- Already scanned
- Invalid QR

---

### Screen 5: Attendance History

```
┌─────────────────────────────────────┐
│  Riwayat Absensi                    │
├─────────────────────────────────────┤
│                                     │
│  ┌───────────────────────────────┐ │
│  │ Rabu, 19 Jan 2026             │ │
│  │ Matematika - VII A            │ │
│  │ ✅ Hadir (07:05)              │ │
│  └───────────────────────────────┘ │
│                                     │
│  ┌───────────────────────────────┐ │
│  │ Selasa, 18 Jan 2026           │ │
│  │ IPA - VII A                   │ │
│  │ 🟡 Terlambat (07:18)          │ │
│  └───────────────────────────────┘ │
│                                     │
│  ┌───────────────────────────────┐ │
│  │ Senin, 17 Jan 2026            │ │
│  │ Matematika - VII A            │ │
│  │ 🔴 Sakit (surat dokter)       │ │
│  └───────────────────────────────┘ │
│                                     │
│  [   LIHAT LEBIH BANYAK   ]         │
│                                     │
├─────────────────────────────────────┤
│ [🏠 Home]  [📷 Scan]  [📋 Riwayat] │
└─────────────────────────────────────┘
```

**Simple list, no analytics needed.**

---

# 2️⃣ USER FLOWS (Per Role with Time Targets)

## 👨‍🏫 GURU - Daily Attendance Flow

```
START
  │
  ├─> Login (username + password)
  │   └─> Dashboard
  │       │
  │       ├─> See today's schedule
  │       │
  │       └─> Click "GENERATE QR" (Matematika VII A)
  │           │
  │           ├─> QR displayed with smart defaults
  │           │   ✅ Expiry: 10 min
  │           │   ✅ Location: Active  
  │           │   ✅ Max scans: 30
  │           │
  │           ├─> Show QR to class (projector/phone)
  │           │
  │           ├─> Monitor real-time count
  │           │   └─> "15/30 siswa sudah scan"
  │           │
  │           ├─> Wait for students
  │           │   (teacher can walk around)
  │           │
  │           ├─> Close QR after 10 minutes
  │           │
  │           └─> Click "LIHAT ABSENSI"
  │               │
  │               ├─> See colored list
  │               │   🟢 23 hadir
  │               │   🟡 2 terlambat
  │               │   🔴 5 belum hadir
  │               │
  │               ├─> Manual input for absent students
  │               │   └─> Select: Sakit/Izin/Alpa
  │               │   └─> Add note (optional)
  │               │   └─> Save
  │               │
  │               └─> Done
END

🎯 **Total time:** < 2 minutes (including manual input)
```

---

## 👨‍🎓 SISWA - Scan QR Flow

```
START
  │
  ├─> Open app (auto-login from saved session)
  │   └─> Home screen
  │       │
  │       ├─> See status: "Belum Absen"
  │       │
  │       └─> Tap "SCAN QR" (large button)
  │           │
  │           ├─> Camera opens AUTOMATICALLY
  │           │   └─> GPS checked AUTOMATICALLY
  │           │
  │           ├─> Point at teacher's QR
  │           │
  │           ├─> QR detected & scanned AUTO
  │           │   └─> Vibration feedback
  │           │
  │           ├─> Validation...
  │           │   ├─> QR valid?
  │           │   ├─> Location OK?
  │           │   └─> Not duplicate?
  │           │
  │           └─> Result:
  │               │
  │               ├─> ✅ SUCCESS
  │               │   └─> Full-screen confirmation
  │               │       └─> "ABSENSI BERHASIL"
  │               │       └─> Details shown
  │               │       └─> Tap "KEMBALI KE HOME"
  │               │
  │               └─> ❌ ERROR
  │                   └─> Full-screen error
  │                       └─> Clear reason
  │                       └─> "COBA LAGI" or "TUTUP"
END

🎯 **Total time:** 5-10 seconds (if successful)
```

---

## 🧑‍💼 ADMIN SEKOLAH - Daily Check Flow

```
START
  │
  ├─> Login
  │   └─> Dashboard
  │       │
  │       ├─> See today's summary
  │       │   └─> All classes attendance rate
  │       │
  │       ├─> Click "Laporan Harian"
  │       │   └─> See detailed report
  │       │   └─> Filter by class/time
  │       │
  │       ├─> Export if needed
  │       │   └─> Excel or PDF
  │       │
  │       └─> Manage master data (occasional)
  │           ├─> Add/edit students
  │           └─> Add/edit classes
END

🎯 **Admin does NOT participate in daily scanning**
```

---

# 3️⃣ AGE-SPECIFIC UX REQUIREMENTS

## 🧒 SD (Usia 6-12 tahun)

### Mandatory UX Features

| Feature | Specification | Reason |
|---------|--------------|---------|
| **Button Size** | Min 60x60px | Small fingers, poor motor control |
| **Font Size** | 18-20px body, 24-28px headers | Reading ability developing |
| **Color Contrast** | WCAG AAA (7:1) | Clear visibility |
| **Feedback** | Full-screen with icons | Cannot rely on small text |
| **Language** | Simple Indonesian | No technical jargon |
| **Error Messages** | Pictorial + text | Visual learners |

### Forbidden UX

- ❌ Toast notifications (too small, disappear)
- ❌ Technical terms ("GPS tidak aktif" → "Nyalakan lokasi")
- ❌ Multiple steps (1-2 max)
- ❌ Small buttons
- ❌ Complex menus

### Example Error Message (SD):

```
❌ [BIG SAD EMOJI]

Kamu Jauh dari Sekolah

Dekati sekolah dulu ya!

[TOMBOL BESAR: COBA LAGI]
```

---

## 👦 SMP (Usia 13-15 tahun)

### Mandatory UX Features

| Feature | Specification | Reason |
|---------|--------------|---------|
| **Button Size** | Min 48x48px | Still developing |
| **Font Size** | 16-18px body | Can read smaller |
| **Language** | Standard Indonesian | More literate |
| **Features** | + History view | Want to track themselves |
| **Icons** | Icon + text labels | Visual + textual |

### Risk Areas

- ⚠️ Don't make it too "childish" (they're sensitive)
- ⚠️ Don't make it too complex (still learning)

### Balance Example:

```
✅ Status: Hadir (07:05)          ← Clear but not babyish
❌ Yeay kamu udah absen! 🎉       ← Too childish
❌ Attendance logged at 07:05:32  ← Too technical
```

---

## 🧑‍🎓 SMA/SMK (Usia 16-18 tahun)

### Mandatory UX Features

| Feature | Specification | Reason |
|---------|--------------|---------|
| **Button Size** | Min 44x44px (standard) | Adult dexterity |
| **Font Size** | 14-16px | Can read smaller text |
| **Language** | Can use some English | Tech-savvy |
| **Speed** | Prioritize above all | Impatient, multi-tasking |
| **Features** | + Detailed history | Self-monitoring |

### Risk Areas

- ⚠️ Too formal/rigid → boring
- ⚠️ Too many animations → annoying
- ⚠️ Slow → will complain loudly

### Ideal UX:

```
Status: ✅ Present (07:05)
Next: Biology @ 08:30

[SCAN AGAIN] [HISTORY]     ← Quick, no nonsense
```

---

## 📊 UX Comparison Table

| Aspect | SD | SMP | SMA |
|--------|----|----|-----|
| **Primary Need** | Clarity | Balance | Speed |
| **Button Size** | 60px | 48px | 44px |
| **Font Size** | 20px | 18px | 16px |
| **Error Handling** | Pictorial | Icon + text | Text OK |
| **Language** | Simple | Standard | Can be technical |
| **Main Risk** | Confusion | Wrong tap | Boredom/impatience |
| **Success Metric** | No teacher help | Independent use | < 5 seconds |

---

# 4️⃣ UI COPYWRITING GUIDE

## Button Labels (Indonesian)

### Web (Teacher)

| Action | Button Text | Alternative |
|--------|------------|-------------|
| Generate QR | "GENERATE QR" | "Buat QR Code" |
| View attendance | "LIHAT ABSENSI" | "Cek Kehadiran" |
| Close QR | "TUTUP QR" | "Selesai" |
| Refresh QR | "REFRESH QR" | "Buat Lagi" |
| Manual input | "INPUT MANUAL" | "Isi Manual" |
| Save | "SIMPAN" | "OK" |
| Cancel | "BATAL" | "Tidak Jadi" |
| Export Excel | "EXPORT EXCEL" | "Unduh Excel" |
| Print | "CETAK" | "Print" |
| Logout | "KELUAR" | "Logout" |

### Mobile (Student)

| Action | Button Text | Alternative |
|--------|------------|-------------|
| Scan QR | "SCAN QR" | "Scan Absensi" |
| Try again | "COBA LAGI" | "Scan Lagi" |
| Close | "TUTUP" | "OK" |
| Back to home | "KEMBALI KE HOME" | "Ke Beranda" |
| View more | "LIHAT LEBIH BANYAK" | "Muat Lebih" |

---

## Status Messages

### Success Messages

```
✅ ABSENSI BERHASIL
✅ QR Code Berhasil Dibuat
✅ Data Berhasil Disimpan
✅ Laporan Berhasil Diunduh
```

### Error Messages (Student-Facing)

```
❌ QR Code Sudah Kadaluarsa
   Minta guru untuk generate QR baru

❌ Kamu Sudah Absen Hari Ini
   Tidak perlu scan lagi

❌ Di Luar Area Sekolah
   Dekati sekolah dan coba lagi

❌ GPS Tidak Aktif
   Nyalakan GPS di pengaturan

❌ QR Code Tidak Valid
   Pastikan kamu scan QR dari guru

❌ Koneksi Internet Terputus
   Cek koneksi internet kamu
```

### Warning Messages

```
⚠️ QR akan kedaluwarsa dalam 2 menit
⚠️ Batas scan hampir tercapai (28/30)
⚠️ Ada 5 siswa belum absen
```

---

## Help Text / Placeholders

```
"Arahkan kamera ke QR Code"
"Tulis catatan (opsional)"
"Pilih status kehadiran"
"Cari nama siswa..."
"Filter berdasarkan tanggal"
```

---

# 5️⃣ UX TEST SCENARIOS

## Scenario 1: Happy Path (Normal Use)

**Actor:** Student Ahmad (SMP, 13 years old)  
**Goal:** Scan QR for Math class

**Steps:**
1. Ahmad arrives to class at 07:02
2. Teacher displays QR on projector
3. Ahmad opens app → sees "Belum Absen"
4. Taps "SCAN QR"
5. Camera opens, GPS already on
6. Points phone at QR
7. Auto-scans successfully
8. Sees "✅ ABSENSI BERHASIL - Hadir (07:02)"
9. Taps "KEMBALI KE HOME"
10. Sees updated status: "✅ Sudah Absen"

**Expected time:** 8 seconds  
**Pass criteria:** No confusion, no errors, happy student

---

## Scenario 2: Edge Case - Late Student

**Actor:** Student Budi (SMA, 17 years old)  
**Goal:** Scan QR after arriving late

**Steps:**
1. Budi arrives at 07:17 (class started 07:00)
2. Opens app, sees "Belum Absen"
3. Scans QR successfully
4. Sees "🟡 ABSENSI BERHASIL - Terlambat (07:17)"
5. Status shows he was late

**Expected behavior:** System auto-marks as "Late", no teacher intervention needed  
**Pass criteria:** Student understands he was late

---

## Scenario 3: Error Case - Outside School

**Actor:** Student Citra (SD, 9 years old)  
**Goal:** Try to scan from home (cheating attempt)

**Steps:**
1. Citra at home, gets QR screenshot from friend
2. Opens app, taps "SCAN QR"
3. Scans screenshot QR
4. GPS check fails (too far from school)
5. Sees full-screen error:
   ```
   ❌ [SAD FACE]
   
   Kamu Jauh dari Sekolah
   
   Dekati sekolah dulu ya!
   
   [COBA LAGI]
   ```
6. Cannot proceed

**Expected behavior:** Clear rejection, simple language for SD student  
**Pass criteria:** Student understands why it failed (SD level comprehension)

---

## Scenario 4: Edge Case - Duplicate Scan

**Actor:** Student Dewi (SMP, 14 years old)  
**Goal:** Accidentally scan twice

**Steps:**
1. Dewi scans successfully at 07:05
2. Forgets and tries to scan again at 07:10
3. System detects duplicate
4. Shows clear message:
   ```
   ⚠️ Kamu Sudah Absen
   
   Matematika - VII A
   Scan pertama: 07:05 WIB
   
   Tidak perlu scan lagi
   
   [TUTUP]
   ```

**Expected behavior:** Friendly rejection, shows previous scan time  
**Pass criteria:** Student remembers first scan, no confusion

---

## Scenario 5: Teacher Stress Test

**Actor:** Guru Pak Budi (40 years old, moderate tech literacy)  
**Goal:** Take attendance for 30 students in 2 minutes

**Steps:**
1. Pak Budi logs in at 06:58
2. Dashboard shows today's schedule
3. Clicks "GENERATE QR" at 07:00
4. QR appears instantly with smart defaults
5. Projects QR to screen
6. Students scan (15 immediate, 5 trickling in late)
7. Pak Budi watches real-time counter: "20/30 scan"
8. At 07:05, closes QR
9. Clicks "LIHAT ABSENSI"
10. Sees visual status (green/yellow/red)
11. Quick manual input for 10 missing students
    - 3 × Sakit
    - 2 × Izin
    - 5 × Alpa
12. Saves and done at 07:07

**Total time:** 7 minutes (well under target)  
**Pass criteria:** Teacher prefers this over paper attendance

---

## Scenario 6: Admin Monthly Report

**Actor:** Admin Ibu Siti  
**Goal:** Generate monthly attendance report

**Steps:**
1. Login to admin panel
2. Click "Laporan" → "Bulanan"
3. Select: Januari 2026, Kelas VII A
4. See summary table with percentages
5. Click "EXPORT EXCEL"
6. Download completes
7. Open file, verify data accuracy

**Expected time:** < 1 minute  
**Pass criteria:** Clean Excel ready for principal

---

## 🧠 FINAL IMPLEMENTATION CHECKLIST

### Before Handoff to Designer

- [ ] All wireframes reviewed
- [ ] Age-specific requirements understood
- [ ] Copywriting approved by Indonesian speaker
- [ ] Color palette decided (conservative for schools)
- [ ] Font family decided (Inter/System recommended)

### Before Development

- [ ] Wireframes converted to Figma/Sketch
- [ ] Component library created (buttons, cards, etc.)
- [ ] Responsive breakpoints defined
- [ ] Icon set selected (Feather/Heroicons)
- [ ] Image assets prepared

### After MVP Build

- [ ] Usability test with real SD student
- [ ] Usability test with real SMP student
- [ ] Usability test with real teacher
- [ ] Load test (100 concurrent scans)
- [ ] Accessibility audit (WCAG AA minimum)

---

**This spec is READY for:**
1. ✅ Designer handoff (Figma creation)
2. ✅ Direct implementation (if no designer)
3. ✅ User testing script creation

**UI/UX is NOT about beauty. It's about RESPECTING USER TIME.** ⏱️
