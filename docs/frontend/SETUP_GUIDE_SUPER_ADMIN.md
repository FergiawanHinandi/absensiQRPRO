# Setup Guide - Super Admin Dashboard

Panduan lengkap untuk mengaktifkan Super Admin Dashboard di Laravel.

---

## 📋 Prerequisites

- ✅ Laravel 10+ installed
- ✅ PHP 8.1+ installed
- ✅ Database configured
- ✅ Authentication system ready

---

## 🚀 Installation Steps

### Step 1: Include Routes

**File:** `app/Providers/RouteServiceProvider.php`

```php
public function boot(): void
{
    $this->routes(function () {
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/api.php'));

        Route::middleware('web')
            ->group(base_path('routes/web.php'));

        // Add this line
        Route::middleware('web')
            ->group(base_path('routes/super-admin.php'));
    });
}
```

**Atau tambahkan di `bootstrap/app.php` (Laravel 11+):**

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
    then: function () {
        Route::middleware('web')
            ->group(base_path('routes/super-admin.php'));
    }
)
```

---

### Step 2: Create Middleware

**File:** `app/Http/Middleware/CheckSuperAdmin.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        if (!auth()->user()->hasRole('super-admin')) {
            abort(403, 'Unauthorized access');
        }

        return $next($request);
    }
}
```

**Register middleware di `app/Http/Kernel.php`:**

```php
protected $middlewareAliases = [
    // ... existing middleware
    'role' => \App\Http\Middleware\CheckSuperAdmin::class,
];
```

---

### Step 3: Update User Model

**File:** `app/Models/User.php`

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // Add this
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Check if user has specific role
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Get unread notifications count
     */
    public function unreadNotifications()
    {
        return $this->notifications()->whereNull('read_at');
    }

    /**
     * Get unread messages count
     */
    public function unreadMessages()
    {
        // Implement based on your messaging system
        return collect([]); // Placeholder
    }
}
```

---

### Step 4: Create Required Models

#### Activity Model

**File:** `app/Models/Activity.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    protected $fillable = [
        'user_id',
        'school_id',
        'action',
        'status',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
```

#### SecurityEvent Model

**File:** `app/Models/SecurityEvent.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    protected $fillable = [
        'type',
        'severity',
        'ip_address',
        'user_id',
        'description',
        'resolved_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeLast24Hours($query)
    {
        return $query->where('created_at', '>=', now()->subDay());
    }
}
```

---

### Step 5: Create Migrations

#### Activities Table

```bash
php artisan make:migration create_activities_table
```

**File:** `database/migrations/xxxx_xx_xx_create_activities_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->enum('status', ['success', 'warning', 'error'])->default('success');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
```

#### Security Events Table

```bash
php artisan make:migration create_security_events_table
```

**File:** `database/migrations/xxxx_xx_xx_create_security_events_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->ipAddress('ip_address')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
```

#### Add Role to Users Table

```bash
php artisan make:migration add_role_to_users_table
```

**File:** `database/migrations/xxxx_xx_xx_add_role_to_users_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('email');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
```

**Run migrations:**

```bash
php artisan migrate
```

---

### Step 6: Create Seeders

#### Super Admin Seeder

```bash
php artisan make:seeder SuperAdminSeeder
```

**File:** `database/seeders/SuperAdminSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@absensiqrpro.com',
            'password' => Hash::make('password'),
            'role' => 'super-admin',
            'email_verified_at' => now(),
        ]);
    }
}
```

#### Activity Seeder (Dummy Data)

```bash
php artisan make:seeder ActivitySeeder
```

**File:** `database/seeders/ActivitySeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;

class ActivitySeeder extends Seeder
{
    public function run(): void
    {
        $schools = School::limit(5)->get();
        $users = User::limit(5)->get();

        $actions = [
            'Login Admin',
            'Generate QR Code',
            'Export Report',
            'Update Profile',
            'New Absence',
            'Failed Login',
        ];

        $statuses = ['success', 'warning', 'error'];

        for ($i = 0; $i < 20; $i++) {
            Activity::create([
                'user_id' => $users->random()->id,
                'school_id' => $schools->random()->id,
                'action' => $actions[array_rand($actions)],
                'status' => $statuses[array_rand($statuses)],
                'ip_address' => '192.168.1.' . rand(1, 255),
                'created_at' => now()->subHours(rand(1, 24)),
            ]);
        }
    }
}
```

**Run seeders:**

```bash
php artisan db:seed --class=SuperAdminSeeder
php artisan db:seed --class=ActivitySeeder
```

---

### Step 7: Clear Cache & Test

