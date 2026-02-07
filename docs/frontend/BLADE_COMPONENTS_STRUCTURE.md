# Struktur Komponen Laravel Blade - Super Admin Dashboard

## 📁 Struktur File

```
backend/resources/views/
├── layouts/
│   └── admin-super.blade.php          # Layout utama
├── components/
│   └── admin-super/
│       ├── sidebar.blade.php          # Komponen sidebar
│       ├── navbar.blade.php           # Komponen navbar
│       └── footer.blade.php           # Komponen footer
└── super-admin/
    └── dashboard/
        └── overview.blade.php         # Halaman dashboard overview
```

---

## 🏗️ 1. Layout Utama

**File:** `layouts/admin-super.blade.php`

### Struktur
```blade
<!DOCTYPE html>
<html lang="id">
<head>
    <!-- Meta tags & Title -->
    @yield('title')
    
    <!-- Tailwind CSS -->
    @stack('styles')
</head>
<body>
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        @include('components.admin-super.sidebar')

        <!-- Main Content -->
        <div class="flex flex-col flex-1">
            <!-- Navbar -->
            @include('components.admin-super.navbar')

            <!-- Page Content -->
            <main>
                @yield('content')
            </main>

            <!-- Footer -->
            @include('components.admin-super.footer')
        </div>
    </div>

    @stack('scripts')
</body>
</html>
```

### Fitur
- ✅ Responsive layout (mobile & desktop)
- ✅ Sidebar toggle untuk mobile
- ✅ Stack untuk custom styles & scripts
- ✅ CSRF token meta tag
- ✅ Tailwind CSS CDN

---

## 🧩 2. Komponen Sidebar

**File:** `components/admin-super/sidebar.blade.php`

### Struktur Menu
```
📊 Dashboard Utama
   • Ringkasan sistem global
   • Statistik sekolah aktif
   • Aktivitas terbaru

🏫 Manajemen Sekolah
   • Daftar Sekolah
   • Aktivasi Sekolah
   • Paket & Limit

👥 Manajemen Pengguna Global
   • Super Admin
   • Support/Admin Internal

🔍 Monitoring Sistem
   • System Health
   • Error Logs
   • Queue Status

🛡️ Security Monitoring Global
   • Security Events
   • Suspicious Activity
   • API Abuse Logs

💳 Billing & Subscription
   • Paket Langganan
   • Tagihan Sekolah
   • Riwayat Pembayaran

📈 Laporan Global
   • Statistik Penggunaan
   • Rekap Absensi Global

⚙️ Pengaturan Sistem
   • Feature Flags
   • Maintenance Mode
```

### Fitur
- ✅ **Active state detection** menggunakan `request()->routeIs()`
- ✅ **Accordion menu** dengan JavaScript toggle
- ✅ **Responsive** - Hidden di mobile, toggle dengan hamburger
- ✅ **Sticky footer** dengan status sistem
- ✅ **Smooth transitions** untuk expand/collapse

### Route Naming Convention
```php
// Dashboard
super-admin.dashboard.overview
super-admin.dashboard.school-stats
super-admin.dashboard.activity

// Schools
super-admin.schools.list
super-admin.schools.activation
super-admin.schools.packages

// Users
super-admin.users.superadmin
super-admin.users.support

// Monitoring
super-admin.monitoring.health
super-admin.monitoring.logs
super-admin.monitoring.queue

// Security
super-admin.security.events
super-admin.security.suspicious
super-admin.security.api-abuse

// Billing
super-admin.billing.packages
super-admin.billing.invoices
super-admin.billing.history

// Reports
super-admin.reports.usage
super-admin.reports.attendance

// Settings
super-admin.settings.flags
super-admin.settings.maintenance
```

---

## 🔝 3. Komponen Navbar

**File:** `components/admin-super/navbar.blade.php`

### Elemen
```html
<!-- Left Section -->
- Hamburger Menu (Mobile)
- Search Bar (Desktop)

<!-- Right Section -->
- Notification Bell (dengan badge count)
- Messages Icon (dengan badge count)
- User Profile Dropdown
  * Avatar dengan inisial
  * Nama & Email
  * Dropdown menu:
    - 👤 Profil Saya
    - ⚙️ Pengaturan Akun
    - 🚪 Logout
```

### Fitur
- ✅ **Notification badges** dinamis
- ✅ **User dropdown** dengan JavaScript toggle
- ✅ **Click outside** untuk close dropdown
- ✅ **Avatar inisial** otomatis dari nama user
- ✅ **Responsive** - Hide user info di mobile

### Variables yang Digunakan
```php
$notificationCount  // int - Jumlah notifikasi belum dibaca
$messageCount       // int - Jumlah pesan belum dibaca
auth()->user()->name    // string - Nama user
auth()->user()->email   // string - Email user
```

---

## 📄 4. Komponen Footer

**File:** `components/admin-super/footer.blade.php`

### Struktur
```html
<footer>
    <p>© {{ date('Y') }} AbsensiQR Pro - Super Admin Dashboard. Semua hak dilindungi.</p>
</footer>
```

