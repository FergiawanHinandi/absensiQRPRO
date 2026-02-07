# Analisis Dashboard Super Admin Baru (NewDashboard.tsx)

**Tanggal Analisis:** 2026-02-04  
**File Utama:** `frontend-web/src/pages/SuperAdmin/NewDashboard.tsx`  
**Total Baris Kode:** 1,342 baris  
**Ukuran File:** 81,856 bytes

---

## 📋 Executive Summary

Dashboard Super Admin baru adalah **komponen React standalone** yang menggunakan **Tailwind CSS** dan **Framer Motion** untuk animasi. Dashboard ini **TIDAK** menggunakan layout global aplikasi dan berdiri sendiri dengan sidebar dan navbar-nya sendiri.

---

## 🏗️ 1. STRUKTUR FILE LAYOUT UTAMA

### File Layout
- **File:** `NewDashboard.tsx` (Single Page Component)
- **Tipe:** Komponen React fungsional dengan hooks
- **Routing:** Terdaftar di `App.tsx` pada route `/new-dashboard`

### Struktur Komponen Utama
```
NewDashboard (Root Component)
├── Sidebar (Fixed/Collapsible)
├── Main Content Area
│   ├── Top Navbar
│   ├── Page Content (Dynamic)
│   └── Footer
└── Mobile Overlay
```

---

## 🧩 2. STRUKTUR SIDEBAR

### Lokasi Kode
**Baris:** 1174-1264

### Komponen Sidebar
```tsx
<motion.div className="fixed md:static z-40 h-full w-64 md:w-72 bg-gray-900">
  {/* Header */}
  <div className="p-4 border-b border-gray-800">
    - Logo: Gradient box dengan huruf "A"
    - Judul: "AbsensiQR Pro"
    - Subtitle: "Super Admin Dashboard"
  </div>

  {/* Navigation Menu */}
  <nav className="mt-2 px-2">
    - Menu items dengan submenu (collapsible)
    - Animasi expand/collapse
  </nav>

  {/* Footer Status */}
  <div className="absolute bottom-0">
    - Status: "Sistem Online" (dengan pulse animation)
    - Version: "v2.5.1"
  </div>
</motion.div>
```

### Menu Struktur (8 Menu Utama)
1. **Dashboard Utama** 📊
   - Ringkasan sistem global
   - Statistik sekolah aktif
   - Aktivitas terbaru

2. **Manajemen Sekolah** 🏫
   - Daftar Sekolah
   - Aktivasi Sekolah
   - Paket & Limit

3. **Manajemen Pengguna Global** 👥
   - Super Admin
   - Support/Admin Internal

4. **Monitoring Sistem** 🔍
   - System Health
   - Error Logs
   - Queue Status

5. **Security Monitoring Global** 🛡️
   - Security Events
   - Suspicious Activity
   - API Abuse Logs

6. **Billing & Subscription** 💳
   - Paket Langganan
   - Tagihan Sekolah
   - Riwayat Pembayaran

7. **Laporan Global** 📈
   - Statistik Penggunaan
   - Rekap Absensi Global

8. **Pengaturan Sistem** ⚙️
   - Feature Flags
   - Maintenance Mode

### State Management Sidebar
```typescript
const [sidebarOpen, setSidebarOpen] = useState(false);
const [expandedMenus, setExpandedMenus] = useState({
  dashboard: true,
  schools: false,
  users: false,
  monitoring: false,
  security: false,
  billing: false,
  reports: false,
  settings: false
});
```

---

## 🔝 3. STRUKTUR TOP NAVBAR

### Lokasi Kode
**Baris:** 1270-1310

### Komponen Navbar
```tsx
<header className="bg-gray-900 border-b border-gray-800 p-4">
  {/* Left Section */}
  <div className="flex items-center">
    - Hamburger Menu (Mobile)
    - Search Bar (Desktop only)
  </div>

  {/* Right Section */}
  <div className="flex items-center space-x-4">
    - Notification Bell (Badge: 3)
    - Messages Icon (Badge: 7)
    - User Profile Dropdown
      * Avatar: "SA" (Super Admin)
      * Email: admin@absensiqrpro.com
  </div>
</header>
```

### Fitur Navbar
- **Responsive:** Hamburger menu untuk mobile
- **Search:** Input search (belum fungsional)
- **Notifications:** Badge dengan animasi pulse
- **User Profile:** Avatar dengan gradient background

---

## 📊 4. STRUKTUR CARD / WIDGET STATISTIK

### Tipe Card yang Digunakan

#### A. Stats Card (Dashboard Overview)
**Lokasi:** Baris 210-245

```tsx
<motion.div className="bg-gray-800 rounded-xl p-6 border border-gray-700">
  <div className="flex items-center justify-between">
    <div>
      <p className="text-gray-400 text-sm">{title}</p>
      <p className="text-2xl font-bold mt-1">{value}</p>
      <p className="text-xs text-gray-500 mt-1">{subtitle}</p>
    </div>
    <div className={`w-12 h-12 rounded-full ${color}`}>
      {icon}
    </div>
  </div>
  {/* Progress Bar */}
  <div className="mt-4 h-2 bg-gray-700 rounded-full">
    <motion.div className={`h-full ${color}`} />
  </div>
</motion.div>
```

**Data Dummy:**
- Sekolah Aktif: 87 (dari 128 sekolah terdaftar)
- Total Pengguna: 15,420 (Siswa, guru & admin)
- Event Keamanan: 12 (24 jam terakhir)
- Uptime Sistem: "15d 7h 22m"

