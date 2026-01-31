# 🎯 UI/UX Operational Spec - Production Ready

> **Purpose:** Final spec for parallel team work (Design + Dev + QA)  
> **Status:** Ready for implementation sprint  
> **Timeline:** 1-3 year sustainability

---

## 📋 CONTENTS

1. Component Design System (Minimal but Sufficient)
2. Acceptance Criteria (QA-Ready, No Arguments)
3. Real-World Simulation (Full Day at School)
4. Component-API Mapping
5. Sprint Backlog (Jira/Linear Ready)
6. Pre-Demo Checklist

---

# 1️⃣ COMPONENT DESIGN SYSTEM

## Philosophy: "Cukup untuk 1-3 tahun, tidak kurang tidak lebih"

---

## 🔘 BUTTONS

### ButtonPrimary

**Usage:** Main actions only
- Generate QR
- Scan QR
- Simpan Absensi

**Specs:**
```
Height: 48px (mobile), 40px (web)
Border Radius: 8px
Font: 14px bold
Padding: 12px 24px
Min Width: 120px

States:
- Default: bg-blue-600, text-white
- Hover: bg-blue-700
- Disabled: bg-gray-300, text-gray-500, cursor-not-allowed
- Loading: spinner + disabled state
```

**Copy Rules:**
- ✅ Verb + Object: "Generate QR Absensi"
- ❌ Generic: "Submit", "OK"
- Format: UPPERCASE or Title Case (consistent)

---

### ButtonSecondary

**Usage:** Supporting actions
- Lihat Absensi
- Kembali
- Refresh QR

**Specs:**
```
Height: 48px (mobile), 40px (web)
Border: 2px solid blue-600
Background: transparent or blue-50
Color: blue-600
Border Radius: 8px
```

**Visual Rule:** NEVER more prominent than Primary

---

### ButtonDanger (Rare)

**Usage:** Destructive actions ONLY
- Tutup QR (closes active session)
- Hapus Data (admin only)

**Specs:**
```
Same as Primary but:
Background: red-600
Hover: red-700
```

**⚠️ DO NOT use red for normal actions**

---

## 🧱 CARDS

### CardSchedule (Teacher Dashboard)

**Usage:** Display class schedule with actions

**Required Elements:**
```
┌────────────────────────────┐
│ MATEMATIKA - VII A         │ ← Header (bold, 18px)
│ 07:00 - 08:30 | Ruang 12   │ ← Meta
│                            │
│ 📊 23/30 siswa sudah absen │ ← Status (realtime)
│                            │
│ [Generate QR] [Lihat]      │ ← Max 2 buttons
└────────────────────────────┘

Dimensions: Full width, auto height
Padding: 16px
Shadow: sm (subtle)
```

**Forbidden:**
- ❌ Charts/graphs
- ❌ Long descriptions
- ❌ More than 2 action buttons

---

### CardStatus (Summary)

**Usage:** Attendance summary display

**Specs:**
```
┌─────────────┐
│     23      │ ← Number (32px bold)
│   Hadir     │ ← Label (14px)
│   🟢        │ ← Icon
└─────────────┘

Background: Status-specific color (light)
Border-left: 4px solid (status color)
```

**Color mapping:**
- Hadir: green-50 bg, green-600 border
- Terlambat: yellow-50 bg, yellow-600 border
- Belum: red-50 bg, red-600 border

---

## 🪟 MODALS

### ModalGenerateQR (CRITICAL)

**Usage:** Display generated QR code

**Specs:**
```
╔═══════════════════════════════╗
║ Generate QR - Matematika VII A║ ← Header
╠═══════════════════════════════╣
║                               ║
║    [ QR CODE 300x300px ]      ║ ← Centered
║                               ║
║  Berlaku sampai: 07:10 WIB    ║ ← Countdown
║  Siswa terscan: 15/30         ║ ← Live counter
║                               ║
║  [Tutup QR]    [Refresh QR]   ║
╚═══════════════════════════════╝

Width: 500px (web), 90vw (mobile)
Max-width: 600px
```