### Fitur
- ✅ **Dynamic year** menggunakan `date('Y')`
- ✅ **Simple & clean** design
- ✅ **Centered text**

---

## 📊 5. Halaman Dashboard Overview

**File:** `super-admin/dashboard/overview.blade.php`

### Struktur
```blade
@extends('layouts.admin-super')

@section('title', 'Ringkasan Sistem Global')

@section('content')
    <!-- Page Title -->
    <h1>Ringkasan sistem global</h1>

    <!-- Stats Cards (4 cards) -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        - Sekolah Aktif
        - Total Pengguna
        - Event Keamanan
        - Uptime Sistem
    </div>

    <!-- Charts (2 charts) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        - Pertumbuhan Sekolah Aktif (Line Chart)
        - Distribusi Paket Langganan (Pie Chart)
    </div>

    <!-- Recent Activities Table -->
    <div>
        - Activity Logs Table
    </div>
@endsection
```

### Variables yang Digunakan
```php
// Stats Cards
$activeSchools              // int
$totalSchools               // int
$activeSchoolsPercentage    // int (0-100)
$totalUsers                 // int
$securityEvents             // int
$systemUptime               // string (format: "15d 7h 22m")

// Package Distribution
$basicPackageCount          // int
$professionalPackageCount   // int
$enterprisePackageCount     // int

// Activity Logs
$activities                 // Collection<Activity>
  ->time                    // string (HH:mm:ss)
  ->school_name             // string
  ->action                  // string
  ->user_email              // string
  ->status                  // string (success|warning|error)
```

---

## 🚀 Cara Menggunakan

### 1. Setup Routes

**File:** `routes/web.php`

```php
use App\Http\Controllers\SuperAdmin\DashboardController;

Route::middleware(['auth', 'role:super-admin'])->prefix('super-admin')->name('super-admin.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'overview'])->name('dashboard.overview');
    Route::get('/dashboard/school-stats', [DashboardController::class, 'schoolStats'])->name('dashboard.school-stats');
    Route::get('/dashboard/activity', [DashboardController::class, 'activity'])->name('dashboard.activity');
    
    // Schools
    Route::get('/schools', [SchoolController::class, 'list'])->name('schools.list');
    Route::get('/schools/activation', [SchoolController::class, 'activation'])->name('schools.activation');
    Route::get('/schools/packages', [SchoolController::class, 'packages'])->name('schools.packages');
    
    // ... route lainnya
});
```

### 2. Setup Controller

**File:** `app/Http/Controllers/SuperAdmin/DashboardController.php`

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Models\SecurityEvent;
use App\Models\Activity;