```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Cache routes for production
php artisan route:cache
php artisan config:cache
```

---

## 🧪 Testing

### 1. Login as Super Admin

```
Email: superadmin@absensiqrpro.com
Password: password
```

### 2. Access Dashboard

```
URL: http://your-domain.com/super-admin/dashboard
```

### 3. Test Features

- [ ] Sidebar menu toggle (mobile)
- [ ] Menu accordion expand/collapse
- [ ] User dropdown menu
- [ ] Notification badges
- [ ] Stats cards display
- [ ] Activity table display
- [ ] Pagination (if applicable)

---

## 🔧 Configuration

### Environment Variables

**File:** `.env`

```env
# Super Admin Settings
SUPER_ADMIN_EMAIL=superadmin@absensiqrpro.com
SUPER_ADMIN_NAME="Super Admin"

# System Settings
APP_DEPLOYMENT_DATE="2026-01-01 00:00:00"
```

### Config File (Optional)

**File:** `config/super-admin.php`

```php
<?php

return [
    'email' => env('SUPER_ADMIN_EMAIL', 'superadmin@absensiqrpro.com'),
    'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
    'deployment_date' => env('APP_DEPLOYMENT_DATE', now()->subDays(15)),
    
    'menu_items' => [
        'dashboard' => true,
        'schools' => true,
        'users' => true,
        'monitoring' => true,
        'security' => true,
        'billing' => true,
        'reports' => true,
        'settings' => true,
    ],
];
```

---

## 📊 Chart Integration (Optional)

### Install Chart.js

**Add to layout:**

```blade
<!-- In layouts/admin-super.blade.php, before </body> -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
```

**Example usage:**

```blade
@push('scripts')
<script>
    const ctx = document.getElementById('myChart');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: @json($chartLabels),
            datasets: [{
                label: 'Pertumbuhan Sekolah',
                data: @json($chartData),
                borderColor: '#3B82F6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
        }
    });
</script>
@endpush
```

---

## 🐛 Troubleshooting

### Issue: Route not found

**Solution:**
```bash
php artisan route:clear
php artisan route:cache
php artisan route:list | grep super-admin
```

### Issue: 403 Forbidden

**Solution:**
- Check user role in database
- Verify middleware is registered
- Check `hasRole()` method in User model

### Issue: Sidebar not showing

**Solution:**
- Check if `@include('components.admin-super.sidebar')` exists
- Verify file path is correct
- Clear view cache: `php artisan view:clear`

### Issue: Styles not applying

**Solution:**
- Hard refresh browser (Ctrl + F5)
- Check Tailwind CDN is loading
- Inspect element in browser DevTools

---

## ✅ Final Checklist

### Backend Setup
- [ ] Routes included in RouteServiceProvider
- [ ] Middleware created and registered
- [ ] User model updated with role
- [ ] Activity model created
- [ ] SecurityEvent model created
- [ ] Migrations run successfully
- [ ] Seeders run successfully

### Frontend Setup
- [ ] Layout file in place
- [ ] Sidebar component in place
- [ ] Navbar component in place
- [ ] Footer component in place
- [ ] Dashboard overview page in place

### Testing
- [ ] Can login as super admin
- [ ] Can access dashboard
- [ ] Sidebar menu works
- [ ] User dropdown works
- [ ] Stats cards display correctly
- [ ] Activity table displays data
- [ ] Responsive on mobile
- [ ] Responsive on tablet
- [ ] Responsive on desktop

### Optional
- [ ] Chart library installed
- [ ] Charts displaying data
- [ ] Additional pages created
- [ ] Search functionality added
- [ ] Export functionality added

---

## 📚 Next Steps

1. **Create Additional Pages**
   - School management pages
   - User management pages
   - Monitoring pages
   - Security pages
   - Billing pages
   - Report pages
   - Settings pages

2. **Add Functionality**
   - CRUD operations
   - Search & filter
   - Pagination
   - Sorting
   - Export (Excel, PDF)
   - Real-time updates (WebSocket)

3. **Enhance UI/UX**
   - Add loading states
   - Add toast notifications
   - Add modal dialogs
   - Add form validation
   - Add error handling

4. **Optimize Performance**
   - Add caching
   - Optimize queries
   - Add eager loading
   - Compress assets

---

**Setup Complete!** 🎉

Your Super Admin Dashboard is now ready to use.

For detailed documentation, see:
- `docs/frontend/BLADE_COMPONENTS_STRUCTURE.md`
- `docs/frontend/SUMMARY_KONVERSI_BLADE.md`
- `backend/resources/views/README-SUPER-ADMIN.md`