#### B. Metric Card (System Health)
**Lokasi:** Baris 647-708

```tsx
<motion.div className="bg-gray-700 p-5 rounded-lg border border-gray-600">
  <div className="flex items-center mb-3">
    <div className={`w-10 h-10 rounded-full ${color}`}>
      {icon}
    </div>
    <div>
      <p className="text-gray-400 text-sm">{label}</p>
      <p className="text-2xl font-bold">{value}%</p>
    </div>
  </div>
  {/* Progress Bar */}
  <div className="w-full bg-gray-600 rounded-full h-2.5">
    <motion.div className={`h-2.5 rounded-full ${color}`} />
  </div>
</motion.div>
```

**Data Dummy (Real-time):**
- CPU Usage: 45% (update setiap 3 detik)
- Memory Usage: 62% (update setiap 3 detik)
- Disk Usage: 38% (bertambah 0.1% per interval)
- System Uptime: "15d 7h 22m"

#### C. Package Card (Billing)
**Lokasi:** Baris 890-917

```tsx
<motion.div className="rounded-xl p-6 border bg-gray-800">
  {/* Popular Badge */}
  <div className="absolute top-0 bg-blue-500">Most Popular</div>
  
  <h3 className="text-xl font-bold">{name}</h3>
  <div>
    <span className="text-3xl font-bold">{price}</span>
    <span className="text-gray-400">{period}</span>
  </div>
  
  {/* Features List */}
  <ul className="space-y-3">
    {features.map(feature => (
      <li>✓ {feature}</li>
    ))}
  </ul>
  
  <button>Edit Paket</button>
</motion.div>
```

**Data Dummy:**
- Basic: Rp 500rb/bulan (Max 500 Siswa)
- Professional: Rp 1.2jt/bulan (Max 2000 Siswa) - POPULAR
- Enterprise: Hubungi (Unlimited Siswa)

---

## 📋 5. STRUKTUR TABEL DATA

### Tipe Tabel yang Digunakan

#### A. Activity Table (Recent Activities)
**Lokasi:** Baris 350-395

```tsx
<table className="min-w-full divide-y divide-gray-700">
  <thead>
    <tr>
      <th>Waktu</th>
      <th>Sekolah</th>
      <th>Aksi</th>
      <th>Pengguna</th>
      <th>Status</th>
    </tr>
  </thead>
  <tbody className="divide-y divide-gray-700">
    {activities.map(activity => (
      <motion.tr className="hover:bg-gray-750">
        <td className="text-blue-400">{time}</td>
        <td className="font-medium">{school}</td>
        <td>
          <span className="px-2 py-1 rounded-full">{action}</span>
        </td>
        <td className="text-gray-300">{user}</td>
        <td>
          {status === 'success' && <span className="text-green-400">✓ Berhasil</span>}
          {status === 'warning' && <span className="text-yellow-400">⚠️ Peringatan</span>}
          {status === 'error' && <span className="text-red-400">✗ Gagal</span>}
        </td>
      </motion.tr>
    ))}
  </tbody>
</table>
```

**Data Dummy (6 entries):**
- Login Admin, Generate QR Code, Export Report, Update Profile, New Absence, Failed Login

#### B. School List Table
**Lokasi:** Baris 538-620

```tsx
<table className="min-w-full divide-y divide-gray-700">
  <thead className="bg-gray-700">
    <tr>
      <th>Nama Sekolah</th>
      <th>Alamat</th>
      <th>Paket</th>
      <th>Pengguna</th>
      <th>Terakhir Aktif</th>
      <th>Status</th>
      <th>Aksi</th>
    </tr>
  </thead>
  <tbody>
    {schools.map(school => (
      <tr className="hover:bg-gray-750">
        <td className="font-medium">{name}</td>
        <td className="text-gray-300 text-sm">{address}</td>
        <td>
          <span className="px-2 py-1 rounded-full">{package}</span>
        </td>
        <td className="text-gray-300">{users}</td>
        <td className="text-sm text-gray-400">{lastActive}</td>
        <td>
          <span className="px-2 py-1 rounded-full">{status}</span>
        </td>
        <td>
          <button>✏️</button>
          <button>👁️</button>
          <button>🗑️</button>
        </td>
      </tr>
    ))}
  </tbody>
</table>

{/* Pagination */}
<div className="mt-6 flex justify-between">
  <p>Menampilkan 1-5 dari 128 sekolah</p>
  <div className="flex space-x-2">
    <button>Sebelumnya</button>
    <button>1</button>
    <button>2</button>
    <button>3</button>
    <button>Selanjutnya</button>
  </div>
</div>
```

**Data Dummy (5 schools):**
- SMA Negeri 1 Jakarta, SMP Islam Terpadu, SD Budi Luhur, SMK Teknologi, SMA Negeri 5 Bandung

#### C. Transaction Table (Billing)
**Lokasi:** Baris 924-959

```tsx
<table className="min-w-full divide-y divide-gray-700">
  <thead>
    <tr>
      <th>Invoice ID</th>
      <th>Sekolah</th>
      <th>Paket</th>
      <th>Jumlah</th>
      <th>Tanggal</th>
      <th>Status</th>
    </tr>
  </thead>
  <tbody>
    {invoices.map(inv => (
      <tr className="hover:bg-gray-750">
        <td className="font-mono text-gray-400">{id}</td>
        <td className="font-medium">{school}</td>
        <td className="text-gray-300">{package}</td>
        <td className="font-medium">{amount}</td>
        <td className="text-gray-400">{date}</td>
        <td>
          <span className="px-2 py-1 rounded-full">
            {status.toUpperCase()}
          </span>
        </td>
      </tr>
    ))}
  </tbody>
</table>
```

