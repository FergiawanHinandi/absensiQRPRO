# 15-Day Production Readiness Sprint
## Sistem Absensi QR Code - Solo Developer Guide

**Target**: 70% → 100% Production Ready  
**Timeline**: 15 hari kerja (3 minggu)  
**Developer**: Fullstack Pemula (Solo)  
**Approach**: Fix Critical Issues First, Then Optimize

---

## 📊 PRIORITIZATION MATRIX

| Priority | Issue | Impact | Effort | Days |
|----------|-------|--------|--------|------|
| 🔴 P0 | N+1 Query (Dashboard) | HIGH | MEDIUM | 2 |
| 🔴 P0 | Broadcast Security Vulnerability | CRITICAL | LOW | 1 |
| 🔴 P0 | Missing Database Indexes | HIGH | LOW | 1 |
| 🟡 P1 | Dashboard Charts/Stats | MEDIUM | MEDIUM | 2 |
| 🟡 P1 | API Response Caching | HIGH | MEDIUM | 2 |
| 🟡 P1 | Rate Limiting | MEDIUM | LOW | 1 |
| 🟡 P1 | Error Boundaries (Frontend) | MEDIUM | LOW | 1 |
| 🟡 P1 | Loading States | LOW | LOW | 1 |
| 🟢 P2 | TypeScript Strict Mode | LOW | MEDIUM | 2 |
| 🟢 P2 | Bundle Optimization | LOW | MEDIUM | 2 |

**Total**: 15 days

---

## 🗓️ WEEK 1: CRITICAL BACKEND FIXES

### **DAY 1: Fix N+1 Query Problem (Part 1 - Audit)**

**Goal**: Identify all N+1 queries in dashboard endpoints

**Morning (4 hours)**:
```bash
# 1. Install Laravel Debugbar
composer require barryvdh/laravel-debugbar --dev

# 2. Enable query logging
# Add to config/app.php
'debug' => env('APP_DEBUG', true),
```

**Task Checklist**:
- [ ] Install Debugbar
- [ ] Access dashboard: `http://localhost:8000/api/v1/dashboard`
- [ ] Screenshot query count (should be >50 queries)
- [ ] List all endpoints with >10 queries:
  - `/api/v1/dashboard` (Teacher Dashboard)
  - `/api/v1/admin/dashboard` (Admin Dashboard)
  - `/api/v1/reports/daily` (Daily Report)

**Afternoon (4 hours)**:
```bash
# 3. Run query analyzer
php artisan telescope:install
php artisan migrate
```

**Code to Add** (`routes/web.php`):
```php
// Temporary route for debugging
Route::get('/debug/queries', function () {
    DB::enableQueryLog();
    
    $user = auth()->user();
    $dashboard = app(\App\Http\Controllers\Api\V1\DashboardController::class)
        ->index(request());
    
    $queries = DB::getQueryLog();
    
    return response()->json([
        'total_queries' => count($queries),
        'queries' => $queries,
    ]);
})->middleware('auth:sanctum');
```

**Testing**:
```bash
# Test with Postman
GET http://localhost:8000/debug/queries
Authorization: Bearer {your_token}

# Expected: JSON with 50+ queries listed
```

**Deliverable**: Document with list of N+1 queries (save to `docs/n1_audit_results.md`)

**Rollback**: Remove Debugbar if it causes issues:
```bash
composer remove barryvdh/laravel-debugbar
```

---

### **DAY 2: Fix N+1 Query Problem (Part 2 - Implementation)**

**Goal**: Implement eager loading for top 5 worst endpoints

**Morning (4 hours)**:

**1. Fix Teacher Dashboard** (`app/Http/Controllers/Api/V1/DashboardController.php`):

**BEFORE** (N+1 Problem):
```php
public function index(Request $request)
{
    $schedules = Schedule::where('teacher_id', auth()->id())->get();
    
    foreach ($schedules as $schedule) {
        $schedule->class->name; // N+1!
        $schedule->subject->name; // N+1!
        $schedule->attendances->count(); // N+1!
    }
}
```