**Forbidden:**
- ❌ Input forms in this modal
- ❌ Scrolling content
- ❌ QR smaller than 250px

---

### ModalManualAttendance

**Usage:** Teacher manual input for absent students

**Specs:**
```
┌────────────────────────────┐
│ Input Manual - Ahmad Fauzi │
├────────────────────────────┤
│                            │
│ Status: [Sakit    ▼]       │ ← Dropdown (required)
│                            │
│ Catatan:                   │
│ [_____________________]    │ ← Textarea (optional)
│                            │
│ Lampiran: [📎 Upload]     │ ← File (optional)
│                            │
│ [Batal]      [Simpan]      │
└────────────────────────────┘

Width: 400px
```

**Validation:**
- ❌ "Hadir" or "Terlambat" NOT in dropdown
- ✅ Only: Sakit, Izin, Alpa, Excused

---

## 📋 TABLES / LISTS

### AttendanceList (Web)

**Usage:** Class attendance view

**Specs:**
```
Table columns:
┌────┬──────────────┬────────────┬──────┬────────┐
│ No │ Nama Siswa   │ Status     │ Waktu│ Aksi   │
├────┼──────────────┼────────────┼──────┼────────┤
│ 1  │ Ahmad Fauzi  │ 🟢 Hadir   │ 07:02│   -    │
│ 2  │ Budi S.      │ 🟡 Terlambat│07:15│   -    │
│ 3  │ Citra D.     │ 🔴 Belum   │  -   │[Manual]│
└────┴──────────────┴────────────┴──────┴────────┘

Features:
- Sticky header
- Zebra striping (alternating row colors)
- Status: Icon + Color + Text
- Responsive: Scroll horizontal on small screens
```

**Mobile Conversion:**
```
Cards instead of table:

┌────────────────────────┐
│ 1. Ahmad Fauzi         │
│ 🟢 Hadir - 07:02       │
└────────────────────────┘

┌────────────────────────┐
│ 3. Citra Dewi          │
│ 🔴 Belum       [Manual]│
└────────────────────────┘
```

---

## 🔔 FEEDBACK COMPONENTS

### AlertInline (Info)

**Usage:** Non-critical information

**Specs:**
```
┌───────────────────────────────┐
│ ℹ️ Kamu sudah absen hari ini  │
└───────────────────────────────┘

Background: blue-50
Border-left: blue-500 4px
Padding: 12px 16px
Dismissible: Optional
```

---

### AlertFullScreen (Mobile CRITICAL)

**Usage:** Success/Error feedback after scan

**Success Spec:**
```
Full screen overlay:

        ✅           ← Icon 64px

  ABSENSI BERHASIL   ← Title 24px bold

  Matematika - VII A ← Details 16px
  07:05 WIB

  [KEMBALI KE HOME]  ← Button

Background: Solid color (not transparent)
Auto-dismiss: NO (user must tap)
```

**Error Spec:**
```
Full screen overlay:

        ❌           ← Icon 64px

  DI LUAR AREA       ← Title 24px bold

  Pastikan GPS aktif ← Message 16px
  dan kamu di sekolah

  [COBA LAGI]        ← Primary action
  [TUTUP]            ← Secondary

Auto-dismiss: NO
```

**⚠️ NEVER use toast for critical feedback on mobile!**

---

# 2️⃣ ACCEPTANCE CRITERIA (QA-READY)

## Format: Given-When-Then + Pass/Fail

---

## 🖥️ Page: Teacher Dashboard

### AC-001: Schedule Loads Fast
```
GIVEN: Teacher logs in at 06:55
WHEN: Dashboard opens
THEN: Today's schedule appears in < 2 seconds

PASS: Schedule visible, no loading spinner
FAIL: Loading > 2s OR error message
```

### AC-002: No Scrolling for Today
```
GIVEN: Teacher has 4 classes today
WHEN: Dashboard loads
THEN: All today's classes visible without scrolling

PASS: All schedules fit viewport
FAIL: Must scroll to see 4th class
```