**Data Dummy (4 invoices):**
- INV-2023-001 hingga INV-2023-004 dengan status: paid, pending, failed

---

## 📝 6. STRUKTUR FORM

### Form Elements yang Digunakan

#### A. Search Input
**Lokasi:** Baris 506-511, 1281-1286

```tsx
<div className="relative">
  <input
    type="text"
    placeholder="Cari sekolah..."
    className="bg-gray-700 text-gray-200 rounded-lg py-2 px-4 w-full md:w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
  />
  <div className="absolute right-3 top-2.5 text-gray-400">🔍</div>
</div>
```

**Status:** Visual only (belum fungsional)

#### B. Toggle Switch (Feature Flags)
**Lokasi:** Baris 1070-1073

```tsx
<label className="relative inline-flex items-center cursor-pointer">
  <input type="checkbox" className="sr-only peer" checked={status} readOnly />
  <div className="w-11 h-6 bg-gray-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
</label>
```

**Data Dummy (5 feature flags):**
- Face Recognition Attendance
- GPS Geofencing Strict Mode
- AI Attendance Analytics (BETA)
- WhatsApp Gateway v2 (BETA)
- Force Dark Mode

#### C. Action Buttons
**Berbagai lokasi:**

```tsx
{/* Primary Button */}
<button className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
  ➕ Tambah Sekolah
</button>

{/* Danger Button */}
<button className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg">
  Aktifkan
</button>

{/* Warning Button */}
<button className="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg">
  Clear Cache
</button>

{/* Icon Buttons */}
<button className="text-blue-400 hover:text-blue-300 text-lg">✏️</button>
<button className="text-green-400 hover:text-green-300 text-lg">👁️</button>
<button className="text-red-400 hover:text-red-300 text-lg">🗑️</button>
```

**Status:** Semua button visual only (belum ada event handler)

---

## 🎨 7. FILE CSS YANG DIGUNAKAN

### A. Tailwind CSS (Primary)
**File:** `src/index.css`

```css
@import "tailwindcss";

@theme {
  /* Custom Color Palette */
  --color-primary-50: #eff6ff;
  --color-primary-100: #dbeafe;
  --color-primary-200: #bfdbfe;
  --color-primary-300: #93c5fd;
  --color-primary-400: #60a5fa;
  --color-primary-500: #3b82f6;
  --color-primary-600: #2563eb;
  --color-primary-700: #1d4ed8;
  --color-primary-800: #1e40af;
  --color-primary-900: #1e3a8a;
  
  /* Custom Font */
  --font-sans: 'Inter', system-ui, sans-serif;
}

body {
  @apply bg-gray-50 text-gray-900 font-sans antialiased;
}
```

### B. Inline Tailwind Classes
**Digunakan di seluruh komponen NewDashboard.tsx**

**Color Scheme:**
- Background: `bg-gray-900`, `bg-gray-800`, `bg-gray-700`
- Text: `text-gray-100`, `text-gray-300`, `text-gray-400`
- Borders: `border-gray-700`, `border-gray-800`
- Accents: `bg-blue-600`, `bg-green-500`, `bg-yellow-500`, `bg-red-500`, `bg-purple-500`

**Layout Classes:**
- Flexbox: `flex`, `flex-col`, `items-center`, `justify-between`
- Grid: `grid`, `grid-cols-1`, `md:grid-cols-2`, `lg:grid-cols-4`
- Spacing: `space-y-6`, `gap-6`, `p-4`, `p-6`, `mb-4`
- Responsive: `md:`, `lg:` prefixes

**Effects:**
- Shadows: `shadow-lg`, `shadow-xl`
- Rounded: `rounded-xl`, `rounded-lg`, `rounded-full`
- Transitions: `transition-colors`, `transition-all`
- Hover: `hover:bg-gray-800`, `hover:bg-blue-700`

### C. App.css (Tidak Digunakan)
**File:** `src/App.css`  
**Status:** File ini untuk default Vite template, TIDAK digunakan di NewDashboard

---

## 💻 8. FILE JAVASCRIPT YANG DIGUNAKAN

### A. React & Hooks
```typescript
import { useState, useEffect } from 'react';
```

**Hooks yang Digunakan:**
- `useState`: 6 state variables
- `useEffect`: 3 effects (loading, system health updates, counter animations)

### B. Framer Motion
```typescript
import { motion, AnimatePresence } from "framer-motion";
```

**Animasi yang Digunakan:**
- **Fade In/Out:** `initial={{ opacity: 0 }}`, `animate={{ opacity: 1 }}`
- **Slide:** `initial={{ y: 20 }}`, `animate={{ y: 0 }}`
- **Hover Effects:** `whileHover={{ y: -5 }}`
- **Tap Effects:** `whileTap={{ scale: 0.98 }}`
- **Sidebar Animation:** `initial={{ x: -300 }}`, `animate={{ x: 0 }}`
- **Progress Bars:** `initial={{ width: 0 }}`, `animate={{ width: '75%' }}`

### C. Recharts
```typescript
import { 
  BarChart, Bar, 
  XAxis, YAxis, 
  CartesianGrid, Tooltip, Legend, 
  PieChart, Pie, Cell, 
  LineChart, Line, 
  ResponsiveContainer, 
  AreaChart, Area 
} from "recharts";
```