**AFTER** (Fixed):
```php
public function index(Request $request)
{
    $schedules = Schedule::where('teacher_id', auth()->id())
        ->with([
            'class:id,name,grade', // Only load needed columns
            'subject:id,name',
            'attendances' => function ($query) {
                $query->select('id', 'schedule_id', 'status')
                      ->where('attendance_date', today());
            }
        ])
        ->select('id', 'class_id', 'subject_id', 'teacher_id', 'day_of_week', 'start_time', 'end_time')
        ->get();
    
    return response()->json([
        'schedules' => $schedules,
        'query_count' => DB::getQueryLog(), // For debugging
    ]);
}
```

**Testing**:
```bash
# Before: 50+ queries
# After: 3-5 queries

# Test with Debugbar enabled
GET http://localhost:8000/api/v1/dashboard
```

**Afternoon (4 hours)**:

**2. Fix Admin Dashboard**:
```php
// app/Http/Controllers/Api/V1/AdminDashboardController.php

public function index(Request $request)
{
    $schoolId = auth()->user()->school_id;
    
    // OPTIMIZED: Single query with aggregation
    $stats = DB::table('attendances')
        ->select(
            DB::raw('COUNT(*) as total'),
            DB::raw('SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present'),
            DB::raw('SUM(CASE WHEN status = "late" THEN 1 ELSE 0 END) as late'),
            DB::raw('SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent')
        )
        ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
        ->where('schedules.school_id', $schoolId)
        ->where('attendances.attendance_date', today())
        ->first();
    
    return response()->json([
        'statistics' => $stats,
        'query_count' => 1, // Only 1 query!
    ]);
}
```

**Testing Checklist**:
- [ ] Dashboard loads in <1 second
- [ ] Query count reduced from 50+ to <10
- [ ] No visual changes (data still correct)
- [ ] Test with 100+ students (stress test)

**Rollback Plan**:
```bash
# If new code breaks dashboard:
git stash
git checkout HEAD~1 -- app/Http/Controllers/Api/V1/DashboardController.php
php artisan config:clear
```

---

### **DAY 3: Add Missing Database Indexes**

**Goal**: Add indexes to slow queries

**Morning (2 hours)**:

**1. Analyze Slow Queries**:
```bash
# Enable slow query log in MySQL
# Add to .env
DB_SLOW_QUERY_LOG=true
DB_SLOW_QUERY_TIME=1 # Log queries >1 second
```

**2. Create Migration**:
```bash
php artisan make:migration add_performance_indexes_to_attendances_table
```

**Migration Code**:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Composite index for dashboard queries
            $table->index(['schedule_id', 'attendance_date', 'status'], 'idx_attendance_dashboard');
            
            // Index for student history queries
            $table->index(['student_id', 'attendance_date'], 'idx_student_history');
            
            // Index for school-wide reports
            $table->index(['school_id', 'attendance_date'], 'idx_school_reports');
        });
        
        Schema::table('schedules', function (Blueprint $table) {
            // Index for teacher dashboard
            $table->index(['teacher_id', 'day_of_week'], 'idx_teacher_schedules');
            
            // Index for class schedules
            $table->index(['class_id', 'day_of_week'], 'idx_class_schedules');
        });
        
        Schema::table('users', function (Blueprint $table) {
            // Index for login queries
            $table->index(['username', 'is_active'], 'idx_login');
            
            // Index for school queries
            $table->index(['school_id', 'role_type'], 'idx_school_users');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_dashboard');
            $table->dropIndex('idx_student_history');
            $table->dropIndex('idx_school_reports');
        });
        
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('idx_teacher_schedules');
            $table->dropIndex('idx_class_schedules');
        });
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_login');
            $table->dropIndex('idx_school_users');
        });
    }
};
```

**Afternoon (2 hours)**:

**3. Run Migration**:
```bash
# Backup database first!
php artisan db:backup # If you have backup package

# Run migration
php artisan migrate

# Verify indexes
php artisan tinker
>>> DB::select("SHOW INDEX FROM attendances");
```

**Testing**:
```bash
# Test query performance
php artisan tinker

# BEFORE indexes:
>>> DB::enableQueryLog();
>>> Attendance::where('schedule_id', 1)->where('attendance_date', today())->get();
>>> DB::getQueryLog(); // Check execution time

# AFTER indexes:
# Should be 10-100x faster
```

**Performance Benchmark**:
```php
// Create test script: tests/Performance/IndexBenchmark.php
<?php

namespace Tests\Performance;

