# 🚀 Advanced Features Implementation Guide

## 📋 Table of Contents
1. [Scheduled Backups](#scheduled-backups)
2. [Cloud Storage Integration](#cloud-storage-integration)
3. [WebSocket Push Notifications](#websocket-push-notifications)
4. [Setup Instructions](#setup-instructions)

---

## 1. ⏰ Scheduled Backups

### Command Created
**File**: `app/Console/Commands/BackupDatabase.php`

**Usage**:
```bash
# Manual backup (local only)
php artisan backup:database

# Backup with cloud upload
php artisan backup:database --upload
```

### Features
- ✅ Automated PostgreSQL dump
- ✅ Cloud storage upload (S3/Google Cloud)
- ✅ Auto-cleanup (keeps last 7 days)
- ✅ Audit logging
- ✅ Error handling & notifications

### Schedule Configuration

#### For Laravel 11+
Add to `routes/console.php`:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:database --upload')
    ->daily()
    ->at('02:00')
    ->timezone('Asia/Jakarta');
```

#### For Laravel 10 and below
Add to `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('backup:database --upload')
        ->daily()
        ->at('02:00')
        ->timezone('Asia/Jakarta');
}
```

### Cron Setup (Production Server)

Add to crontab:
```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

**Windows Task Scheduler**:
```powershell
# Create task that runs every minute
schtasks /create /tn "Laravel Scheduler" /tr "php C:\path\to\artisan schedule:run" /sc minute
```

---

## 2. ☁️ Cloud Storage Integration

### AWS S3 Setup

#### 1. Install AWS SDK
```bash
composer require league/flysystem-aws-s3-v3 "^3.0" --with-all-dependencies
```

#### 2. Configure `.env`
```env
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=absensi-backups
AWS_USE_PATH_STYLE_ENDPOINT=false
```

#### 3. Update `config/filesystems.php`
```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => false,
],
```

#### 4. Test Upload
```bash
php artisan backup:database --upload
```

---

### Google Cloud Storage Setup

#### 1. Install Google Cloud SDK
```bash
composer require league/flysystem-google-cloud-storage "^3.0"
```

#### 2. Create Service Account
1. Go to Google Cloud Console
2. Create Service Account
3. Download JSON key file
4. Save to `storage/app/gcs-key.json`

#### 3. Configure `.env`
```env
GOOGLE_CLOUD_PROJECT_ID=your-project-id
GOOGLE_CLOUD_KEY_FILE=storage/app/gcs-key.json
GOOGLE_CLOUD_STORAGE_BUCKET=absensi-backups
```

#### 4. Update `config/filesystems.php`
```php
'gcs' => [
    'driver' => 'gcs',
    'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
    'key_file' => base_path(env('GOOGLE_CLOUD_KEY_FILE')),
    'bucket' => env('GOOGLE_CLOUD_STORAGE_BUCKET'),
    'path_prefix' => env('GOOGLE_CLOUD_STORAGE_PATH_PREFIX', ''),
    'storage_api_uri' => env('GOOGLE_CLOUD_STORAGE_API_URI', null),
    'visibility' => 'private',
],
```

#### 5. Update BackupDatabase Command
Change line 97:
```php
$disk = \Illuminate\Support\Facades\Storage::disk('gcs'); // Changed from 's3'
```

---

## 3. 🔔 WebSocket Push Notifications

### Option A: Laravel Reverb (Recommended for Laravel 11+)

#### 1. Install Reverb
```bash
php artisan install:broadcasting
```

#### 2. Configure `.env`
```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http
```

#### 3. Start Reverb Server
```bash
php artisan reverb:start
```

#### 4. Create Announcement Event
```bash
php artisan make:event AnnouncementBroadcast
```

**File**: `app/Events/AnnouncementBroadcast.php`
```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnnouncementBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $announcement;

    public function __construct($announcement)
    {
        $this->announcement = $announcement;
    }

    public function broadcastOn()
    {
        return new Channel('announcements');
    }

    public function broadcastAs()
    {
        return 'new-announcement';
    }
}
```

#### 5. Trigger Event in Controller
Update `AnnouncementController::store()`:
```php
public function store(Request $request)
{
    // ... existing validation ...

    $announcement = \App\Models\Announcement::create($validated);

    // Broadcast to all connected clients
    broadcast(new \App\Events\AnnouncementBroadcast($announcement));

    return response()->json([
        'success' => true,
        'message' => 'Pengumuman berhasil dibuat.',
        'data' => $announcement
    ]);
}
```

#### 6. Frontend Integration (React)

Install Laravel Echo:
```bash
npm install --save laravel-echo pusher-js
```

**File**: `frontend-web/src/lib/echo.ts`
```typescript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

**Update**: `frontend-web/.env`
```env
VITE_REVERB_APP_KEY=your-app-key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

**Usage in Component**:
```typescript
import { echo } from '../lib/echo';
import { useEffect, useState } from 'react';

export const Dashboard = () => {
    const [newAnnouncement, setNewAnnouncement] = useState(null);

    useEffect(() => {
        echo.channel('announcements')
            .listen('.new-announcement', (e) => {
                console.log('New announcement:', e.announcement);
                setNewAnnouncement(e.announcement);
                // Show toast notification
                alert(`New announcement: ${e.announcement.title}`);
            });

        return () => {
            echo.leaveChannel('announcements');
        };
    }, []);

    // ... rest of component
};
```

---

### Option B: Pusher (Easier Setup)

#### 1. Create Pusher Account
Visit: https://pusher.com/

#### 2. Configure `.env`
```env
BROADCAST_CONNECTION=pusher

PUSHER_APP_ID=your-app-id
PUSHER_APP_KEY=your-app-key
PUSHER_APP_SECRET=your-app-secret
PUSHER_APP_CLUSTER=ap1
```

#### 3. Install Pusher PHP SDK
```bash
composer require pusher/pusher-php-server
```

#### 4. Frontend Setup (same as Reverb)
Change broadcaster to 'pusher' in echo.ts:
```typescript
export const echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true
});
```

---

## 4. 🛠️ Setup Instructions

### Complete Installation Steps

#### 1. Backend Setup
```bash
cd backend

# Install dependencies
composer install

# Run migrations (if not done)
php artisan migrate

# Test backup command
php artisan backup:database

# Test with cloud upload (after configuring S3/GCS)
php artisan backup:database --upload
```

#### 2. Configure Task Scheduler

**Linux/Mac**:
```bash
crontab -e
# Add:
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

**Windows**:
```powershell
# Run as Administrator
schtasks /create /tn "AbsensiQR Scheduler" /tr "php C:\path\to\backend\artisan schedule:run" /sc minute /ru SYSTEM
```

#### 3. Frontend Setup
```bash
cd frontend-web

# Install dependencies
npm install

# Add new pages to router
# (See next section)

# Start dev server
npm run dev
```

#### 4. Add Routes to Frontend

**File**: `frontend-web/src/App.tsx` (or your router file)
```typescript
import { AnnouncementsManagement } from './pages/SuperAdmin/AnnouncementsManagement';
import { SystemManagement } from './pages/SuperAdmin/SystemManagement';

// Add routes:
<Route path="/super-admin/announcements" element={<AnnouncementsManagement />} />
<Route path="/super-admin/system" element={<SystemManagement />} />
```

#### 5. Update Sidebar Menu

**File**: `frontend-web/src/layouts/SuperAdminLayout.tsx`
```typescript
const menuItems = [
    // ... existing items
    {
        name: 'Pengumuman',
        icon: Bell,
        path: '/super-admin/announcements'
    },
    {
        name: 'System Management',
        icon: Settings,
        path: '/super-admin/system'
    }
];
```

#### 6. Add Announcement Widget to Dashboards

**Admin Dashboard** (`frontend-web/src/pages/AdminDashboard.tsx`):
```typescript
import { AnnouncementWidget } from '../components/AnnouncementWidget';

// Inside component:
return (
    <div>
        <AnnouncementWidget />
        {/* Rest of dashboard */}
    </div>
);
```

**Teacher Dashboard** (`frontend-web/src/pages/TeacherDashboard.tsx`):
```typescript
import { AnnouncementWidget } from '../components/AnnouncementWidget';

// Same as above
```

---

## 5. 🧪 Testing

### Test Announcements
```bash
# Create announcement via API
curl -X POST http://localhost:8000/api/v1/super-admin/announcements \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Test Announcement",
    "content": "This is a test",
    "type": "info",
    "target_role": "all",
    "is_active": true
  }'

# Get active announcements
curl http://localhost:8000/api/v1/broadcasts \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Test Backup
```bash
# Manual backup
php artisan backup:database

# Check if file created
ls -lh storage/app/backups/

# Test cloud upload (if configured)
php artisan backup:database --upload
```

### Test Maintenance Mode
```bash
# Enable
curl -X POST http://localhost:8000/api/v1/super-admin/system/maintenance \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enable": true}'

# Check status
curl http://localhost:8000/api/v1/super-admin/system/maintenance/status \
  -H "Authorization: Bearer YOUR_TOKEN"

# Disable
curl -X POST http://localhost:8000/api/v1/super-admin/system/maintenance \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enable": false}'
```

---

## 6. 📊 Monitoring & Logs

### View Audit Logs
```sql
SELECT * FROM audit_logs 
WHERE action IN ('scheduled_backup', 'backup_failed', 'maintenance_mode_enabled')
ORDER BY created_at DESC 
LIMIT 10;
```

### Check Backup Files
```bash
# List all backups
ls -lh storage/app/backups/

# Check file size
du -sh storage/app/backups/*

# View latest backup
ls -lt storage/app/backups/ | head -n 2
```

### Monitor Scheduler
```bash
# View scheduler logs
tail -f storage/logs/laravel.log | grep schedule

# Test scheduler manually
php artisan schedule:run
```

---

## 7. 🚨 Troubleshooting

### Backup Command Fails
**Error**: `pg_dump: command not found`
**Solution**: Install PostgreSQL client tools
```bash
# Ubuntu/Debian
sudo apt-get install postgresql-client

# Mac
brew install postgresql

# Windows
# Download from https://www.postgresql.org/download/windows/
```

### Cloud Upload Fails
**Error**: `Unable to write file`
**Solution**: Check credentials and permissions
```bash
# Test S3 connection
php artisan tinker
>>> Storage::disk('s3')->put('test.txt', 'Hello World');
>>> Storage::disk('s3')->exists('test.txt');
```

### WebSocket Not Connecting
**Error**: `WebSocket connection failed`
**Solution**: 
1. Check Reverb server is running: `php artisan reverb:start`
2. Check firewall allows port 8080
3. Verify `.env` configuration
4. Check browser console for errors

---

## 8. 📈 Performance Optimization

### Backup Optimization
```php
// Use compression
$command = sprintf(
    'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -F c -Z 9 -f %s %s',
    // -F c = custom format (compressed)
    // -Z 9 = maximum compression
    // ...
);
```

### Cloud Upload Optimization
```php
// Use streaming instead of loading entire file
$disk->writeStream(
    'backups/' . $filename,
    fopen($filepath, 'r')
);
```

### WebSocket Optimization
```typescript
// Use presence channels for online users
echo.join('online-users')
    .here((users) => {
        console.log('Online users:', users);
    })
    .joining((user) => {
        console.log('User joined:', user);
    })
    .leaving((user) => {
        console.log('User left:', user);
    });
```

---

## 9. 🔐 Security Best Practices

### Backup Security
1. **Encrypt backups before upload**:
```bash
# Encrypt with GPG
gpg --symmetric --cipher-algo AES256 backup.sql
```

2. **Use IAM roles** (AWS) instead of access keys
3. **Enable versioning** on S3 bucket
4. **Set lifecycle policies** to auto-delete old backups

### WebSocket Security
1. **Use private channels** for sensitive data
2. **Implement authentication**:
```php
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

3. **Rate limit** broadcast events
4. **Use HTTPS/WSS** in production

---

## 10. ✅ Deployment Checklist

- [ ] Configure cloud storage (S3/GCS)
- [ ] Set up cron job for scheduler
- [ ] Test backup command
- [ ] Test cloud upload
- [ ] Configure WebSocket server (Reverb/Pusher)
- [ ] Update frontend .env with WebSocket credentials
- [ ] Add routes to frontend router
- [ ] Update sidebar menu
- [ ] Add AnnouncementWidget to dashboards
- [ ] Test announcements creation
- [ ] Test real-time notifications
- [ ] Test maintenance mode
- [ ] Monitor audit logs
- [ ] Set up monitoring alerts (optional)

---

**Last Updated**: 2026-01-21  
**Version**: 2.0.0  
**Status**: ✅ Ready for Production