**Chart Types:**
1. **LineChart** - School Growth (Baris 261-278)
2. **PieChart** - Package Distribution (Baris 296-323)
3. **AreaChart** - School Stats (Baris 427-449)
4. **LineChart (Dual Axis)** - Performance Metrics (Baris 766-802)
5. **BarChart** - Attendance Data (Baris 973-989)

---

## 📦 9. LIBRARY YANG DIPAKAI

### Dependencies dari package.json

#### UI & Styling
- **tailwindcss**: ^4.1.18 - Utility-first CSS framework
- **@tailwindcss/vite**: ^4.1.18 - Vite plugin untuk Tailwind
- **autoprefixer**: ^10.4.23 - PostCSS plugin
- **postcss**: ^8.5.6 - CSS processor

#### Animation
- **framer-motion**: ^12.30.0 - Production-ready animation library

#### Charts & Visualization
- **recharts**: ^3.7.0 - Composable charting library

#### React Core
- **react**: ^19.2.0
- **react-dom**: ^19.2.0
- **react-router-dom**: ^7.12.0

#### Icons
- **lucide-react**: ^0.562.0 - Icon library (tidak digunakan di NewDashboard, pakai emoji)

#### State Management
- **zustand**: ^5.0.10 - State management (tidak digunakan di NewDashboard)

#### HTTP & Real-time
- **axios**: ^1.13.2 - HTTP client (tidak digunakan di NewDashboard)
- **@tanstack/react-query**: ^5.90.19 - Data fetching (tidak digunakan di NewDashboard)

#### Utilities
- **date-fns**: ^4.1.0 - Date utility (tidak digunakan di NewDashboard)

### Library yang TIDAK Digunakan di NewDashboard
- ❌ lucide-react (pakai emoji sebagai gantinya)
- ❌ zustand (pakai useState lokal)
- ❌ axios (semua data dummy hardcoded)
- ❌ react-query (tidak ada API calls)
- ❌ date-fns (tanggal hardcoded sebagai string)

---

## 🎭 10. BAGIAN VISUAL vs LOGIC

### A. BAGIAN YANG HANYA VISUAL (UI Only)

#### 1. Semua Chart Components
**Status:** ✅ Visual Complete, ❌ No Real Data

```typescript
// Baris 30-51: Data dummy hardcoded
const schoolGrowthData = [
  { month: 'Jan', schools: 45 },
  { month: 'Feb', schools: 52 },
  // ...
];

const packageDistribution = [
  { name: 'Basic', value: 35 },
  { name: 'Professional', value: 42 },
  { name: 'Enterprise', value: 10 }
];

const attendanceData = [
  { day: 'Senin', hadir: 4250, izin: 320, alpha: 180 },
  // ...
];
```

**Yang Perlu Dihubungkan:**
- API endpoint untuk data sekolah
- API endpoint untuk distribusi paket
- API endpoint untuk data absensi global

#### 2. Semua Tabel Data
**Status:** ✅ Visual Complete, ❌ No Real Data, ❌ No Pagination

```typescript
// Baris 361-367: Activity logs dummy
{ time: '09:45:23', school: 'SMA Negeri 1 Jakarta', action: 'Login Admin', ... }

// Baris 466-471: School ranking dummy
{ rank: 1, name: 'SMA Negeri 1 Jakarta', package: 'Enterprise', ... }

// Baris 551-591: School list dummy
{ name: 'SMA Negeri 1 Jakarta', address: 'Jl. Budi Utomo No.7', ... }

// Baris 936-940: Invoice dummy
{ id: 'INV-2023-001', school: 'SMA Negeri 1 Jakarta', ... }
```

**Yang Perlu Dihubungkan:**
- API endpoint untuk activity logs
- API endpoint untuk school list dengan pagination
- API endpoint untuk invoices
- Event handlers untuk pagination buttons

#### 3. Search & Filter
**Status:** ✅ Visual Complete, ❌ No Functionality

```typescript
// Baris 506-511, 1281-1286: Search input
<input
  type="text"
  placeholder="Cari sekolah..."
  className="..."
/>
```

**Yang Perlu Ditambahkan:**
- `onChange` handler
- Search state management
- API call dengan query parameter
- Debounce untuk performa

#### 4. Action Buttons
**Status:** ✅ Visual Complete, ❌ No Event Handlers

```typescript
// Baris 513-515: Tambah Sekolah
<button className="bg-blue-600 hover:bg-blue-700">
  <span className="mr-2">➕</span>Tambah Sekolah
</button>

// Baris 612-614: CRUD buttons
<button className="text-blue-400">✏️</button>
<button className="text-green-400">👁️</button>
<button className="text-red-400">🗑️</button>

// Baris 1092-1094: Maintenance Mode
<button className="px-4 py-2 bg-red-600">
  Aktifkan
</button>
```

**Yang Perlu Ditambahkan:**
- Modal components untuk create/edit
- Delete confirmation dialog
- API calls untuk CRUD operations
- Toast notifications untuk feedback

#### 5. Feature Flags Toggle
**Status:** ✅ Visual Complete, ❌ Read-only

```typescript
// Baris 1051-1056: Feature flags dummy
{ id: 'face_recognition', name: 'Face Recognition Attendance', status: true, beta: false }

// Baris 1071: Toggle input
<input type="checkbox" className="sr-only peer" checked={status} readOnly />
```