use App\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexBenchmark extends TestCase
{
    public function test_dashboard_query_performance()
    {
        $start = microtime(true);
        
        $attendances = Attendance::where('schedule_id', 1)
            ->where('attendance_date', today())
            ->get();
        
        $duration = (microtime(true) - $start) * 1000; // Convert to ms
        
        $this->assertLessThan(100, $duration, "Query took {$duration}ms (should be <100ms)");
    }
}
```

**Run Benchmark**:
```bash
php artisan test --filter=IndexBenchmark
```

**Rollback**:
```bash
# If indexes cause issues:
php artisan migrate:rollback --step=1
```

---

### **DAY 4: Implement API Response Caching**

**Goal**: Cache expensive API responses

**Morning (4 hours)**:

**1. Install Redis** (if not installed):
```bash
# Windows (using Laragon/XAMPP):
# Download Redis for Windows: https://github.com/microsoftarchive/redis/releases
# Or use Docker:
docker run -d -p 6379:6379 redis:alpine

# Update .env
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

**2. Create Cache Middleware**:
```bash
php artisan make:middleware CacheResponse
```

**Code** (`app/Http/Middleware/CacheResponse.php`):
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CacheResponse
{
    public function handle(Request $request, Closure $next, int $ttl = 60)
    {
        // Only cache GET requests
        if ($request->method() !== 'GET') {
            return $next($request);
        }
        
        // Generate cache key based on URL + user
        $cacheKey = 'api_cache:' . md5($request->fullUrl() . ':' . auth()->id());
        
        // Check cache
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey))
                ->header('X-Cache-Hit', 'true');
        }
        
        // Process request
        $response = $next($request);
        
        // Cache successful responses
        if ($response->status() === 200) {
            Cache::put($cacheKey, $response->getData(), now()->addSeconds($ttl));
        }
        
        return $response->header('X-Cache-Hit', 'false');
    }
}
```

**3. Register Middleware** (`bootstrap/app.php`):
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'cache.response' => \App\Http\Middleware\CacheResponse::class,
    ]);
})
```

**Afternoon (4 hours)**:

**4. Apply to Routes** (`routes/api.php`):
```php
// Cache dashboard for 5 minutes
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth:sanctum', 'cache.response:300']);

// Cache reports for 10 minutes
Route::get('/reports/daily', [ReportController::class, 'daily'])
    ->middleware(['auth:sanctum', 'cache.response:600']);

// Cache student list for 30 minutes
Route::get('/students', [StudentController::class, 'index'])
    ->middleware(['auth:sanctum', 'cache.response:1800']);
```

**5. Implement Cache Invalidation**:
```php
// app/Listeners/InvalidateDashboardCache.php (ALREADY EXISTS, UPDATE IT)

public function handle(StudentAttended $event): void
{
    $teacherId = $event->attendance->schedule->teacher_id;
    $schoolId = $event->attendance->schedule->school_id;
    
    // Clear teacher dashboard cache
    Cache::tags(['dashboard', "user:{$teacherId}"])->flush();
    
    // Clear school dashboard cache
    Cache::tags(['dashboard', "school:{$schoolId}"])->flush();
    
    Log::info('Dashboard cache invalidated', [
        'teacher_id' => $teacherId,
        'school_id' => $schoolId,
    ]);
}
```

**Testing**:
```bash
# Test cache hit
curl -H "Authorization: Bearer {token}" http://localhost:8000/api/v1/dashboard -v

# First request: X-Cache-Hit: false
# Second request: X-Cache-Hit: true (should be faster)

# Measure performance
ab -n 100 -c 10 -H "Authorization: Bearer {token}" http://localhost:8000/api/v1/dashboard
```

**Expected Results**:
- First request: 500-1000ms
- Cached request: 10-50ms (10-20x faster!)

**Rollback**:
```bash
# If caching causes stale data:
php artisan cache:clear

# Disable middleware temporarily:
# Comment out middleware in routes/api.php
```

---

### **DAY 5: Implement Rate Limiting**

**Goal**: Protect API from abuse

**Morning (3 hours)**:

**1. Configure Rate Limits** (`bootstrap/app.php`):
```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

->withMiddleware(function (Middleware $middleware) {
    // Global rate limit
    RateLimiter::for('global', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });
    
    // Strict limit for login
    RateLimiter::for('login', function (Request $request) {
        return Limit::perMinute(5)->by($request->ip())
            ->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => 'TOO_MANY_ATTEMPTS',
                    'message' => 'Terlalu banyak percobaan login. Coba lagi dalam 1 menit.',
                ], 429);
            });
    });
    
    // Moderate limit for QR scanning
    RateLimiter::for('qr_scan', function (Request $request) {
        return Limit::perMinute(10)->by($request->user()->id);
    });
})
```

**2. Apply to Routes** (`routes/api.php`):
```php
// Login endpoint
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// QR scan endpoint
Route::post('/attendance/scan', [AttendanceController::class, 'scan'])
    ->middleware(['auth:sanctum', 'throttle:qr_scan']);

// All other API routes
Route::middleware(['auth:sanctum', 'throttle:global'])->group(function () {
    // ... existing routes
});
```

**Afternoon (1 hour)**:

**Testing**:
```bash
# Test rate limiting
for i in {1..10}; do
  curl -X POST http://localhost:8000/api/v1/auth/login \
    -H "Content-Type: application/json" \
    -d '{"username":"test","password":"wrong"}' \
    -w "\nStatus: %{http_code}\n"
done

# Expected:
# Requests 1-5: 401 (Unauthorized)
# Request 6+: 429 (Too Many Requests)
```

**Deliverable**: Rate limiting working, documented in `docs/RATE_LIMITING.md`

---

## 🗓️ WEEK 2: FRONTEND OPTIMIZATION

### **DAY 6-7: Dashboard Charts & Real-time Updates**

**Goal**: Complete dashboard with charts and live data

**DAY 6 Morning (4 hours)**:

**1. Install Chart Library**:
```bash
cd frontend-web
npm install recharts
```

**2. Create Dashboard Stats Component**:
```tsx
// src/components/Dashboard/AttendanceChart.tsx
import React from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

interface AttendanceData {
  date: string;
  present: number;
  late: number;
  absent: number;
}

interface Props {
  data: AttendanceData[];
}

export const AttendanceChart: React.FC<Props> = ({ data }) => {
  return (
    <div className="bg-white p-6 rounded-lg shadow">
      <h3 className="text-lg font-semibold mb-4">Statistik Kehadiran 7 Hari Terakhir</h3>
      <ResponsiveContainer width="100%" height={300}>
        <LineChart data={data}>
          <CartesianGrid strokeDasharray="3 3" />
          <XAxis dataKey="date" />
          <YAxis />
          <Tooltip />
          <Legend />
          <Line type="monotone" dataKey="present" stroke="#10b981" name="Hadir" />
          <Line type="monotone" dataKey="late" stroke="#f59e0b" name="Terlambat" />
          <Line type="monotone" dataKey="absent" stroke="#ef4444" name="Tidak Hadir" />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
};
```

**DAY 6 Afternoon (4 hours)**:

**3. Add Real-time Updates with Laravel Echo**:
```bash
npm install laravel-echo pusher-js
```

**Code** (`src/services/echo.ts`):
```typescript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo: Echo;
  }
}

window.Pusher = Pusher;

export const echo = new Echo({
  broadcaster: 'pusher',
  key: import.meta.env.VITE_PUSHER_APP_KEY,
  cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
  forceTLS: true,
  authEndpoint: 'http://localhost:8000/broadcasting/auth',
  auth: {
    headers: {
      Authorization: `Bearer ${localStorage.getItem('token')}`,
    },
  },
});
```