### AC-003: Quick QR Access
```
GIVEN: Teacher on dashboard
WHEN: Wants to generate QR
THEN: Can do it in ≤ 2 clicks

PASS: Dashboard → Generate QR (2 clicks)
FAIL: More than 2 clicks OR hidden button
```

### AC-004: No Distractions
```
GIVEN: Dashboard loaded
WHEN: Teacher views page
THEN: No charts, graphs, or analytics

PASS: Only schedule + action buttons
FAIL: Any chart/graph/statistic visible
```

---

## 🖥️ Page: Generate QR

### AC-005: QR Appears Quickly
```
GIVEN: Teacher clicks "Generate QR"
WHEN: Modal opens
THEN: QR code visible in < 2 seconds

PASS: QR displayed, scannable
FAIL: Loading > 2s OR QR not rendered
```

### AC-006: QR Size Adequate
```
GIVEN: QR modal opened
WHEN: Displayed on screen
THEN: QR is ≥ 280x280px

PASS: Students can scan from 2m distance
FAIL: QR too small to scan reliably
```

### AC-007: No Parameter Input
```
GIVEN: Teacher generates QR
WHEN: Modal opens
THEN: No manual configuration required

PASS: Smart defaults applied automatically
FAIL: Must fill form fields
```

### AC-008: Expiry Visible
```
GIVEN: QR displayed
WHEN: Teacher shows to class
THEN: Expiry time clearly shown

PASS: "Berlaku sampai 07:10" visible
FAIL: No expiry indication
```

---

## 🖥️ Page: Class Attendance

### AC-009: Real-time Updates
```
GIVEN: QR active, students scanning
WHEN: Teacher views attendance list
THEN: Count updates every 5s OR manual refresh works

PASS: Can see new scans without page reload
FAIL: Must refresh entire page
```

### AC-010: Manual Input Restriction
```
GIVEN: Teacher inputs manual attendance
WHEN: Opens manual input modal
THEN: "Hadir" is NOT an option

PASS: Only Sakit/Izin/Alpa available
FAIL: Can select "Hadir" manually
```

### AC-011: Cannot Edit QR Results
```
GIVEN: Student scanned QR successfully
WHEN: Teacher views attendance
THEN: Cannot change QR-scanned status to manual

PASS: Locked, shows "Scan QR" indicator
FAIL: Can override QR scan
```

---

## 📱 Page: Student Scan

### AC-012: Auto Camera
```
GIVEN: Student taps "Scan"
WHEN: Scan screen opens
THEN: Camera activates automatically

PASS: Camera ready, no extra tap
FAIL: Needs manual camera activation
```

### AC-013: Scan Speed
```
GIVEN: Camera active, valid QR visible
WHEN: Student points at QR
THEN: Scan completes in < 5 seconds

PASS: Auto-scan, vibration feedback, result shown
FAIL: >5s OR needs manual capture button
```

### AC-014: Full-Screen Feedback
```
GIVEN: Scan completed (success or error)
WHEN: Result shown
THEN: Full-screen feedback appears

PASS: Large icon, clear message, full screen
FAIL: Small toast OR notification bar
```

---

## 📱 Page: Error Handling

### AC-015: Simple Error Messages
```
GIVEN: Scan fails (any reason)
WHEN: Error shown
THEN: Message is 1-2 sentences, no jargon

PASS: "Di luar area sekolah. Dekati sekolah."
FAIL: "GPS_VALIDATION_FAILED: Distance exceeds threshold"
```

### AC-016: Actionable Errors
```
GIVEN: Error displayed
WHEN: Student reads message
THEN: Clear "Coba Lagi" button present

PASS: Button visible, tappable
FAIL: Only "OK" OR no retry option
```

---

# 3️⃣ REAL-WORLD SIMULATION

## Full Day at School (Realistic Scenario)

---

## ⏰ 06:45 - Guru Pak Budi Tiba

**Action:**
- Pak Budi logs in via laptop/phone
- Dashboard loads

**Expected UX:**
- Schedule today visible: 4 classes
- No need to click menu items
- Coffee in hand, one hand on mouse

**PASS:** Dashboard shows everything needed  
**FAIL:** Must navigate menus, search for schedule