**Yang Perlu Ditambahkan:**
- `onChange` handler
- API call untuk update feature flag
- Optimistic UI update
- Confirmation untuk critical flags

#### 6. Notification & Messages
**Status:** ✅ Visual Complete, ❌ No Functionality

```typescript
// Baris 1291-1298: Notification icons
<button className="p-2 hover:bg-gray-800 rounded-lg relative">
  🔔
  <span className="absolute top-1 right-1 bg-red-500 w-5 h-5 rounded-full animate-pulse">3</span>
</button>
```

**Yang Perlu Ditambahkan:**
- Dropdown panel untuk notifications
- API endpoint untuk notification list
- Mark as read functionality
- Real-time updates (WebSocket/Pusher)

### B. BAGIAN YANG MENGANDUNG LOGIC

#### 1. Loading State
**Status:** ✅ Functional

```typescript
// Baris 27, 56-59: Loading simulation
const [loading, setLoading] = useState(true);

useEffect(() => {
  const timer = setTimeout(() => setLoading(false), 1200);
  return () => clearTimeout(timer);
}, []);

// Baris 1112-1123: Loading UI
if (loading) {
  return (
    <div className="flex items-center justify-center h-full">
      <div className="w-16 h-16 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
      <p>Memuat Dashboard...</p>
    </div>
  );
}
```

**Catatan:** Ini simulasi loading, perlu diganti dengan real API loading state

#### 2. Real-time System Health Updates
**Status:** ✅ Functional (Simulated)

```typescript
// Baris 18-23, 62-73: System health state & updates
const [systemHealth, setSystemHealth] = useState({
  cpu: 45,
  memory: 62,
  disk: 38,
  uptime: '15d 7h 22m'
});

useEffect(() => {
  const interval = setInterval(() => {
    setSystemHealth(prev => ({
      cpu: Math.max(20, Math.min(90, prev.cpu + (Math.random() - 0.5) * 10)),
      memory: Math.max(30, Math.min(85, prev.memory + (Math.random() - 0.5) * 8)),
      disk: Math.min(95, prev.disk + 0.1),
      uptime: prev.uptime
    }));
  }, 3000);

  return () => clearInterval(interval);
}, []);
```

**Catatan:** Ini simulasi, perlu diganti dengan WebSocket untuk real-time data

#### 3. Counter Animations
**Status:** ✅ Functional

```typescript
// Baris 76-103: Animated counters
useEffect(() => {
  if (loading) return;

  let schoolCount = 0;
  const schoolInterval = setInterval(() => {
    schoolCount += 3;
    if (schoolCount >= activeSchools) {
      clearInterval(schoolInterval);
      schoolCount = activeSchools;
    }
    setActiveSchools(schoolCount);
  }, 50);

  // Similar for userCount...

  return () => {
    clearInterval(schoolInterval);
    clearInterval(userInterval);
  };
}, [loading]);
```

**Catatan:** Ini animasi visual, data final tetap hardcoded

#### 4. Menu Navigation
**Status:** ✅ Functional

```typescript
// Baris 6, 186-191: Active menu state
const [activeMenu, setActiveMenu] = useState('dashboard-overview');

const toggleMenu = (menuId: string) => {
  setExpandedMenus((prev: any) => ({
    ...prev,
    [menuId]: !prev[menuId as keyof typeof prev]
  }));
};

// Baris 1126-1169: Content routing
switch (activeMenu) {
  case 'dashboard-overview': return renderDashboardOverview();
  case 'dashboard-school-stats': return renderSchoolStats();
  case 'schools-list': return renderSchoolsList();
  case 'monitoring-health': return renderSystemHealth();
  case 'security-events': return renderSecurityEvents();
  case 'billing-packages': return renderBillingPackages();
  case 'reports-attendance': return renderAttendanceReport();
  case 'settings-flags': return renderFeatureFlags();
  default: return <PlaceholderContent />;
}
```

**Catatan:** Routing lokal, tidak terintegrasi dengan React Router

#### 5. Sidebar Toggle (Mobile)
**Status:** ✅ Functional

```typescript
// Baris 7: Sidebar state
const [sidebarOpen, setSidebarOpen] = useState(false);

// Baris 1272-1279: Hamburger button
<button onClick={() => setSidebarOpen(!sidebarOpen)}>
  <div className="w-6 h-0.5 bg-blue-400"></div>
  <div className="w-6 h-0.5 bg-blue-400"></div>
  <div className="w-6 h-0.5 bg-blue-400"></div>
</button>

// Baris 1333-1338: Mobile overlay
{sidebarOpen && (
  <div
    className="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden"
    onClick={() => setSidebarOpen(false)}
  />
)}
```

**Catatan:** Fully functional untuk mobile responsiveness

---

## 🗑️ 11. DATA DUMMY YANG HARUS DIHAPUS

### A. Mock Data Arrays

#### 1. School Growth Data
**Lokasi:** Baris 30-37  
**Hapus:** ✅

```typescript
const schoolGrowthData = [
  { month: 'Jan', schools: 45 },
  { month: 'Feb', schools: 52 },
  { month: 'Mar', schools: 61 },
  { month: 'Apr', schools: 68 },
  { month: 'May', schools: 75 },
  { month: 'Jun', schools: 87 }
];
```

**Ganti dengan:** API call ke `/api/super-admin/analytics/school-growth`

#### 2. Package Distribution
**Lokasi:** Baris 39-43  
**Hapus:** ✅