**4. Use in Dashboard**:
```tsx
// src/pages/TeacherDashboard.tsx
import { useEffect, useState } from 'react';
import { echo } from '../services/echo';
import { AttendanceChart } from '../components/Dashboard/AttendanceChart';

export const TeacherDashboard = () => {
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  
  useEffect(() => {
    // Fetch initial data
    fetchDashboardData();
    
    // Subscribe to real-time updates
    const teacherId = localStorage.getItem('user_id');
    echo.private(`teacher.${teacherId}`)
      .listen('AttendanceRecorded', (e: any) => {
        console.log('New attendance:', e);
        // Update stats in real-time
        setStats((prev: any) => ({
          ...prev,
          total_present: prev.total_present + 1,
        }));
      });
    
    return () => {
      echo.leave(`teacher.${teacherId}`);
    };
  }, []);
  
  const fetchDashboardData = async () => {
    setLoading(true);
    try {
      const response = await fetch('http://localhost:8000/api/v1/dashboard', {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
        },
      });
      const data = await response.json();
      setStats(data);
    } catch (error) {
      console.error('Failed to fetch dashboard:', error);
    } finally {
      setLoading(false);
    }
  };
  
  if (loading) {
    return <div className="flex items-center justify-center h-screen">
      <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
    </div>;
  }
  
  return (
    <div className="p-6">
      <h1 className="text-2xl font-bold mb-6">Dashboard Guru</h1>
      
      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div className="bg-white p-4 rounded-lg shadow">
          <p className="text-gray-500 text-sm">Total Hadir</p>
          <p className="text-3xl font-bold text-green-600">{stats.total_present}</p>
        </div>
        <div className="bg-white p-4 rounded-lg shadow">
          <p className="text-gray-500 text-sm">Terlambat</p>
          <p className="text-3xl font-bold text-yellow-600">{stats.total_late}</p>
        </div>
        <div className="bg-white p-4 rounded-lg shadow">
          <p className="text-gray-500 text-sm">Tidak Hadir</p>
          <p className="text-3xl font-bold text-red-600">{stats.total_absent}</p>
        </div>
        <div className="bg-white p-4 rounded-lg shadow">
          <p className="text-gray-500 text-sm">Persentase</p>
          <p className="text-3xl font-bold text-blue-600">{stats.attendance_rate}%</p>
        </div>
      </div>
      
      {/* Chart */}
      <AttendanceChart data={stats.weekly_data} />
    </div>
  );
};
```

**Testing**:
- [ ] Dashboard loads with charts
- [ ] Real-time updates work (scan QR, see dashboard update)
- [ ] Loading states show properly
- [ ] Responsive on mobile

---

### **DAY 8: Error Boundaries & Loading States**

**Goal**: Improve UX with proper error handling

**Morning (4 hours)**:

**1. Create Error Boundary**:
```tsx
// src/components/ErrorBoundary.tsx
import React, { Component, ErrorInfo, ReactNode } from 'react';

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error('Error caught by boundary:', error, errorInfo);
    
    // Send to error tracking service (e.g., Sentry)
    // Sentry.captureException(error);
  }

  render() {
    if (this.state.hasError) {
      return this.props.fallback || (
        <div className="flex flex-col items-center justify-center h-screen bg-gray-50">
          <div className="text-center p-8 bg-white rounded-lg shadow-lg max-w-md">
            <svg className="w-16 h-16 text-red-500 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <h2 className="text-2xl font-bold text-gray-800 mb-2">Oops! Terjadi Kesalahan</h2>
            <p className="text-gray-600 mb-4">
              Aplikasi mengalami error. Tim kami sudah diberitahu.
            </p>
            <button
              onClick={() => window.location.reload()}
              className="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 transition"
            >
              Muat Ulang Halaman
            </button>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
```

**2. Wrap App with Error Boundary** (`src/main.tsx`):
```tsx
import { ErrorBoundary } from './components/ErrorBoundary';

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <ErrorBoundary>
      <App />
    </ErrorBoundary>
  </React.StrictMode>
);
```

**Afternoon (4 hours)**:

**3. Create Loading Component**:
```tsx
// src/components/LoadingSpinner.tsx
export const LoadingSpinner = ({ fullScreen = false }: { fullScreen?: boolean }) => {
  const containerClass = fullScreen 
    ? "fixed inset-0 flex items-center justify-center bg-white bg-opacity-75 z-50"
    : "flex items-center justify-center p-8";
  
  return (
    <div className={containerClass}>
      <div className="flex flex-col items-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
        <p className="mt-4 text-gray-600">Memuat data...</p>
      </div>
    </div>
  );
};

// Skeleton loader for lists
export const SkeletonLoader = () => {
  return (
    <div className="animate-pulse space-y-4">
      {[1, 2, 3].map((i) => (
        <div key={i} className="bg-gray-200 h-20 rounded-lg"></div>
      ))}
    </div>
  );
};
```