class DashboardController extends Controller
{
    public function overview()
    {
        $activeSchools = School::where('is_active', true)->count();
        $totalSchools = School::count();
        $activeSchoolsPercentage = $totalSchools > 0 
            ? round(($activeSchools / $totalSchools) * 100) 
            : 0;
        
        $totalUsers = User::count();
        
        $securityEvents = SecurityEvent::where('created_at', '>=', now()->subDay())->count();
        
        $systemUptime = $this->getSystemUptime();
        
        $basicPackageCount = School::where('package', 'Basic')->count();
        $professionalPackageCount = School::where('package', 'Professional')->count();
        $enterprisePackageCount = School::where('package', 'Enterprise')->count();
        
        $activities = Activity::with(['school', 'user'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($activity) {
                return (object) [
                    'time' => $activity->created_at->format('H:i:s'),
                    'school_name' => $activity->school->name ?? 'N/A',
                    'action' => $activity->action,
                    'user_email' => $activity->user->email ?? 'N/A',
                    'status' => $activity->status,
                ];
            });
        
        $notificationCount = auth()->user()->unreadNotifications()->count();
        $messageCount = auth()->user()->unreadMessages()->count();
        
        return view('super-admin.dashboard.overview', compact(
            'activeSchools',
            'totalSchools',
            'activeSchoolsPercentage',
            'totalUsers',
            'securityEvents',
            'systemUptime',
            'basicPackageCount',
            'professionalPackageCount',
            'enterprisePackageCount',
            'activities',
            'notificationCount',
            'messageCount'
        ));
    }
    
    private function getSystemUptime(): string
    {
        // Implementasi logic untuk mendapatkan system uptime
        // Contoh: membaca dari file atau database
        return '15d 7h 22m';
    }
}
```

### 3. Membuat Halaman Baru

**Contoh:** Halaman Daftar Sekolah

**File:** `resources/views/super-admin/schools/list.blade.php`

```blade
@extends('layouts.admin-super')

@section('title', 'Daftar Sekolah')

@section('content')
<h1 class="text-2xl md:text-3xl font-bold mb-6 bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
    Daftar Sekolah
</h1>

<div class="space-y-6">
    <!-- Search & Filter -->
    <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
            <h2 class="text-xl font-bold">Kelola Sekolah</h2>
            <div class="mt-4 md:mt-0 flex space-x-3">
                <input type="text" placeholder="Cari sekolah..." class="bg-gray-700 text-gray-200 rounded-lg py-2 px-4">
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                    ➕ Tambah Sekolah
                </button>
            </div>
        </div>
        
        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-700">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase">Nama Sekolah</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase">Paket</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700">
                    @forelse($schools as $school)
                    <tr class="hover:bg-gray-750">
                        <td class="px-4 py-3">{{ $school->name }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs bg-blue-900 text-blue-300">
                                {{ $school->package }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs {{ $school->is_active ? 'bg-green-900 text-green-300' : 'bg-red-900 text-red-300' }}">
                                {{ $school->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <button class="text-blue-400 hover:text-blue-300">✏️</button>
                            <button class="text-red-400 hover:text-red-300">🗑️</button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-400">
                            Tidak ada data sekolah
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div class="mt-6">
            {{ $schools->links() }}
        </div>
    </div>
</div>
@endsection
```

---

## 🎨 Customization

### Menambah Custom Styles

**Di halaman tertentu:**

```blade
@extends('layouts.admin-super')

@push('styles')
<style>
    .custom-class {
        /* Custom CSS */
    }
</style>
@endpush

@section('content')
    <!-- Content -->
@endsection
```

### Menambah Custom Scripts

**Di halaman tertentu:**

```blade
@extends('layouts.admin-super')

@section('content')
    <!-- Content -->
@endsection

@push('scripts')
<script>
    // Custom JavaScript
    console.log('Custom script loaded');
</script>
@endpush
```

### Mengubah Warna Tema

**Edit di:** `layouts/admin-super.blade.php`

```html
<!-- Ganti Tailwind CDN dengan custom config -->
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    primary: '#3B82F6',    // Blue
                    secondary: '#10B981',  // Green
                    danger: '#EF4444',     // Red
                    warning: '#F59E0B',    // Yellow
                }
            }
        }
    }
</script>
```

---

## 📱 Responsive Breakpoints

```
Mobile:   < 768px   (default classes)
Tablet:   ≥ 768px   (md: prefix)
Desktop:  ≥ 1024px  (lg: prefix)
```

**Contoh penggunaan:**
```html
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
    <!-- 1 kolom di mobile, 2 di tablet, 4 di desktop -->
</div>
```

---

## 🔒 Security Features

### CSRF Protection
```blade
<!-- Otomatis include di layout -->
<meta name="csrf-token" content="{{ csrf_token() }}">

<!-- Untuk form -->
<form method="POST">
    @csrf
    <!-- Form fields -->
</form>
```

### Authentication Check
```blade
<!-- Di navbar.blade.php -->
{{ auth()->user()->name }}
{{ auth()->user()->email }}

<!-- Di route -->
Route::middleware(['auth', 'role:super-admin'])->group(function () {
    // Routes
});
```

---

## ✅ Checklist Implementasi

### Setup Awal
- [ ] Copy semua file ke folder yang sesuai
- [ ] Setup routes di `routes/web.php`
- [ ] Buat controller `SuperAdmin/DashboardController`
- [ ] Test akses halaman dashboard

### Middleware & Auth
- [ ] Setup middleware `role:super-admin`
- [ ] Test authentication
- [ ] Test authorization

### Data Integration
- [ ] Buat model Activity jika belum ada
- [ ] Buat model SecurityEvent jika belum ada
- [ ] Populate data dummy untuk testing
- [ ] Integrate dengan database real

### UI/UX
- [ ] Test responsive di mobile
- [ ] Test sidebar toggle
- [ ] Test menu accordion
- [ ] Test user dropdown
- [ ] Test notification badges

### Chart Integration
- [ ] Install Chart.js atau ApexCharts
- [ ] Replace chart placeholders
- [ ] Integrate dengan data real
- [ ] Test chart rendering

---

## 📚 Resources

### Tailwind CSS
- Docs: https://tailwindcss.com/docs
- CDN: https://cdn.tailwindcss.com

### Chart Libraries
- Chart.js: https://www.chartjs.org/
- ApexCharts: https://apexcharts.com/
- Google Charts: https://developers.google.com/chart

### Laravel Blade
- Docs: https://laravel.com/docs/blade
- Components: https://laravel.com/docs/blade#components

---

## 🐛 Troubleshooting

### Sidebar tidak muncul di mobile
**Solusi:** Pastikan JavaScript di layout sudah berjalan
```javascript
// Check di browser console
console.log(document.querySelector('#sidebar'));
```

### Menu accordion tidak bekerja
**Solusi:** Pastikan script di sidebar.blade.php sudah di-push
```blade
@push('scripts')
<script>
    // Menu toggle script
</script>
@endpush
```

### Route not found
**Solusi:** Jalankan route cache
```bash
php artisan route:clear
php artisan route:cache
```

### Styles tidak apply
**Solusi:** Clear browser cache atau hard refresh (Ctrl+F5)

---

**Status:** ✅ Dokumentasi Lengkap  
**Tanggal:** 2026-02-04  
**Versi:** 1.0
