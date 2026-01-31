# 🎨 UI/UX Design Guidelines - AbsensiQRPro

> **Strategic Principle:** Good code + bad UI = failed product  
> **Target:** Teachers finish in < 30s, Students scan in < 5s

---

## 🧠 CORE PRINCIPLES (Non-Negotiable)

### The Golden Rule

> **Teachers want SPEED.**  
> **Students want DONE.**  
> **Admins want CONTROL.**

**If UI blocks any of these → system will be "used but hated".**

### Reality Check

**80% of school system failures = UI problems, NOT bugs.**

Examples of fatal mistakes:
- ❌ Dashboard full of charts (teachers don't care)
- ❌ Too many menu items (confusion)
- ❌ 5 clicks to take attendance (teachers return to paper)
- ❌ Fancy animations on mobile (students get impatient)

**Success metric:** If teachers prefer paper → UI failed.

---

## 1️⃣ WEBSITE UI/UX (Admin & Teachers)

### Target Users

| Role | Frequency | Priority Need |
|------|-----------|---------------|
| Super Admin | Rare, technical | Full control panel |
| School Admin | Daily routine | Overview + management |
| Teacher / Homeroom | Every day, rushed | **SPEED above all** |

### ❌ Fatal Mistakes to Avoid

```
❌ Dashboard → 10 charts, 5 widgets, scrolling required
❌ Generate QR → 8 form fields, dropdowns everywhere
❌ Attendance → Multiple modals, complex workflows
```

**Result:** Teachers go back to Excel sheets.

---

### ✅ WEBSITE STRUCTURE (FINAL)

#### Sidebar Navigation (Role-Based)

**Teacher View:**
```
📊 Dashboard               ← Action page, not analytics
📝 Absensi
   ├─ Hari Ini            ← Quick access
   └─ Riwayat
📱 QR Code
   ├─ Generate QR         ← Default smart settings
   └─ Riwayat QR
👥 Kelas Saya             ← Only their classes
📄 Laporan
   ├─ Harian
   └─ Bulanan
⚙️ Pengaturan
```

**Admin View:**
```
📊 Dashboard
📝 Absensi                ← All classes
📱 QR Management
👥 Manajemen Kelas        ← Full CRUD
👨‍🎓 Data Siswa
👨‍🏫 Data Guru
📊 Laporan Lengkap
⚙️ Pengaturan Sekolah
```

**CRITICAL:** 
- ✅ RBAC-based menu (teachers don't see admin menus)
- ✅ Cleaner UI per role
- ✅ Less cognitive load

---

### 📄 CRITICAL PAGES (Website)

#### 1. Teacher Dashboard (P0 - Most Important)

**NOT an analytics page. This is an ACTION page.**

**Layout:**

```
╔════════════════════════════════════════╗
║ Dashboard Guru - Pak Budi              ║
╠════════════════════════════════════════╣
║                                        ║
║  📅 Jadwalmu Hari Ini                  ║
║  ┌──────────────────────────────────┐ ║
║  │ Matematika - VII A               │ ║
║  │ 07:00 - 08:30                    │ ║
║  │ Ruang 12                         │ ║
║  │                                  │ ║
║  │ [GENERATE QR]  [LIHAT ABSENSI]  │ ║
║  │                                  │ ║
║  │ Status: 23/30 siswa sudah absen │ ║
║  └──────────────────────────────────┘ ║
║                                        ║
║  ┌──────────────────────────────────┐ ║
║  │ IPA - VII A                      │ ║
║  │ 08:30 - 09:30                    │ ║
║  │ [GENERATE QR]  [LIHAT ABSENSI]  │ ║
║  └──────────────────────────────────┘ ║
║                                        ║
╚════════════════════════════════════════╝
```

**Target:** Teacher completes action in **< 30 seconds**.

**Success indicators:**
- ✅ Schedule visible immediately
- ✅ 1-click QR generation
- ✅ Real-time attendance count
- ✅ No scrolling needed for today's schedule

---

#### 2. Generate QR Page

**❌ DO NOT:**
- Ask for expiry time (auto-calculate)
- Ask for radius (use school default)
- Ask for max scans (auto = class size)

**✅ SMART DEFAULTS:**

```
╔════════════════════════════════════════╗
║ Generate QR Code                       ║
╠════════════════════════════════════════╣
║                                        ║
║  Matematika - VII A                    ║
║  Rabu, 19 Januari 2026 | 07:00-08:30  ║
║                                        ║
║  ✅ Berlaku: 10 menit                  ║
║  ✅ Validasi lokasi: Aktif             ║
║  ✅ Max scan: 30 siswa                 ║
║                                        ║
║       [GENERATE QR CODE]               ║
║                                        ║
║  (Advanced settings) ← collapsed       ║
║                                        ║
╚════════════════════════════════════════╝
```

**Teachers do NOT want to think about parameters.**

---

#### 3. Class Attendance Page

**Focus:** Quick visual scan + manual input

```
╔════════════════════════════════════════╗
║ Absensi - Matematika VII A             ║
║ Rabu, 19 Jan 2026                      ║
╠════════════════════════════════════════╣
║                                        ║
║  Status: 23 Hadir | 5 Belum | 2 Sakit ║
║                                        ║
║  ┌────────────────────────────────┐   ║
║  │ No  Nama        Status   Waktu │   ║
║  ├────────────────────────────────┤   ║
║  │ 1   Ahmad       🟢 07:02       │   ║
║  │ 2   Budi        🟡 07:15       │   ║
║  │ 3   Citra       🔴 [Manual▼]  │   ║
║  │ 4   Dewi        🔴 [Manual▼]  │   ║
║  │     ...                        │   ║
║  └────────────────────────────────┘   ║
║                                        ║
║  [EXPORT EXCEL]  [PRINT]              ║
║                                        ║
╚════════════════════════════════════════╝
```

**Color coding:**
- 🟢 Green = Present (hadir)
- 🟡 Yellow = Late (terlambat)
- 🔴 Red = Not yet / absent

**Manual input:**
- Dropdown: Sakit | Izin | Alpa
- Optional: Short notes
- NO complex modals

---

### 🎨 Visual Guidelines (Website)

**Framework:** Tailwind CSS (utility-first, fast)

**Typography:**
- Primary: Inter / System Font
- Headers: 18-24px
- Body: 14-16px
- Buttons: 14px bold

**Colors (Conservative for Schools):**
```
Primary:   #3B82F6 (Blue - trustworthy)
Success:   #10B981 (Green)
Warning:   #F59E0B (Yellow)
Danger:    #EF4444 (Red)
Neutral:   #6B7280 (Gray)

Background: #F9FAFB (Light gray)
Surface:    #FFFFFF (White cards)
```

**DO NOT:**
- ❌ Excessive gradients
- ❌ 3D effects
- ❌ Animated backgrounds
- ❌ Neon colors

**Schools are conservative environments, not crypto startups.**

---

## 2️⃣ MOBILE APP UI/UX (Students)

### Target Users

**Age range:** 7-18 years (SD to SMK)  
**Usage time:** **< 10 seconds total**  
**Tech literacy:** Varies widely

**If longer than 10 seconds:**
- Students scan wrong QR
- Complaints to teachers
- Ask friends to scan for them

---

### ❌ FATAL MISTAKES

```
❌ 5 tabs in bottom nav
❌ Analytics charts for students
❌ Complex profiles
❌ Social features
❌ "Cool but unnecessary" features
```

**Remember:** Students want to scan and leave. Period.

---

### ✅ MOBILE STRUCTURE (MVP FINAL)

#### Bottom Navigation (3 Tabs MAX)

```
┌─────────────────────────────────────┐
│                                     │
│         [MAIN CONTENT]              │
│                                     │
└─────────────────────────────────────┘
┌─────────┬─────────┬─────────────────┐
│  🏠     │   📷    │      📋        │
│  Home  │  Scan   │   Riwayat      │
└─────────┴─────────┴─────────────────┘
```

**If more than 3 tabs → you're over-engineering.**

---

### 📱 CRITICAL MOBILE PAGES

#### 1. Home Screen

**Keep it SIMPLE:**

```
┌─────────────────────────────────────┐
│  👋 Halo, Ahmad                     │
│  Kelas VII A                        │
│                                     │
│  ┌───────────────────────────────┐ │
│  │  Status Hari Ini              │ │
│  │                               │ │
│  │  ✅ Sudah Absen               │ │
│  │  Matematika - 07:05           │ │
│  │                               │ │
│  └───────────────────────────────┘ │
│                                     │
│  Jadwal Berikutnya:                │
│  IPA - 08:30                        │
│                                     │
└─────────────────────────────────────┘
```

**NO charts, NO fancy animations.**

---

#### 2. Scan QR Screen (P0 - MOST CRITICAL)

**Ideal flow:**
1. Open app
2. Tap "Scan"
3. Camera opens automatically
4. Scan QR
5. Done

**Target time: < 5 seconds**

**Layout:**

```
┌─────────────────────────────────────┐
│  ┌─ Camera Viewfinder ───────────┐ │
│  │                               │ │
│  │      [QR SCANNING AREA]       │ │
│  │                               │ │
│  │   Arahkan ke QR Code          │ │
│  │                               │ │
│  └───────────────────────────────┘ │
│                                     │
│      🟢 GPS: Aktif                  │
│      📍 Lokasi: Di Sekolah          │
│                                     │
│      [    BATALKAN    ]             │
│                                     │
└─────────────────────────────────────┘
```

**Auto-behaviors:**
- ✅ Camera activates immediately
- ✅ GPS checked automatically
- ✅ Auto-scan when QR detected (no extra tap)
- ✅ Vibration feedback on scan

---

#### 3. Scan Feedback (CRITICAL UX)

**❌ DO NOT use tiny toast messages!**

**✅ USE full-screen feedback:**

**Success:**
```
┌─────────────────────────────────────┐
│                                     │
│          ✅                          │
│                                     │
│     ABSENSI BERHASIL                │
│                                     │
│     Matematika - VII A              │
│     Rabu, 19 Jan 2026               │
│     07:05 WIB                       │
│                                     │
│     Status: Hadir                   │
│                                     │
│     [      TUTUP      ]             │
│                                     │
└─────────────────────────────────────┘
```

**Error:**
```
┌─────────────────────────────────────┐
│                                     │
│          ❌                          │
│                                     │
│   DI LUAR AREA SEKOLAH              │
│                                     │
│   Pastikan GPS aktif dan            │
│   kamu berada di sekolah            │
│                                     │
│     [   COBA LAGI   ]               │
│     [     TUTUP      ]              │
│                                     │
└─────────────────────────────────────┘
```

**Why full-screen:**
- Elementary students (SD) need clear feedback
- Prevents "did it work?" confusion
- Reduces repeated scans
- Better accessibility

---

#### 4. History/Riwayat

**Simple list:**

```
┌─────────────────────────────────────┐
│  Riwayat Absensi                    │
│                                     │
│  ┌─────────────────────────────┐   │
│  │ Rabu, 19 Jan 2026           │   │
│  │ Matematika - VII A          │   │
│  │ ✅ Hadir (07:05)            │   │
│  └─────────────────────────────┘   │
│                                     │
│  ┌─────────────────────────────┐   │
│  │ Selasa, 18 Jan 2026         │   │
│  │ IPA - VII A                 │   │
│  │ 🟡 Terlambat (07:18)        │   │
│  └─────────────────────────────┘   │
│                                     │
│  [   LIHAT LEBIH BANYAK   ]        │
│                                     │
└─────────────────────────────────────┘
```

**Keep it minimal. No analytics needed.**

---

### 🎨 Mobile Visual Guidelines

**Design System:**

**Typography:**
- Headers: 20-24px BOLD
- Body: 16-18px (larger than web!)
- Buttons: 16px bold

**Colors:**
- High contrast required
- Same palette as web (consistency)
- Status colors very visible

**Buttons:**
- Minimum 48x48px tap target
- Large, full-width for primary actions
- Clear labels (not icons only)

**Spacing:**
- Generous padding (16-24px)
- Easy for thumbs
- Consider small screens (iPhone SE)

**Animations:**
- MINIMAL and functional only
- Loadingspin ners: Simple
- Transitions: Fast (200ms max)

**This is NOT Instagram. Speed > aesthetics.**

---

## 3️⃣ CONSISTENCY: Web ↔ Mobile

### ❌ Classic Mistake

**Web uses:** "Check-in"  
**Mobile uses:** "Scan masuk"

**Result:** Confusion, support tickets.

### ✅ TERMINOLOGY STANDARD

| Concept | Web (ID) | Mobile (ID) | English |
|---------|----------|-------------|---------|
| Hadir | Hadir | Hadir | Present |
| Terlambat | Terlambat | Terlambat | Late |
| Sakit | Sakit | Sakit | Sick |
| Izin | Izin | Izin | Permit |
| Alpa | Alpa | Alpa | Absent |
| Scan QR | Scan QR Code | Scan | Scan QR |
| Generate | Generate QR | - | Generate |
| Absensi | Absensi | Absensi | Attendance |

**Rule:** Don't be "creative" with terminology. Consistency prevents confusion.

---

## 4️⃣ MVP UI/UX CHECKLIST

### 🔴 MUST HAVE (P0)

**Web (Teachers):**
- [ ] Dashboard action-focused (not analytics)
- [ ] Generate QR < 30 seconds
- [ ] Real-time attendance view
- [ ] Simple manual input (dropdown + notes)
- [ ] Role-based sidebar menu

**Mobile (Students):**
- [ ] Scan QR < 5 seconds
- [ ] Full-screen feedback (success/error)
- [ ] 3 tabs only (Home, Scan, History)
- [ ] Auto GPS check
- [ ] Large, clear buttons

**Both:**
- [ ] Consistent terminology
- [ ] High contrast colors
- [ ] Responsive design
- [ ] Clear error messages

---

### 🟠 NICE TO HAVE (Phase 2)

**Can wait until after pilot:**
- [ ] Dark mode
- [ ] Advanced analytics
- [ ] Custom themes
- [ ] Notification preferences
- [ ] Profile pictures

---

### ❌ DO NOT ADD NOW

**Will kill velocity:**
- ❌ Parent app
- ❌ Chat features
- ❌ Social features
- ❌ Gamification
- ❌ Complex statistics
- ❌ Offline mode (complex!)

---

## 5️⃣ INTERACTION FLOWS

### Teacher: Generate QR & Check Attendance

```
Dashboard
    │
    ├─> Click "Generate QR" (Matematika VII A)
    │   └─> QR displayed (auto-settings)
    │       └─> Share to projector/students
    │           └─> Monitor real-time count
    │
    └─> Click "Lihat Absensi"
        └─> See colored list (green/yellow/red)
            └─> Manual input for absent students
                └─> Save → Done
```

**Total time: < 2 minutes including manual input**

---

### Student: Scan QR Attendance

```
Open App
    │
    └─> Tap "Scan" tab
        └─> Camera opens AUTO
            └─> Point at QR
                └─> Scan detected AUTO
                    │
                    ├─> Success ✅
                    │   └─> Full-screen feedback
                    │       └─> Close → Done
                    │
                    └─> Error ❌
                        └─> Full-screen error reason
                            └─> Retry or close
```

**Total time: 5-10 seconds**

---

## 6️⃣ ACCESSIBILITY

### Must Support

- ✅ Large tap targets (min 48x48px)
- ✅ High contrast text (WCAG AA minimum)
- ✅ Clear error messages (no jargon)
- ✅ Works on old Android (min Android 8.0)
- ✅ Works on small screens (iPhone SE)

### Not Required for MVP

- Screen readers (Phase 2)
- Voice control (Phase 2)
- Multi-language (ID only for now)

---

## 🎯 SUCCESS METRICS

### Teacher Satisfaction
- ✅ Generate QR: < 30 seconds
- ✅ Check attendance: < 2 minutes
- ✅ Prefer app over paper

### Student Satisfaction
- ✅ Scan time: < 10 seconds
- ✅ No confusion on feedback
- ✅ Minimal support questions

### Admin Satisfaction
- ✅ Real-time visibility
- ✅ Accurate reports
- ✅ Easy data export

**If any metric fails → UI/UX needs iteration.**

---

## 🧠 FINAL PRINCIPLE

> **Good code + Bad UI = Failed product**  
> **Average code + Great UI = Success**

Your code is **already production-grade**.  
UI/UX is now the **determining factor for adoption**.

**Follow these guidelines:**
- ✅ Teachers don't complain
- ✅ Students finish quickly
- ✅ Admins stay in control

**Break these guidelines:**
- ❌ System is "used but hated"
- ❌ Back to paper/Excel
- ❌ Pilot fails

---

**This is NOT about aesthetics. This is about USABILITY.** 🎯