**4. Use in Components**:
```tsx
// Example usage
import { LoadingSpinner, SkeletonLoader } from '../components/LoadingSpinner';

export const StudentList = () => {
  const [loading, setLoading] = useState(true);
  const [students, setStudents] = useState([]);
  
  if (loading) {
    return <SkeletonLoader />;
  }
  
  return (
    <div>
      {students.map(student => (
        <StudentCard key={student.id} student={student} />
      ))}
    </div>
  );
};
```

**Testing**:
- [ ] Trigger error (throw new Error in component)
- [ ] Error boundary catches and shows fallback
- [ ] Loading states show during data fetch
- [ ] Skeleton loaders render correctly

---

## 🗓️ WEEK 3: MOBILE APP & FINAL POLISH

### **DAY 9-10: Mobile Performance Optimization**

**Goal**: Fix crashes and improve performance

**DAY 9 - Memory Leak Fix**:

**1. Identify Memory Leaks**:
```bash
# Enable React Native performance monitor
# In your app, shake device → "Show Perf Monitor"
```

**2. Fix Common Leaks** (React Native):
```typescript
// src/screens/AttendanceScreen.tsx

// ❌ BEFORE (Memory Leak)
useEffect(() => {
  const interval = setInterval(() => {
    fetchAttendance();
  }, 5000);
  // Missing cleanup!
}, []);

// ✅ AFTER (Fixed)
useEffect(() => {
  const interval = setInterval(() => {
    fetchAttendance();
  }, 5000);
  
  return () => clearInterval(interval); // Cleanup!
}, []);

// ❌ BEFORE (Event Listener Leak)
useEffect(() => {
  NetInfo.addEventListener(handleConnectivityChange);
}, []);

// ✅ AFTER (Fixed)
useEffect(() => {
  const unsubscribe = NetInfo.addEventListener(handleConnectivityChange);
  return () => unsubscribe(); // Cleanup!
}, []);
```

**3. Optimize Images**:
```typescript
// Use FastImage instead of Image
import FastImage from 'react-native-fast-image';

<FastImage
  source={{ uri: student.photo_url, priority: FastImage.priority.normal }}
  style={{ width: 50, height: 50, borderRadius: 25 }}
  resizeMode={FastImage.resizeMode.cover}
/>
```

**DAY 10 - Navigation Performance**:

**1. Lazy Load Screens**:
```typescript
// src/navigation/AppNavigator.tsx
import { lazy, Suspense } from 'react';

const DashboardScreen = lazy(() => import('../screens/DashboardScreen'));
const AttendanceScreen = lazy(() => import('../screens/AttendanceScreen'));

export const AppNavigator = () => {
  return (
    <Suspense fallback={<LoadingSpinner />}>
      <Stack.Navigator>
        <Stack.Screen name="Dashboard" component={DashboardScreen} />
        <Stack.Screen name="Attendance" component={AttendanceScreen} />
      </Stack.Navigator>
    </Suspense>
  );
};
```

**Testing**:
```bash
# Build release APK
cd android
./gradlew assembleRelease

# Test on real device
adb install app/build/outputs/apk/release/app-release.apk

# Monitor performance
adb shell dumpsys meminfo com.yourapp
```

---

### **DAY 11-12: Offline Sync Implementation**

**Goal**: Basic offline functionality

**DAY 11 - Local Storage**:

**1. Install AsyncStorage**:
```bash
npm install @react-native-async-storage/async-storage
```

**2. Create Offline Queue**:
```typescript
// src/services/offlineQueue.ts
import AsyncStorage from '@react-native-async-storage/async-storage';

const QUEUE_KEY = 'offline_attendance_queue';

export interface QueuedAttendance {
  id: string;
  qr_token: string;
  latitude: number;
  longitude: number;
  timestamp: string;
}

export const offlineQueue = {
  async add(attendance: Omit<QueuedAttendance, 'id'>): Promise<void> {
    const queue = await this.getAll();
    const newItem: QueuedAttendance = {
      ...attendance,
      id: Date.now().toString(),
    };
    queue.push(newItem);
    await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
  },
  
  async getAll(): Promise<QueuedAttendance[]> {
    const data = await AsyncStorage.getItem(QUEUE_KEY);
    return data ? JSON.parse(data) : [];
  },
  
  async remove(id: string): Promise<void> {
    const queue = await this.getAll();
    const filtered = queue.filter(item => item.id !== id);
    await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(filtered));
  },
  
  async clear(): Promise<void> {
    await AsyncStorage.removeItem(QUEUE_KEY);
  },
};
```

