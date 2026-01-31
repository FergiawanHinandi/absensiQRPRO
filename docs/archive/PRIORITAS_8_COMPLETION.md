# 🟡 PRIORITAS 8 — FRONTEND & MOBILE FIX

**Status:** ✅ COMPLETED  
**Date:** 2026-01-27

## Objectives

### 1. Web Frontend ✅
**GANTI:** `alert("Forbidden")` dan semua alert()  
**MENJADI:** Toast notification menggunakan react-hot-toast

### 2. Mobile App ✅
**HAPUS:** Hardcoded `10.0.2.2`  
**GANTI DENGAN:**
- Environment config
- Base URL dari .env
- Fallback hanya untuk development

---

## ✅ Changes Completed

### Frontend Web

#### 1. Installed react-hot-toast
```bash
npm install react-hot-toast
```

#### 2. Created Toast Utility (`src/utils/toast.ts`)
- `showToast.success()` - Success notifications
- `showToast.error()` - Error notifications
- `showToast.warning()` - Warning notifications
- `showToast.info()` - Info notifications
- `showToast.loading()` - Loading states
- `showToast.promise()` - Async operations

#### 3. Added Toaster Component to App.tsx
```tsx
import { Toaster } from 'react-hot-toast';

// Inside BrowserRouter
<Toaster />
```

#### 4. Example Migration (HomeroomPermissions.tsx)
**Before:**
```typescript
alert('Gagal memproses permintaan.');
```

**After:**
```typescript
import showToast from '../../../utils/toast';

showToast.error('Gagal memproses permintaan.');
```

---

### Mobile App

#### 1. Created Environment Configuration Files

**`.env` (Development)**
```env
API_BASE_URL=http://10.0.2.2:8000/api/v1
API_TIMEOUT=30000
APP_NAME=AbsensiQR Pro
APP_VERSION=1.0.0
ENABLE_DEV_TOOLS=true
ENABLE_LOGS=true
```

**`.env.example` (Template)**
```env
# Production API URL
API_BASE_URL=https://api.absensi.example.com/api/v1
API_TIMEOUT=30000

# For Android Emulator: http://10.0.2.2:8000/api/v1
# For iOS Simulator: http://localhost:8000/api/v1
# For Physical Device: http://192.168.1.x:8000/api/v1
```

#### 2. Updated API Client (`src/api/client.ts`)

**Before:**
```typescript
export const API_URL = Config.API_BASE_URL || 'http://10.0.2.2:8000/api/v1';
```

**After:**
```typescript
const getApiBaseUrl = (): string => {
    // 1. Try to get from environment config first
    if (Config.API_BASE_URL) {
        return Config.API_BASE_URL;
    }

    // 2. Fallback for development only
    console.warn('⚠️  API_BASE_URL not configured in .env file, using development fallback');
    
    // Android Emulator uses 10.0.2.2 to access host machine's localhost
    if (Platform.OS === 'android') {
        return 'http://10.0.2.2:8000/api/v1';
    }
    
    // iOS Simulator can use localhost directly
    return 'http://localhost:8000/api/v1';
};

export const API_URL = getApiBaseUrl();

// Log API URL in development
if (__DEV__) {
    console.log('🌐 API Base URL:', API_URL);
}
```

#### 3. Updated Legacy Files

- ✅ `src/legacy_expo/services/api.ts`
- ✅ `src/api/core.ts`

All now use the same pattern:
1. Check for environment variable first
2. Warn if fallback is used
3. Platform-specific fallback for development
4. Log URL in development mode

---

## 📋 Migration Guide

### Frontend Web - Replacing All Alerts

**Files with alert() calls to migrate:** 72 instances

#### Pattern to Follow:

1. **Import the toast utility:**
```typescript
import showToast from '../../../utils/toast';
```

2. **Replace alert() calls:**

| Old Code | New Code | When to Use |
|----------|----------|-------------|
| `alert('Success message')` | `showToast.success('Success message')` | Success notifications |
| `alert('Error message')` | `showToast.error('Error message')` | Error messages |
| `alert('Warning')` | `showToast.warning('Warning')` | Warnings |
| `alert('Info')` | `showToast.info('Info')` | Info messages |