```typescript
const packageDistribution = [
  { name: 'Basic', value: 35 },
  { name: 'Professional', value: 42 },
  { name: 'Enterprise', value: 10 }
];
```

**Ganti dengan:** API call ke `/api/super-admin/analytics/package-distribution`

#### 3. Attendance Data
**Lokasi:** Baris 45-51  
**Hapus:** ✅

```typescript
const attendanceData = [
  { day: 'Senin', hadir: 4250, izin: 320, alpha: 180 },
  { day: 'Selasa', hadir: 4380, izin: 290, alpha: 150 },
  // ...
];
```

**Ganti dengan:** API call ke `/api/super-admin/analytics/attendance-weekly`

#### 4. Activity Logs
**Lokasi:** Baris 361-367  
**Hapus:** ✅

```typescript
[
  { time: '09:45:23', school: 'SMA Negeri 1 Jakarta', action: 'Login Admin', user: 'admin@smajkt.sch.id', status: 'success' },
  { time: '09:32:17', school: 'SMP Islam Terpadu', action: 'Generate QR Code', user: 'guru@smpit.sch.id', status: 'success' },
  // ... 4 more entries
]
```

**Ganti dengan:** API call ke `/api/super-admin/activity-logs?limit=6`

#### 5. School Ranking
**Lokasi:** Baris 466-471  
**Hapus:** ✅

```typescript
[
  { rank: 1, name: 'SMA Negeri 1 Jakarta', package: 'Enterprise', users: 1250, attendance: 8740, status: 'Aktif' },
  { rank: 2, name: 'SMA Negeri 5 Bandung', package: 'Enterprise', users: 1100, attendance: 7650, status: 'Aktif' },
  // ... 3 more entries
]
```

**Ganti dengan:** API call ke `/api/super-admin/schools/ranking?limit=5`

#### 6. School List
**Lokasi:** Baris 551-591  
**Hapus:** ✅

```typescript
[
  {
    name: 'SMA Negeri 1 Jakarta',
    address: 'Jl. Budi Utomo No.7, Jakarta Pusat',
    package: 'Enterprise',
    users: 1250,
    lastActive: '2 jam lalu',
    status: 'Aktif'
  },
  // ... 4 more schools
]
```

**Ganti dengan:** API call ke `/api/super-admin/schools?page=1&limit=5`

#### 7. Server Status
**Lokasi:** Baris 717-723  
**Hapus:** ✅

```typescript
[
  { name: 'Server Jakarta', status: 'online', load: 45, region: 'Asia Tenggara' },
  { name: 'Server Singapura', status: 'online', load: 38, region: 'Asia Pasifik' },
  { name: 'Server Frankfurt', status: 'maintenance', load: 12, region: 'Eropa' },
  // ... 3 more servers
]
```

**Ganti dengan:** API call ke `/api/super-admin/monitoring/servers`

#### 8. Performance Metrics
**Lokasi:** Baris 766-773  
**Hapus:** ✅

```typescript
[
  { time: '00:00', response: 45, errors: 2 },
  { time: '04:00', response: 38, errors: 1 },
  // ... 5 more entries
]
```

**Ganti dengan:** API call ke `/api/super-admin/monitoring/performance?period=24h`

#### 9. Security Logs
**Lokasi:** Baris 846-851  
**Hapus:** ✅

```typescript
[
  { time: '10:42:15', ip: '192.168.1.45', event: 'Brute force attempt', target: 'admin@sekolaha.sch.id', severity: 'high' },
  { time: '10:38:22', ip: '202.14.33.12', event: 'SQL Injection detected', target: '/api/v1/students', severity: 'critical' },
  // ... 3 more logs
]
```

**Ganti dengan:** API call ke `/api/super-admin/security/events?limit=5`

#### 10. Billing Packages
**Lokasi:** Baris 885-888  
**Hapus:** ✅

```typescript
[
  { name: 'Basic', price: 'Rp 500rb', period: '/bulan', features: ['Max 500 Siswa', ...] },
  { name: 'Professional', price: 'Rp 1.2jt', period: '/bulan', features: [...], popular: true },
  { name: 'Enterprise', price: 'Hubungi', period: '', features: [...] }
]
```

**Ganti dengan:** API call ke `/api/super-admin/billing/packages`

#### 11. Transaction History
**Lokasi:** Baris 936-940  
**Hapus:** ✅

```typescript
[
  { id: 'INV-2023-001', school: 'SMA Negeri 1 Jakarta', package: 'Enterprise Yearly', amount: 'Rp 15.000.000', date: '01 Feb 2026', status: 'paid' },
  { id: 'INV-2023-002', school: 'SMP Islam Terpadu', package: 'Professional Monthly', amount: 'Rp 1.200.000', date: '02 Feb 2026', status: 'paid' },
  // ... 2 more invoices
]
```

**Ganti dengan:** API call ke `/api/super-admin/billing/invoices?limit=10`

#### 12. Attendance Anomalies
**Lokasi:** Baris 1020-1023  
**Hapus:** ✅

```typescript
[
  { school: 'SD Budi Luhur', issue: 'Tingkat alpha tinggi (>15%)', date: '03 Feb 2026', status: 'investigating' },
  { school: 'SMK Teknologi', issue: 'Jam masuk tidak wajar (03:00)', date: '02 Feb 2026', status: 'resolved' },
  { school: 'SMP Islam Terpadu', issue: 'Lonjakan absensi izin', date: '01 Feb 2026', status: 'pending' }
]
```

