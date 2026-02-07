# 📐 Visual Diagram - Super Admin Dashboard Structure

Diagram visual untuk memahami struktur dan alur Super Admin Dashboard.

---

## 🏗️ Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    SUPER ADMIN DASHBOARD                        │
│                     (Laravel Blade)                             │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │     routes/super-admin.php              │
        │     (47 routes defined)                 │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │   Middleware: auth, role:super-admin    │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  Controllers/SuperAdmin/                │
        │  - DashboardController                  │
        │  - SchoolController                     │
        │  - UserController                       │
        │  - MonitoringController                 │
        │  - SecurityController                   │
        │  - BillingController                    │
        │  - ReportController                     │
        │  - SettingController                    │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  Models                                 │
        │  - User                                 │
        │  - School                               │
        │  - Activity                             │
        │  - SecurityEvent                        │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  Database                               │
        │  - users                                │
        │  - schools                              │
        │  - activities                           │
        │  - security_events                      │
        └─────────────────────────────────────────┘
```

---

## 📄 Blade Component Structure

```
layouts/admin-super.blade.php
│
├── components/admin-super/sidebar.blade.php
│   │
│   ├── Header (Logo + Title)
│   │
│   ├── Navigation Menu
│   │   ├── 📊 Dashboard Utama (3 submenu)
│   │   ├── 🏫 Manajemen Sekolah (3 submenu)
│   │   ├── 👥 Manajemen Pengguna (2 submenu)
│   │   ├── 🔍 Monitoring Sistem (3 submenu)
│   │   ├── 🛡️ Security Monitoring (3 submenu)
│   │   ├── 💳 Billing & Subscription (3 submenu)
│   │   ├── 📈 Laporan Global (2 submenu)
│   │   └── ⚙️ Pengaturan Sistem (2 submenu)
│   │
│   └── Footer Status (System Online + Version)
│
├── components/admin-super/navbar.blade.php
│   │
│   ├── Left Section
│   │   ├── Hamburger Menu (Mobile)
│   │   └── Search Bar (Desktop)
│   │
│   └── Right Section
│       ├── Notification Bell (Badge)
│       ├── Messages Icon (Badge)
│       └── User Profile Dropdown
│           ├── Avatar + Name + Email
│           └── Dropdown Menu
│               ├── 👤 Profil Saya
│               ├── ⚙️ Pengaturan Akun
│               └── 🚪 Logout
│
├── Main Content Area
│   │
│   └── @yield('content')
│       │
│       └── super-admin/dashboard/overview.blade.php
│           │
│           ├── Page Title
│           │
│           ├── Stats Cards (4 cards)
│           │   ├── Sekolah Aktif
│           │   ├── Total Pengguna
│           │   ├── Event Keamanan
│           │   └── Uptime Sistem
│           │
│           ├── Charts (2 charts)
│           │   ├── Pertumbuhan Sekolah (Line Chart)
│           │   └── Distribusi Paket (Pie Chart)
│           │
│           └── Activity Logs Table
│               ├── Table Header
│               ├── Table Body (@forelse loop)
│               └── Empty State
│
└── components/admin-super/footer.blade.php
    │
    └── Copyright © 2026 AbsensiQR Pro
```

---

## 🔄 Data Flow Diagram

```
┌──────────────┐
│   Browser    │
│   Request    │
└──────┬───────┘
       │
       ▼
┌──────────────────────────────────┐
│   Route: /super-admin/dashboard  │
│   Middleware: auth, role         │
└──────┬───────────────────────────┘
       │
       ▼
┌─────────────────────────────────────────┐
│   DashboardController@overview()        │
│                                         │
│   1. Fetch data from database           │
│      - School::count()                  │
│      - User::count()                    │
│      - SecurityEvent::last24Hours()     │
│      - Activity::latest()->limit(10)    │
│                                         │
│   2. Calculate metrics                  │
│      - activeSchoolsPercentage          │
│      - systemUptime                     │
│                                         │
│   3. Format data                        │
│      - Map activities collection        │
│      - Format timestamps                │
│                                         │
│   4. Return view with data              │
└─────────┬───────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────┐
│   View: super-admin/dashboard/          │
│         overview.blade.php              │
│                                         │
│   1. Extends layout                     │
│   2. Includes components                │
│   3. Renders data                       │
│      - {{ $activeSchools }}             │
│      - {{ $totalUsers }}                │
│      - @forelse($activities)            │
└─────────┬───────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────┐
│   HTML Response                         │
│   - Sidebar (navigation)                │
│   - Navbar (user info)                  │
│   - Content (stats + charts + table)    │
│   - Footer (copyright)                  │
└─────────┬───────────────────────────────┘
          │
          ▼