---

## ⏰ 06:58 - Prep Before Class

**Action:**
- Pak Budi clicks "Generate QR" for Matematika VII A
- QR appears in modal

**Expected UX:**
- No form filling
- QR large enough for projector
- Expiry clear: "Sampai 07:10"

**PASS:** < 30 seconds total  
**FAIL:** Confused by options, QR too small

---

## ⏰ 07:00 - Class Starts

**Action:**
- Pak Budi shows QR on projector
- 30 students start pulling out phones

**Expected UX:**
- Students can scan from their seats (2-3m distance)
- Real-time counter visible to teacher: "5/30 scan"

**Reality Check:**
- Some students forgot phones → OK (manual later)
- Some students slow → OK (they have 10 min)

---

## ⏰ 07:01-07:05 - Normal Scanning

**Student: Ahmad (on time, phone ready)**

**Flow:**
1. Opens app → sees "Belum Absen"
2. Taps "SCAN QR"
3. Camera opens auto
4. Points at projector
5. Scan happens auto
6. Sees: ✅ "ABSENSI BERHASIL - Hadir (07:01)"
7. Closes app

**Time:** 6 seconds  
**Mental state:** Happy, done

---

**Student: Budi (late, rushing)**

**07:14 arrival:**
1. Rushes in, opens app
2. Scans QR hurriedly
3. Sees: 🟡 "ABSENSI BERHASIL - Terlambat (07:14)"

**System:**
- Auto-marks late (>10min grace period)
- No manual teacher intervention needed

**PASS:** Automatic, accurate  
**FAIL:** Teacher must manually mark late

---

**Student: Citra (trying to cheat)**

**At home, 07:03:**
1. Gets screenshot of QR from friend (WhatsApp)
2. Opens app, scans screenshot
3. GPS check runs...
4. Sees: ❌ "DI LUAR AREA SEKOLAH"
   "Jarak dari sekolah: 8.5km (maks 100m)"

**System:**
- Blocks scan
- Logs suspicious attempt (for later review)
- Clear message