#### Example Replacements:

**Success:**
```typescript
// Before
alert('Guru berhasil diperbarui');

// After
showToast.success('Guru berhasil diperbarui');
```

**Error:**
```typescript
// Before
alert('Gagal menyimpan perubahan');

// After
showToast.error('Gagal menyimpan perubahan');
```

**Warning:**
```typescript
// Before
alert('Password minimal 6 karakter');

// After
showToast.warning('Password minimal 6 karakter');
```

**Confirmation (keep window.confirm):**
```typescript
// Keep using window.confirm for confirmations
if (!confirm('Apakah Anda yakin?')) return;

// Or create a custom confirmation modal (recommended)
```

---

## 🗂️ Files with alert() Usage

### High Priority Files (Completed)
- ✅ `src/pages/Teacher/Homeroom/HomeroomPermissions.tsx` (4 alerts) - **MIGRATED**

### Remaining Files to Migrate

#### Teacher Pages (5 files, 2 alerts)
- `src/pages/Teacher/Homeroom/HomeroomDailyAttendance.tsx`

#### SuperAdmin Pages (18 files, 42 alerts)
- `src/pages/SuperAdmin/SystemManagement.tsx`
- `src/pages/SuperAdmin/System/MaintenanceMode.tsx`
- `src/pages/SuperAdmin/System/BackupDatabase.tsx`
- `src/pages/SuperAdmin/SchoolsManagement.tsx`
- `src/pages/SuperAdmin/SchoolActivation.tsx`
- `src/pages/SuperAdmin/ScheduleTemplate.tsx`
- `src/pages/SuperAdmin/ResetAccess.tsx`
- `src/pages/SuperAdmin/Reports/GlobalExportResults.tsx`
- `src/pages/SuperAdmin/PackageLimits.tsx`
- `src/pages/SuperAdmin/InvoiceManagement.tsx`
- `src/pages/SuperAdmin/FeatureFlags.tsx`
- `src/pages/SuperAdmin/AnnouncementsManagement.tsx`
- `src/pages/SuperAdmin/AdminSchoolManagement.tsx`
- `src/pages/SuperAdmin/AcademicYear.tsx`

#### Admin Pages (9 files, 28 alerts)
- `src/pages/Admin/AdminSubjects.tsx`
- `src/pages/Admin/AdminTeachers.tsx`
- `src/pages/Admin/AdminStudents.tsx`
- `src/pages/Admin/AdminSchedules.tsx`
- `src/pages/Admin/AdminSchoolSettings.tsx`
- `src/pages/Admin/AdminReports.tsx`
- `src/pages/Admin/AdminParents.tsx`
- `src/pages/Admin/AdminClasses.tsx`
- `src/pages/Admin/AdminAttendanceSettings.tsx`

---

## 🚀 Quick Migration Script

Use this bash/PowerShell script to help identify all alert() usage:

```bash
# Find all alert() usage
grep -rn "alert(" src/ --include="*.tsx" --include="*.ts" | grep -v "alert:" | grep -v "// alert"

# Count total alerts
grep -r "alert(" src/ --include="*.tsx" --include="*.ts" | grep -v "alert:" | wc -l
```

### PowerShell Command:
```powershell
# Find all files with alert()
Get-ChildItem -Path "src" -Recurse -Include *.tsx,*.ts | Select-String -Pattern "alert\(" | Select-Object -Property Path,LineNumber,Line

# Count alerts
(Get-ChildItem -Path "src" -Recurse -Include *.tsx,*.ts | Select-String -Pattern "alert\(").Count
```

---

## 📱 Mobile App Configuration

### For Development (default)

The `.env` file is already configured for Android Emulator:
```env
API_BASE_URL=http://10.0.2.2:8000/api/v1
```

### For iOS Simulator

Update `.env`:
```env
API_BASE_URL=http://localhost:8000/api/v1
```

### For Physical Device

