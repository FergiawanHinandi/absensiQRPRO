# Super Admin Dashboard - Laravel Blade Components

Komponen Laravel Blade yang telah dikonversi dari React NewDashboard.tsx

## 📁 File Structure

```
backend/resources/views/
├── layouts/
│   └── admin-super.blade.php              ✅ Layout utama
├── components/admin-super/
│   ├── sidebar.blade.php                  ✅ Sidebar dengan 8 menu
│   ├── navbar.blade.php                   ✅ Top navbar
│   └── footer.blade.php                   ✅ Footer
└── super-admin/dashboard/
    └── overview.blade.php                 ✅ Dashboard overview page
```

## 🚀 Quick Start

### 1. Setup Route
```php
// routes/web.php
Route::middleware(['auth', 'role:super-admin'])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'overview'])
            ->name('dashboard.overview');
    });
```

### 2. Create Controller
```php
// app/Http/Controllers/SuperAdmin/DashboardController.php
public function overview()
{
    return view('super-admin.dashboard.overview', [
        'activeSchools' => School::where('is_active', true)->count(),
        'totalSchools' => School::count(),
        'totalUsers' => User::count(),
        'securityEvents' => SecurityEvent::last24Hours()->count(),
        'systemUptime' => System::getUptime(),
        'activities' => Activity::latest()->limit(10)->get(),
        // ... more data
    ]);
}
```

### 3. Create New Page
```blade
@extends('layouts.admin-super')

@section('title', 'Page Title')

@section('content')
    <!-- Your content here -->
@endsection
```

## 📊 Required Variables

### Layout (navbar.blade.php)
- `$notificationCount` - int
- `$messageCount` - int
- `auth()->user()->name` - string
- `auth()->user()->email` - string

### Dashboard Overview
- `$activeSchools` - int
- `$totalSchools` - int
- `$activeSchoolsPercentage` - int
- `$totalUsers` - int
- `$securityEvents` - int
- `$systemUptime` - string
- `$basicPackageCount` - int
- `$professionalPackageCount` - int
- `$enterprisePackageCount` - int
- `$activities` - Collection

## 🎨 Features

✅ **Responsive Design** - Mobile, Tablet, Desktop  
✅ **Dark Theme** - Gray-900 color scheme  
✅ **Sidebar Menu** - 8 main menus with 23 submenus  
✅ **Active State** - Auto-detect with `request()->routeIs()`  
✅ **Accordion Menu** - Expand/collapse with JavaScript  
✅ **User Dropdown** - Profile, Settings, Logout  
✅ **Notification Badges** - Dynamic count display  
✅ **Chart Placeholders** - Ready for Chart.js/ApexCharts  

## 🔗 Route Naming Convention

```
super-admin.dashboard.overview
super-admin.dashboard.school-stats
super-admin.dashboard.activity
super-admin.schools.list
super-admin.schools.activation
super-admin.schools.packages
super-admin.users.superadmin
super-admin.users.support
super-admin.monitoring.health
super-admin.monitoring.logs
super-admin.monitoring.queue
super-admin.security.events
super-admin.security.suspicious
super-admin.security.api-abuse
super-admin.billing.packages
super-admin.billing.invoices
super-admin.billing.history
super-admin.reports.usage
super-admin.reports.attendance
super-admin.settings.flags
super-admin.settings.maintenance
```

## 📚 Documentation

Lihat dokumentasi lengkap di: `docs/frontend/BLADE_COMPONENTS_STRUCTURE.md`

## ✅ Checklist

- [x] Layout utama dengan sidebar, navbar, footer
- [x] Sidebar dengan 8 menu utama
- [x] Navbar dengan search, notifikasi, user menu
- [x] Footer dengan copyright
- [x] Dashboard overview page
- [x] Responsive design (mobile-first)
- [x] Active state detection
- [x] JavaScript untuk interaktivitas
- [ ] Chart integration (Chart.js/ApexCharts)
- [ ] Real data integration
- [ ] Additional pages (schools, users, monitoring, dll)

## 🔧 Next Steps

1. Install chart library (Chart.js atau ApexCharts)
2. Create controllers untuk semua routes
3. Integrate dengan database real
4. Add form validation
5. Add AJAX untuk dynamic updates
6. Add toast notifications
7. Add loading states

---

**Created:** 2026-02-04  
**Status:** ✅ Ready to Use