┌──────────────┐
│   Browser    │
│   Display    │
└──────────────┘
```

---

## 🗺️ Menu Navigation Map

```
Super Admin Dashboard
│
├── 📊 Dashboard Utama
│   ├── Ringkasan sistem global          → dashboard.overview
│   ├── Statistik sekolah aktif          → dashboard.school-stats
│   └── Aktivitas terbaru                → dashboard.activity
│
├── 🏫 Manajemen Sekolah
│   ├── Daftar Sekolah                   → schools.list
│   ├── Aktivasi Sekolah                 → schools.activation
│   └── Paket & Limit                    → schools.packages
│
├── 👥 Manajemen Pengguna Global
│   ├── Super Admin                      → users.superadmin
│   └── Support/Admin Internal           → users.support
│
├── 🔍 Monitoring Sistem
│   ├── System Health                    → monitoring.health
│   ├── Error Logs                       → monitoring.logs
│   └── Queue Status                     → monitoring.queue
│
├── 🛡️ Security Monitoring Global
│   ├── Security Events                  → security.events
│   ├── Suspicious Activity              → security.suspicious
│   └── API Abuse Logs                   → security.api-abuse
│
├── 💳 Billing & Subscription
│   ├── Paket Langganan                  → billing.packages
│   ├── Tagihan Sekolah                  → billing.invoices
│   └── Riwayat Pembayaran               → billing.history
│
├── 📈 Laporan Global
│   ├── Statistik Penggunaan             → reports.usage
│   └── Rekap Absensi Global             → reports.attendance
│
└── ⚙️ Pengaturan Sistem
    ├── Feature Flags                    → settings.flags
    └── Maintenance Mode                 → settings.maintenance
```

---

## 🎨 Layout Structure

```
┌────────────────────────────────────────────────────────────────┐
│                         BROWSER WINDOW                         │
├────────────┬───────────────────────────────────────────────────┤
│            │  ┌─────────────────────────────────────────────┐  │
│            │  │         TOP NAVBAR (navbar.blade.php)       │  │
│            │  │  [☰] [Search...] [🔔3] [📧7] [SA ▼]        │  │
│            │  └─────────────────────────────────────────────┘  │
│            │                                                    │
│  SIDEBAR   │  ┌─────────────────────────────────────────────┐  │
│  (sidebar  │  │                                             │  │
│  .blade    │  │         MAIN CONTENT AREA                   │  │
│  .php)     │  │         (@yield('content'))                 │  │
│            │  │                                             │  │
│  ┌──────┐  │  │  ┌───────────────────────────────────────┐ │  │
│  │ Logo │  │  │  │  Page Title                           │ │  │
│  └──────┘  │  │  └───────────────────────────────────────┘ │  │
│            │  │                                             │  │
│  📊 Menu1  │  │  ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐          │  │
│   • Sub1   │  │  │Card1│ │Card2│ │Card3│ │Card4│          │  │
│   • Sub2   │  │  └─────┘ └─────┘ └─────┘ └─────┘          │  │
│            │  │                                             │  │
│  🏫 Menu2  │  │  ┌──────────────┐ ┌──────────────┐         │  │
│   • Sub1   │  │  │   Chart 1    │ │   Chart 2    │         │  │
│   • Sub2   │  │  └──────────────┘ └──────────────┘         │  │
│            │  │                                             │  │
│  👥 Menu3  │  │  ┌─────────────────────────────────────┐   │  │
│            │  │  │     Activity Logs Table             │   │  │
│  🔍 Menu4  │  │  │  Time | School | Action | Status    │   │  │
│            │  │  │  ─────────────────────────────────   │   │  │
│  🛡️ Menu5  │  │  │  09:45 | SMA 1  | Login  | ✓       │   │  │
│            │  │  │  09:32 | SMP IT | QR Gen | ✓       │   │  │
│  💳 Menu6  │  │  └─────────────────────────────────────┘   │  │
│            │  │                                             │  │
│  📈 Menu7  │  └─────────────────────────────────────────────┘  │
│            │                                                    │
│  ⚙️ Menu8  │  ┌─────────────────────────────────────────────┐  │
│            │  │      FOOTER (footer.blade.php)              │  │
│  ┌──────┐  │  │  © 2026 AbsensiQR Pro. All rights reserved. │  │
│  │Online│  │  └─────────────────────────────────────────────┘  │
│  └──────┘  │                                                    │
└────────────┴────────────────────────────────────────────────────┘
```

---

## 📱 Responsive Breakpoints

```
Mobile (< 768px)
┌──────────────────┐
│   [☰] [🔔] [👤]  │  ← Navbar (compact)
├──────────────────┤
│                  │
│  Main Content    │  ← Full width
│  (Sidebar hidden)│
│                  │
│  Stats Cards     │  ← 1 column
│  (stacked)       │
│                  │
└──────────────────┘

Tablet (768px - 1024px)
┌────────┬─────────────────┐
│        │ [Search] [🔔][👤]│  ← Navbar
│ Side   ├─────────────────┤
│ bar    │                 │
│        │  Main Content   │  ← Wider
│ Menu1  │                 │
│ Menu2  │  Stats Cards    │  ← 2 columns
│ Menu3  │  (2x2 grid)     │
│        │                 │
└────────┴─────────────────┘