**DAY 12 - Sync Logic**:

**3. Implement Sync**:
```typescript
// src/services/syncService.ts
import NetInfo from '@react-native-community/netinfo';
import { offlineQueue } from './offlineQueue';
import { api } from './api';

export const syncService = {
  async syncPendingAttendances(): Promise<void> {
    const isConnected = await NetInfo.fetch().then(state => state.isConnected);
    
    if (!isConnected) {
      console.log('No internet, skipping sync');
      return;
    }
    
    const queue = await offlineQueue.getAll();
    
    for (const item of queue) {
      try {
        await api.post('/attendance/scan', {
          qr_token: item.qr_token,
          latitude: item.latitude,
          longitude: item.longitude,
        });
        
        // Success, remove from queue
        await offlineQueue.remove(item.id);
        console.log(`Synced attendance ${item.id}`);
      } catch (error) {
        console.error(`Failed to sync ${item.id}:`, error);
        // Keep in queue for retry
      }
    }
  },
  
  startAutoSync(): void {
    // Sync every 5 minutes
    setInterval(() => {
      this.syncPendingAttendances();
    }, 5 * 60 * 1000);
    
    // Sync when network becomes available
    NetInfo.addEventListener(state => {
      if (state.isConnected) {
        this.syncPendingAttendances();
      }
    });
  },
};
```

**4. Use in App**:
```typescript
// src/screens/QRScanScreen.tsx
import { offlineQueue } from '../services/offlineQueue';
import NetInfo from '@react-native-community/netinfo';

const handleScan = async (qrData: string) => {
  const isConnected = await NetInfo.fetch().then(state => state.isConnected);
  
  const attendanceData = {
    qr_token: qrData,
    latitude: location.latitude,
    longitude: location.longitude,
    timestamp: new Date().toISOString(),
  };
  
  if (isConnected) {
    // Online: Send immediately
    try {
      await api.post('/attendance/scan', attendanceData);
      Alert.alert('Sukses', 'Absensi berhasil dicatat');
    } catch (error) {
      // Failed, add to queue
      await offlineQueue.add(attendanceData);
      Alert.alert('Offline', 'Absensi disimpan, akan dikirim saat online');
    }
  } else {
    // Offline: Add to queue
    await offlineQueue.add(attendanceData);
    Alert.alert('Mode Offline', 'Absensi disimpan, akan dikirim saat online');
  }
};
```

**Testing**:
- [ ] Turn off WiFi/data
- [ ] Scan QR code
- [ ] Verify data saved to AsyncStorage
- [ ] Turn on internet
- [ ] Verify data synced to server

---

### **DAY 13: Biometric Login**

**Goal**: Add fingerprint/face ID login

**1. Install Package**:
```bash
npm install react-native-biometrics
```

**2. Implement Biometric Auth**:
```typescript
// src/services/biometricAuth.ts
import ReactNativeBiometrics from 'react-native-biometrics';

const rnBiometrics = new ReactNativeBiometrics();

export const biometricAuth = {
  async isAvailable(): Promise<boolean> {
    const { available } = await rnBiometrics.isSensorAvailable();
    return available;
  },
  
  async authenticate(): Promise<boolean> {
    try {
      const { success } = await rnBiometrics.simplePrompt({
        promptMessage: 'Konfirmasi identitas Anda',
        cancelButtonText: 'Batal',
      });
      return success;
    } catch (error) {
      console.error('Biometric auth failed:', error);
      return false;
    }
  },
  
  async saveCredentials(username: string, password: string): Promise<void> {
    // Encrypt and save to secure storage
    await rnBiometrics.createKeys();
    // Store encrypted credentials
  },
};
```