**Reality:**
- Citra cannot cheat this way
- No drama in class (she's not there anyway)
- Teacher can review suspicious scans later if needed

---

## ⏰ 07:10 - QR Auto-Expires

**Action:**
- QR stops accepting scans
- 25/30students have scanned

**Teacher:**
- Sees live count: "25/30"
- Closes QR modal
- Clicks "Lihat Absensi"

---

## ⏰ 07:12 - Manual Input

**Teacher sees:**
- 🟢 25 Hadir (including 2 late)
- 🔴 5 Belum hadir

**Action for each of 5:**

**Dina:** Click [Manual] → Status: "Sakit" → Note: "Demam, surat dokter"  
**Eka:** [Manual] → "Izin" → Note: "Acara keluarga"  
**Fani, Gita, Hadi:** [Manual] → "Alpa" → No note

**Time:** 2 minutes for all 5

**PASS:** Quick, clear dropdown  
**FAIL:** Complex form, can mark "Hadir" manually

---

## ⏰ 07:15 - Pak Budi Done

**Result:**
- All 30 students accounted for
- Data accurate
- No arguments ("Why am I marked absent?")

**Total time from class start:** 15 minutes  
**Teacher effort:** Minimal

**PASS:** Faster than paper  
**FAIL:** Slower than paper OR data conflicts

---

## ⏰ 08:30 - Next Class (Repeat)

Pak Budi teaches IPA VII B:
- Same flow
- Different students
- System performs identically

**System reliability:** Consistent across classes

---

## ⏰ 12:00 - Admin Check

**Bu Siti (School Admin) logs in:**

**Goal:** See today's attendance summary

**Action:**
1. Dashboard shows: "Attendance rate: 94%"
2. Clicks "Laporan Harian"
3. Sees all classes listed with counts
4. Filters to VII A Matematika
5. Sees detailed breakdown

**Data Quality Check:**
- ✅ 25 Hadir (23 on-time, 2 late)
- ✅ 2 Sakit (with notes)
- ✅ 1 Izin (with note)
- ✅ 2 Alpa (no notes)
- Total: 30 ✓

**PASS:** Data makes sense, no conflicts  
**FAIL:** Numbers don't add up, missing students

---

## ⏰ 15:00 - Potential Complaint

**Phone call to school:**

> "Ibu Siti, saya Ibu Dina (Dina's mom). Kenapa anak saya tidak hadir hari ini?"

**Bu Siti:**
1. Opens system
2. Searches: "Dina, VII A, 19 Jan"
3. Sees: "Sakit - Demam, surat dokter - Input manual oleh Pak Budi 07:12"

**Response:**
> "Ibu Dina, menurut catatan Pak Budi, Dina sakit demam dan ada surat dokter. Pak Budi yang input manual pukul 07:12."

**Parent:** "Oh ya, terima kasih Bu."

**PASS:** Issue resolved in < 1 minute  
**FAIL:** No record, no timestamp, conflict

---

## ⏰ 16:00 - Daily Recap

**Principal reviews:**
- Attendance rate: 94% (normal)
- 2 classes with < 90% → will follow up
- No technical issues reported
- Teachers satisfied vs old paper system

**Decision:** Continue using system

---

## 🚨 EDGE CASES THAT HAPPENED

### Case 1: Student with Broken Phone

**Hari (phone battery dead):**
- Cannot scan during class
- Raises hand to teacher
- Teacher: "Nanti saya isi manual ya"
- After class: Teacher marks "Hadir" manually with note "HP mati"

**System:** Flexible enough to handle

---

### Case 2: Teacher Forgot to Generate QR

**Pak Budi (distracted, forgot QR for class 3):**
- Realizes 5 min into class
- Generates QR mid-class
- Students who arrived on-time scan late
- Pak Budi manually corrects timestamps (admin override)

**System:** Recoverable, has admin tools

---

### Case 3: Network Down

**School internet drops at 10am:**
- Students cannot scan (needs API)
- Teachers switch to manual input for rest of day
- Data syncs when internet returns

**System:** Graceful degradation (though not offline-first MVP)

---

# 4️⃣ COMPONENT-API MAPPING

## Which Frontend Components Need Which API Endpoints

---

## Teacher Dashboard

```
Components → API Endpoints:

CardSchedule
  GET /api/v1/schedules/today
  Response: [{ id, subject, class, time, student_count }]

ButtonGenerateQR
  POST /api/v1/qr/generate
  Body: { schedule_id }
  Response: { qr_token, expiry, max_scans }

AttendanceCount (realtime)
  GET /api/v1/attendance/schedule/{id}/summary
  Response: { present, late, absent, total }
  Polling: Every 5s when QR active
```

---

## Generate QR Modal

```
ModalGenerateQR
  POST /api/v1/qr/generate (on open)
  GET /api/v1/qr/{id}/status (polling for count)
  DELETE /api/v1/qr/{id} (on close)
```

---

## Class Attendance List

```
AttendanceList
  GET /api/v1/attendance/schedule/{id}
  Response: [{ student_id, name, status, time, is_manual }]

ModalManualAttendance
  POST /api/v1/attendance/manual
  Body: { student_id, schedule_id, status, notes, attachment }
```

---

## Student Scan

```
ScanScreen
  POST /api/v1/attendance/scan
  Body: { qr_token, latitude, longitude, device_info }
  Response: { status, time, message }

ErrorFeedback
  Uses response.error_code to determine message
```

---

# 5️⃣ SPRINT BACKLOG (Jira/Linear Ready)

## Sprint 1: Core Teacher Web (2 weeks)

```
Story 1: Teacher Dashboard
  - Task: Create schedule card component
  - Task: Integrate GET /schedules/today API
  - Task: Add generate QR button
  AC: AC-001, AC-002, AC-003, AC-004

Story 2: Generate QR Flow
  - Task: Create QR modal component
  - Task: Integrate QR generation API
  - Task: Add realtime counter
  - Task: Add close/refresh buttons
  AC: AC-005, AC-006, AC-007, AC-008

Story 3: Attendance List
  - Task: Create attendance table component
  - Task: Integrate attendance API
  - Task: Add manual input modal
  - Task: Implement status restrictions
  AC: AC-009, AC-010, AC-011
```

---

## Sprint 2: Student Mobile (2 weeks)

```
Story 4: Student Scan Flow
  - Task: Camera component setup
  - Task: GPS integration
  - Task: QR scanner library integration
  - Task: API integration (scan endpoint)
  AC: AC-012, AC-013

Story 5: Feedback Screens
  - Task: Full-screen success component
  - Task: Full-screen error component
  - Task: Error message mapping
  AC: AC-014, AC-015, AC-016

Story 6: Home & History
  - Task: Dashboard/home screen
  - Task: History list component
  - Task: Status indicators
```

---

## Sprint 3: Testing & Polish (1 week)

```
Story 7: QA Full Flow
  - Task: All AC verification
  - Task: Cross-device testing
  - Task: Performance testing
  - Task: Bug fixes

Story 8: Real-world Simulation
  - Task: Run full day simulation
  - Task: Edge case testing
  - Task: Load testing (100 students)
```

---

# 6️⃣ PRE-DEMO CHECKLIST

## Before Showing to School

### Technical Readiness

- [ ] All P0 migrations run successfully
- [ ] Database seeded with demo data
- [ ] API rate limiting active (5/min for scan)
- [ ] All critical endpoints tested
- [ ] Mobile app installed on demo device
- [ ] Web app accessible on stable URL

### UX Readiness

- [ ] All acceptance criteria pass
- [ ] Teacher flow < 2 min tested
- [ ] Student scan < 5 sec tested
- [ ] Error messages in proper Indonesian
- [ ] No technical jargon visible to users
- [ ] Full-screen feedback works on all devices

### Demo Data

- [ ] 1 demo school created
- [ ] 3 demo teachers with schedules
- [ ] 30 demo students (1 class)
- [ ] Roles & permissions assigned correctly
- [ ] QR generation tested

### Presentation Prep

- [ ] Demo script prepared (follow simulation)
- [ ] Backup plan if internet fails
- [ ] Screenshots of key screens
- [ ] FAQ answers ready (technical questions)

### Risk Mitigation

- [ ] Tested on school's WiFi (if possible)
- [ ] Mobile data backup available
- [ ] Demo accounts credentials secure
- [ ] Rollback plan documented

---

## During Demo

### Teacher Demo Flow (5 min)

1. Login as teacher
2. Show dashboard (schedule visible)
3. Generate QR (1 click)
4. Show QR on screen
5. (Assistant scans as "student")
6. Show real-time count update
7. Close QR
8. Show attendance list
9. Demo manual input
10. Show final summary

### Student Demo Flow (3 min)

1. Open mobile app
2. Show home screen (clear status)
3. Tap scan
4. Scan the QR (live scan)
5. Show success feedback (full-screen)
6. Show updated home status
7. Show history

### Admin Demo Flow (2 min)

1. Login as admin
2. Show daily report
3. Show attendance summary
4. Demo Excel export

**Total demo time:** 10-15 minutes

---

## 🎯 SUCCESS CRITERIA FOR PILOT

### Week 1 (Soft Launch - 1 Class)

- [ ] Teacher can use without help
- [ ] Students scan successfully (>90% rate)
- [ ]] No critical bugs
- [ ] Data accuracy 100%

### Week 2-4 (Scale to Full School)

- [ ] All teachers trained
- [ ] All classes using system
- [ ] Attendance rate baseline established
- [ ] Feedback collection active

### Month 2-3 (Evaluation)

- [ ] Teachers prefer vs paper (survey)
- [ ] Time savings measured
- [ ] Cheating attempts logged but minimal
- [ ] Admin satisfied with reports

**If all pass → Production launch**  
**If 1 fails → Iterate & fix**

---

**This spec is NOW ready for:**
1. ✅ Designer (Figma mockups)
2. ✅ Developer (Sprint planning)
3. ✅ QA (Test case creation)
4. ✅ Stakeholder (Demo preparation)

**No more planning. Time to build.** 🚀