**Ganti dengan:** API call ke `/api/super-admin/reports/anomalies`

#### 13. Feature Flags
**Lokasi:** Baris 1051-1056  
**Hapus:** ✅

```typescript
[
  { id: 'face_recognition', name: 'Face Recognition Attendance', desc: '...', status: true, beta: false },
  { id: 'location_fencing', name: 'GPS Geofencing Strict Mode', desc: '...', status: true, beta: false },
  { id: 'ai_analytics', name: 'AI Attendance Analytics', desc: '...', status: false, beta: true },
  { id: 'whatsapp_integration', name: 'WhatsApp Gateway v2', desc: '...', status: true, beta: true },
  { id: 'dark_mode_default', name: 'Force Dark Mode', desc: '...', status: false, beta: false }
]
```

**Ganti dengan:** API call ke `/api/super-admin/settings/feature-flags`

### B. Hardcoded State Values

#### 1. Initial Stats
**Lokasi:** Baris 24-26  
**Hapus:** ✅

```typescript
const [securityEvents] = useState(12);
const [activeSchools, setActiveSchools] = useState(87);
const [totalUsers, setTotalUsers] = useState(15420);
```

**Ganti dengan:** API call ke `/api/super-admin/dashboard/stats`

#### 2. System Health Initial Values
**Lokasi:** Baris 18-23  
**Hapus:** ✅

```typescript
const [systemHealth, setSystemHealth] = useState({
  cpu: 45,
  memory: 62,
  disk: 38,
  uptime: '15d 7h 22m'
});
```

**Ganti dengan:** WebSocket connection atau API polling ke `/api/super-admin/monitoring/health`

### C. Inline Dummy Text

#### 1. Stats Card Subtitles
**Lokasi:** Baris 212-215  
**Hapus:** ✅

```typescript
{ title: 'Sekolah Aktif', value: activeSchools, icon: '🏫', color: 'bg-blue-500', subtitle: 'Dari 128 sekolah terdaftar' }
```

**Ganti dengan:** Dynamic calculation dari API data

#### 2. Pagination Text
**Lokasi:** Baris 623  
**Hapus:** ✅

```typescript
<p className="text-gray-400">Menampilkan 1-5 dari 128 sekolah</p>
```

**Ganti dengan:** Dynamic dari pagination metadata

#### 3. User Profile Info
**Lokasi:** Baris 1304-1305  
**Hapus:** ✅

```typescript
<p className="text-sm font-medium">Super Admin</p>
<p className="text-xs text-gray-400">admin@absensiqrpro.com</p>
```

**Ganti dengan:** Data dari auth context/store

---

## 🔄 12. REKOMENDASI INTEGRASI

### A. State Management

#### Ganti useState dengan Zustand Store
```typescript
// stores/superAdminStore.ts
import { create } from 'zustand';

interface SuperAdminStore {
  stats: {
    activeSchools: number;
    totalUsers: number;
    securityEvents: number;
  };
  systemHealth: {
    cpu: number;
    memory: number;
    disk: number;
    uptime: string;
  };
  loading: boolean;
  fetchDashboardStats: () => Promise<void>;
  subscribeToSystemHealth: () => void;
}

export const useSuperAdminStore = create<SuperAdminStore>((set) => ({
  stats: { activeSchools: 0, totalUsers: 0, securityEvents: 0 },
  systemHealth: { cpu: 0, memory: 0, disk: 0, uptime: '' },
  loading: true,
  fetchDashboardStats: async () => {
    // API call
  },
  subscribeToSystemHealth: () => {
    // WebSocket subscription
  }
}));
```

### B. API Integration

#### Create API Service
```typescript
// services/superAdminService.ts
import axios from 'axios';

export const superAdminAPI = {
  getDashboardStats: () => axios.get('/api/super-admin/dashboard/stats'),
  getSchoolGrowth: (period: string) => axios.get(`/api/super-admin/analytics/school-growth?period=${period}`),
  getSchools: (page: number, limit: number, search?: string) => 
    axios.get(`/api/super-admin/schools?page=${page}&limit=${limit}&search=${search}`),
  getActivityLogs: (limit: number) => axios.get(`/api/super-admin/activity-logs?limit=${limit}`),
  getSecurityEvents: (limit: number) => axios.get(`/api/super-admin/security/events?limit=${limit}`),
  getInvoices: (page: number, limit: number) => axios.get(`/api/super-admin/billing/invoices?page=${page}&limit=${limit}`),
  getFeatureFlags: () => axios.get('/api/super-admin/settings/feature-flags'),
  updateFeatureFlag: (id: string, enabled: boolean) => 
    axios.patch(`/api/super-admin/settings/feature-flags/${id}`, { enabled }),
  activateMaintenanceMode: () => axios.post('/api/super-admin/settings/maintenance-mode'),
  flushCache: () => axios.post('/api/super-admin/settings/flush-cache'),
};
```

### C. Real-time Updates

#### WebSocket Integration
```typescript
// hooks/useSystemHealthSocket.ts
import { useEffect } from 'react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export const useSystemHealthSocket = (onUpdate: (data: any) => void) => {
  useEffect(() => {
    const echo = new Echo({
      broadcaster: 'pusher',
      key: import.meta.env.VITE_PUSHER_APP_KEY,
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
      forceTLS: true
    });

    echo.channel('super-admin.system-health')
      .listen('SystemHealthUpdated', (data: any) => {
        onUpdate(data);
      });

    return () => {
      echo.leaveChannel('super-admin.system-health');
    };
  }, [onUpdate]);
};
```