**3. Use in Login Screen**:
```typescript
// src/screens/LoginScreen.tsx
import { biometricAuth } from '../services/biometricAuth';

const LoginScreen = () => {
  const [biometricAvailable, setBiometricAvailable] = useState(false);
  
  useEffect(() => {
    checkBiometric();
  }, []);
  
  const checkBiometric = async () => {
    const available = await biometricAuth.isAvailable();
    setBiometricAvailable(available);
  };
  
  const handleBiometricLogin = async () => {
    const success = await biometricAuth.authenticate();
    if (success) {
      // Auto-fill credentials and login
      const credentials = await getStoredCredentials();
      handleLogin(credentials.username, credentials.password);
    }
  };
  
  return (
    <View>
      {/* Normal login form */}
      
      {biometricAvailable && (
        <TouchableOpacity onPress={handleBiometricLogin}>
          <Text>Login dengan Sidik Jari</Text>
        </TouchableOpacity>
      )}
    </View>
  );
};
```

---

### **DAY 14: TypeScript Strict Mode & Code Quality**

**Goal**: Enable strict mode and fix type errors

**1. Update tsconfig.json**:
```json
{
  "compilerOptions": {
    "strict": true,
    "noImplicitAny": true,
    "strictNullChecks": true,
    "strictFunctionTypes": true,
    "strictPropertyInitialization": true,
    "noImplicitThis": true,
    "alwaysStrict": true
  }
}
```

**2. Fix Type Errors** (Example):
```typescript
// ❌ BEFORE
const fetchData = async (id) => {
  const response = await api.get(`/students/${id}`);
  return response.data;
};

// ✅ AFTER
interface Student {
  id: number;
  name: string;
  nisn: string;
}

const fetchData = async (id: number): Promise<Student> => {
  const response = await api.get<Student>(`/students/${id}`);
  return response.data;
};
```

**3. Run Type Check**:
```bash
npm run type-check
# Fix all errors before proceeding
```

---

### **DAY 15: Final Testing & Deployment Prep**

**Goal**: Comprehensive testing and deployment checklist

**Morning (4 hours) - Testing**:

**1. Backend Tests**:
```bash
cd backend
php artisan test

# Expected: All tests pass
```

**2. Frontend Tests**:
```bash
cd frontend-web
npm run test

# Expected: All tests pass
```

**3. Mobile Tests**:
```bash
cd mobile
npm run test

# Build release
npm run build:android
```

**Afternoon (4 hours) - Deployment**:

**1. Backend Deployment Checklist**:
```bash
# .env production settings
APP_ENV=production
APP_DEBUG=false
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
composer install --optimize-autoloader --no-dev

# Migrate database
php artisan migrate --force

# Restart services
sudo supervisorctl restart laravel-worker:*
```

**2. Frontend Deployment**:
```bash
cd frontend-web
npm run build

# Upload dist/ to server
scp -r dist/* user@server:/var/www/html/
```

**3. Mobile Release**:
```bash
cd mobile/android
./gradlew bundleRelease

# Upload to Play Store Console
```

**Final Checklist**:
- [ ] All tests passing
- [ ] No console errors
- [ ] Performance benchmarks met (<1s dashboard load)
- [ ] Security audit passed
- [ ] Documentation updated
- [ ] Backup created
- [ ] Rollback plan ready

---

## 📊 SUCCESS METRICS

| Metric | Before | Target | After |
|--------|--------|--------|-------|
| Dashboard Load Time | 2-5s | <1s | ✅ |
| API Query Count | 50+ | <10 | ✅ |
| Mobile Crash Rate | High | <1% | ✅ |
| Test Coverage | 0% | >70% | ✅ |
| TypeScript Errors | Many | 0 | ✅ |

---

## 🚨 ROLLBACK PROCEDURES

### If Backend Breaks:
```bash
# Rollback migration
php artisan migrate:rollback

# Restore from backup
mysql -u root -p absensi_db < backup_2026_02_08.sql

# Revert code
git reset --hard HEAD~1
php artisan config:clear
```

### If Frontend Breaks:
```bash
# Revert to previous build
git checkout HEAD~1 -- frontend-web/
npm install
npm run build
```

### If Mobile Breaks:
```bash
# Rollback to previous APK
adb uninstall com.yourapp
adb install previous_version.apk
```

---

## 📚 LEARNING RESOURCES

- **N+1 Queries**: https://laravel.com/docs/eloquent-relationships#eager-loading
- **Redis Caching**: https://laravel.com/docs/cache
- **React Performance**: https://react.dev/learn/render-and-commit
- **TypeScript**: https://www.typescriptlang.org/docs/handbook/intro.html

---

**Good luck! 🚀 Anda bisa melakukannya!**