Desktop (> 1024px)
┌──────────┬───────────────────────────┐
│          │ [Search...] [🔔] [📧] [👤]│  ← Full navbar
│  Side    ├───────────────────────────┤
│  bar     │                           │
│          │    Main Content           │  ← Full width
│  Menu1   │                           │
│   • Sub1 │  ┌───┐ ┌───┐ ┌───┐ ┌───┐ │  ← 4 columns
│   • Sub2 │  │C1 │ │C2 │ │C3 │ │C4 │ │
│          │  └───┘ └───┘ └───┘ └───┘ │
│  Menu2   │                           │
│   • Sub1 │  ┌─────────┐ ┌─────────┐ │  ← 2 columns
│   • Sub2 │  │ Chart 1 │ │ Chart 2 │ │
│          │  └─────────┘ └─────────┘ │
│  Menu3   │                           │
│          │  ┌─────────────────────┐ │  ← Full width
│  [Status]│  │   Activity Table    │ │
└──────────┴──┴─────────────────────┴─┘
```

---

## 🔐 Authentication Flow

```
┌─────────────┐
│   Login     │
│   Page      │
└──────┬──────┘
       │
       ▼
┌──────────────────────┐
│  Validate            │
│  Credentials         │
└──────┬───────────────┘
       │
       ├─── ✗ Invalid ──────┐
       │                    │
       ▼                    ▼
┌──────────────┐    ┌──────────────┐
│  Check Role  │    │ Error Message│
└──────┬───────┘    └──────────────┘
       │
       ├─── Not Super Admin ──┐
       │                      │
       ▼                      ▼
┌──────────────┐    ┌──────────────┐
│ Super Admin? │    │ 403 Forbidden│
└──────┬───────┘    └──────────────┘
       │
       ▼ ✓
┌──────────────────────┐
│  Set Session         │
│  auth()->user()      │
└──────┬───────────────┘
       │
       ▼
┌──────────────────────┐
│  Redirect to         │
│  Dashboard           │
└──────┬───────────────┘
       │
       ▼
┌──────────────────────┐
│  Dashboard Overview  │
│  (Logged In)         │
└──────────────────────┘
```

---

## 🗄️ Database Schema

```
┌─────────────────────────────────────────┐
│              users                      │
├─────────────────────────────────────────┤
│ id                 BIGINT PK            │
│ name               VARCHAR(255)         │
│ email              VARCHAR(255) UNIQUE  │
│ password           VARCHAR(255)         │
│ role               VARCHAR(50)          │  ← 'super-admin'
│ email_verified_at  TIMESTAMP NULL       │
│ created_at         TIMESTAMP            │
│ updated_at         TIMESTAMP            │
└─────────────────────────────────────────┘
                    │
                    │ 1:N
                    ▼
┌─────────────────────────────────────────┐
│            activities                   │
├─────────────────────────────────────────┤
│ id                 BIGINT PK            │
│ user_id            BIGINT FK            │  → users.id
│ school_id          BIGINT FK            │  → schools.id
│ action             VARCHAR(255)         │
│ status             ENUM                 │  (success/warning/error)
│ ip_address         VARCHAR(45)          │
│ user_agent         TEXT                 │
│ created_at         TIMESTAMP            │
│ updated_at         TIMESTAMP            │
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│          security_events                │
├─────────────────────────────────────────┤
│ id                 BIGINT PK            │
│ type               VARCHAR(100)         │
│ severity           ENUM                 │  (low/medium/high/critical)
│ ip_address         VARCHAR(45)          │
│ user_id            BIGINT FK NULL       │  → users.id
│ description        TEXT                 │
│ resolved_at        TIMESTAMP NULL       │
│ created_at         TIMESTAMP            │
│ updated_at         TIMESTAMP            │
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│             schools                     │
├─────────────────────────────────────────┤
│ id                 BIGINT PK            │
│ name               VARCHAR(255)         │
│ address            TEXT                 │
│ package            VARCHAR(50)          │  (Basic/Professional/Enterprise)
│ is_active          BOOLEAN              │
│ created_at         TIMESTAMP            │
│ updated_at         TIMESTAMP            │
└─────────────────────────────────────────┘
```

---

## 🎯 Component Interaction

```
User Action
    │
    ▼
┌───────────────┐
│   Sidebar     │ ──── Click Menu ────▶ Update URL
│   Component   │                       (route change)
└───────────────┘
                                            │
                                            ▼
                                    ┌───────────────┐
                                    │  Controller   │
                                    │  Fetch Data   │
                                    └───────┬───────┘
                                            │
                                            ▼
                                    ┌───────────────┐
                                    │  Blade View   │
                                    │  Render HTML  │
                                    └───────┬───────┘
                                            │
                                            ▼
┌───────────────┐                   ┌───────────────┐
│   Navbar      │ ◀──── Update ──── │  Main Content │
│   Component   │       Display     │   Component   │
└───────────────┘                   └───────────────┘
        │
        ▼
User sees updated data
```

---

**Created:** 2026-02-04  
**Purpose:** Visual reference untuk struktur dashboard  
**Status:** ✅ Complete