### D. React Query Integration

#### Data Fetching Hooks
```typescript
// hooks/useSuperAdminData.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { superAdminAPI } from '../services/superAdminService';

export const useDashboardStats = () => {
  return useQuery({
    queryKey: ['super-admin', 'dashboard-stats'],
    queryFn: superAdminAPI.getDashboardStats,
    refetchInterval: 30000, // Refresh every 30 seconds
  });
};

export const useSchools = (page: number, limit: number, search?: string) => {
  return useQuery({
    queryKey: ['super-admin', 'schools', page, limit, search],
    queryFn: () => superAdminAPI.getSchools(page, limit, search),
  });
};

export const useUpdateFeatureFlag = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) => 
      superAdminAPI.updateFeatureFlag(id, enabled),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['super-admin', 'feature-flags'] });
    },
  });
};
```

---

## 📝 13. CHECKLIST MIGRASI

### Phase 1: Setup Infrastructure
- [ ] Create Zustand store untuk Super Admin state
- [ ] Setup API service dengan axios interceptors
- [ ] Configure React Query dengan proper cache settings
- [ ] Setup Laravel Echo untuk WebSocket
- [ ] Create custom hooks untuk data fetching

### Phase 2: Replace Dummy Data
- [ ] Replace `schoolGrowthData` dengan API call
- [ ] Replace `packageDistribution` dengan API call
- [ ] Replace `attendanceData` dengan API call
- [ ] Replace activity logs dengan API call
- [ ] Replace school list dengan API call + pagination
- [ ] Replace server status dengan WebSocket
- [ ] Replace security logs dengan API call
- [ ] Replace invoices dengan API call + pagination
- [ ] Replace feature flags dengan API call

### Phase 3: Add Functionality
- [ ] Implement search functionality
- [ ] Add pagination logic untuk semua tabel
- [ ] Create modal components untuk CRUD operations
- [ ] Add form validation
- [ ] Implement delete confirmation dialogs
- [ ] Add toast notifications untuk user feedback
- [ ] Implement feature flag toggle functionality
- [ ] Add maintenance mode activation dengan confirmation

### Phase 4: Real-time Features
- [ ] Setup WebSocket untuk system health updates
- [ ] Setup WebSocket untuk security events
- [ ] Setup WebSocket untuk activity logs
- [ ] Add notification dropdown dengan real data
- [ ] Implement mark as read untuk notifications

### Phase 5: Integration & Testing
- [ ] Integrate dengan existing auth system
- [ ] Add role-based access control
- [ ] Test all API endpoints
- [ ] Test WebSocket connections
- [ ] Test responsive design di berbagai device
- [ ] Performance testing dengan real data
- [ ] Error handling untuk network failures

### Phase 6: Polish & Optimization
- [ ] Add loading skeletons untuk better UX
- [ ] Optimize chart rendering performance
- [ ] Add error boundaries
- [ ] Implement retry logic untuk failed requests
- [ ] Add analytics tracking
- [ ] Documentation untuk maintenance

---

## 🎯 14. PRIORITAS DEVELOPMENT

### High Priority (Week 1-2)
1. **Dashboard Stats API** - Core metrics yang ditampilkan di overview
2. **School List API** - Tabel utama dengan pagination
3. **Authentication Integration** - User profile dan permissions
4. **Activity Logs API** - Monitoring aktivitas sistem

### Medium Priority (Week 3-4)
5. **System Health WebSocket** - Real-time monitoring
6. **Security Events API** - Security monitoring
7. **Feature Flags CRUD** - System configuration
8. **Billing & Invoices API** - Financial tracking

### Low Priority (Week 5-6)
9. **Charts & Analytics** - Data visualization
10. **Notification System** - User notifications
11. **Advanced Filters** - Search dan filter enhancement
12. **Export Features** - Report generation

---

## 📊 15. ESTIMASI EFFORT

| Task | Complexity | Estimated Time |
|------|-----------|----------------|
| Setup Infrastructure | Medium | 2-3 days |
| Replace Dummy Data | Low | 3-4 days |
| Add Functionality | High | 5-7 days |
| Real-time Features | High | 4-5 days |
| Integration & Testing | Medium | 3-4 days |
| Polish & Optimization | Medium | 2-3 days |
| **TOTAL** | - | **19-26 days** |

---

## 🚀 16. NEXT STEPS

1. **Review dengan Tim Backend**
   - Diskusikan API endpoints yang dibutuhkan
   - Tentukan struktur response data
   - Setup WebSocket channels

2. **Create API Documentation**
   - Document semua endpoint yang dibutuhkan
   - Define request/response schemas
   - Setup API testing dengan Postman/Insomnia

3. **Start Development**
   - Begin dengan Phase 1 (Infrastructure)
   - Implement incrementally per feature
   - Test setiap feature sebelum lanjut

4. **Code Review & QA**
   - Peer review untuk setiap PR
   - Manual testing di staging
   - Automated testing dengan Jest/Vitest

---

## 📞 KONTAK & SUPPORT

Untuk pertanyaan atau diskusi lebih lanjut mengenai analisis ini:
- **Developer:** [Your Name]
- **Email:** [Your Email]
- **Slack/Discord:** [Your Handle]

---

**Last Updated:** 2026-02-04  
**Document Version:** 1.0  
**Status:** ✅ Complete Analysis