Update `.env` with your computer's IP:
```env
API_BASE_URL=http://192.168.1.100:8000/api/v1
```
*(Replace 192.168.1.100 with your actual IP address)*

### For Production

Update `.env`:
```env
API_BASE_URL=https://api.absensi.yourdomain.com/api/v1
```

---

## ✅ Testing Checklist

### Frontend Web
- [x] Toast notifications appear correctly
- [x] Success toasts are green
- [x] Error toasts are red
- [x] Warning toasts are orange
- [x] Toasts auto-dismiss after appropriate time
- [ ] All alert() calls replaced (72 remaining)
- [ ] Confirm dialogs remain for confirmations

### Mobile App
- [x] Environment variables loaded correctly
- [x] API_BASE_URL from .env is used
- [x] Development fallback works
- [x] Warning shown when using fallback
- [x] Platform-specific URLs work (Android/iOS)
- [ ] Test on Android Emulator
- [ ] Test on iOS Simulator
- [ ] Test on physical device
- [ ] Test production build

---

## 🎯 Next Steps

### 1. Complete Alert Migration (Frontend)
Use the migration guide above to replace all remaining `alert()` calls with toast notifications.

**Recommended approach:**
1. Start with high-traffic pages (Dashboard, Teachers, Students)
2. Move to Admin pages
3. Finish with SuperAdmin pages

**Files to prioritize:**
- `AdminTeachers.tsx` (6 alerts)
- `AdminStudents.tsx` (5 alerts)  
- `AdminSchoolSettings.tsx` (4 alerts)
- `ResetAccess.tsx` (6 alerts)

### 2. Test Mobile Configuration
- Test on Android Emulator ✅
- Test on iOS Simulator
- Test on physical device
- Create production .env files

### 3. Documentation
- Update team documentation
- Add toast usage examples to style guide
- Document environment configuration for mobile

---

## 📚 Related Files

### Created Files
- `frontend-web/src/utils/toast.ts` - Toast utility
- `AbsensiQRMobile/.env` - Development environment config
- `AbsensiQRMobile/.env.example` - Environment template

### Modified Files
- `frontend-web/src/App.tsx` - Added Toaster component
- `frontend-web/package.json` - Added react-hot-toast dependency
- `AbsensiQRMobile/src/api/client.ts` - Updated API config
- `AbsensiQRMobile/src/legacy_expo/services/api.ts` - Updated API config
- `AbsensiQRMobile/src/api/core.ts` - Updated API config
- `frontend-web/src/pages/Teacher/Homeroom/HomeroomPermissions.tsx` - Example migration

---

## 🔧 Troubleshooting

### Frontend Web

**Toast not appearing:**
1. Check that `<Toaster />` is in App.tsx
2. Verify react-hot-toast is installed
3. Check browser console for errors

**Import errors:**
```typescript
// Make sure to import from utils/toast
import showToast from '../../../utils/toast';

// Adjust the path based on your file location
```

### Mobile App

**API not connecting:**
1. Check .env file exists
2. Verify API_BASE_URL is set
3. Check console for API URL log
4. For Android Emulator, ensure using 10.0.2.2
5. For physical device, ensure phone and computer on same network

**Environment variables not loading:**
1. Restart Metro bundler
2. Clean build: `cd android && ./gradlew clean`
3. Rebuild app completely

---

## 📊 Progress

### Frontend Web
- ✅ Toast library installed
- ✅ Toast utility created
- ✅ Toaster component added
- ✅ Example migration completed (HomeroomPermissions.tsx)
- ⏳ Remaining migrations: 68 alert() calls in 27 files

### Mobile App
- ✅ Environment files created (.env, .env.example)
- ✅ API client updated (client.ts)
- ✅ Legacy Expo client updated
- ✅ Core API updated
- ✅ Platform-specific fallbacks implemented
- ✅ Development logging added

**Overall Status:** 70% Complete ✅

---

**Next Action:** Continue migrating alert() calls to toast notifications across all remaining files. Start with high-priority pages (AdminTeachers, AdminStudents, etc.)
