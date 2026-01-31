# Mobile App - Technical Specification & Implementation Plan

## 🎯 Target Platform
- **Android**: Min SDK 24 (Android 7.0) - Target SDK 34 (Android 14)
- **iOS**: Min iOS 13.0 - Target iOS 17.0

---

## 📱 App Architecture

### Technology Choice: **React Native** (Recommended)

**Alasan:**
1. ✅ Code sharing dengan web (sama-sama React)
2. ✅ Developer sudah familiar dengan TypeScript
3. ✅ Ekosistem library yang mature untuk QR, Camera, Location
4. ✅ Fast development & hot reload
5. ✅ Native performance untuk QR scanning

### Alternative: **Flutter** (if team prefer Dart)

---

## 🗂️ Project Structure

```
absensiQRMobile/
├── android/                    # Android native code
├── ios/                        # iOS native code
├── src/
│   ├── api/
│   │   ├── client.ts          # Axios instance
│   │   ├── auth.api.ts        # Auth endpoints
│   │   ├── attendance.api.ts  # Attendance endpoints
│   │   └── student.api.ts     # Student endpoints
│   │
│   ├── components/
│   │   ├── common/
│   │   │   ├── Button.tsx
│   │   │   ├── Input.tsx
│   │   │   ├── Card.tsx
│   │   │   └── Loading.tsx
│   │   ├── attendance/
│   │   │   ├── QRScanner.tsx          # Main QR Scanner
│   │   │   ├── ScanOverlay.tsx        # UI overlay for scanner
│   │   │   └── AttendanceCard.tsx     # History card
│   │   └── navigation/
│   │       └── TabBar.tsx
│   │
│   ├── screens/
│   │   ├── auth/
│   │   │   ├── SplashScreen.tsx
│   │   │   ├── LoginScreen.tsx
│   │   │   └── ForgotPasswordScreen.tsx
│   │   ├── dashboard/
│   │   │   └── DashboardScreen.tsx
│   │   ├── attendance/
│   │   │   ├── ScanQRScreen.tsx       # QR Scanner page
│   │   │   └── HistoryScreen.tsx      # Attendance history
│   │   ├── schedule/
│   │   │   └── ScheduleScreen.tsx
│   │   └── profile/
│   │       └── ProfileScreen.tsx
│   │
│   ├── navigation/
│   │   ├── RootNavigator.tsx          # Main navigator
│   │   ├── AuthNavigator.tsx          # Auth stack
│   │   └── MainNavigator.tsx          # Logged in stack
│   │
│   ├── store/
│   │   ├── slices/
│   │   │   ├── authSlice.ts          # Auth state
│   │   │   ├── attendanceSlice.ts    # Attendance state
│   │   │   └── scheduleSlice.ts      # Schedule state
│   │   └── index.ts                   # Store config
│   │
│   ├── hooks/
│   │   ├── useAuth.ts
│   │   ├── useAttendance.ts
│   │   ├── useLocation.ts             # GPS hook
│   │   └── useCamera.ts               # Camera permission
│   │
│   ├── utils/
│   │   ├── storage.ts                 # SecureStorage wrapper
│   │   ├── validation.ts              # Form validation
│   │   ├── formatter.ts               # Date, time formatter
│   │   └── permissions.ts             # Permission handler
│   │
│   ├── constants/
│   │   ├── colors.ts
│   │   ├── api.ts                     # API endpoints
│   │   └── config.ts
│   │
│   └── types/
│       ├── auth.types.ts
│       ├── attendance.types.ts
│       └── api.types.ts
│
├── App.tsx                            # Root component
├── package.json
└── tsconfig.json
```

---

## 📋 Feature Breakdown

### 1. **Authentication (Priority: HIGH)**

#### Screens:
- `SplashScreen.tsx` - Logo + check auth status
- `LoginScreen.tsx` - Username/Email + Password
- `ForgotPasswordScreen.tsx` (optional v2)

#### API Endpoints:
```typescript
POST /api/v1/auth/login
GET  /api/v1/auth/me
POST /api/v1/auth/logout
```

#### Local Storage:
- Token (SecureStorage)
- User data (AsyncStorage dengan encryption)
- Remember me preference

---

### 2. **Dashboard (Priority: HIGH)**

#### UI Components:
- Welcome Card (Nama + Kelas)
- Attendance Summary Widget (Hadir / Total Hari)
- Quick Actions (Scan QR, View History)
- Today's Schedule

#### Data Required:
```typescript
interface DashboardData {
  student: {
    id: number;
    name: string;
    nis: string;
    class_name: string;
  };
  todayAttendance: {
    total_sessions: number;
    attended: number;
    pending: number;
  };
  monthSummary: {
    present: number;
    late: number;
    sick: number;
    permit: number;
    alpha: number;
  };
  todaySchedule: Schedule[];
}
```

---

### 3. **QR Scanner (Priority: CRITICAL)** 🎯

#### Library:
```bash
npm install react-native-vision-camera
npm install vision-camera-code-scanner
```

#### Permissions Required:
- Camera access
- Location access (GPS)

#### Flow:
1. Open camera with QR scanner overlay
2. Detect QR code (format: `ABSENSI-{id}-{timestamp}-{hash}`)
3. Get current GPS coordinates
4. Submit to backend
5. Show success/error toast
6. Close camera

#### API Call:
```typescript
POST /api/v1/attendance/check-in
Body: {
  qr_code: string;
  latitude: number;
  longitude: number;
}

Response: {
  success: boolean;
  message: string;
  data: {
    status: 'present' | 'late';
    check_in_time: string;
    schedule: {...}
  }
}
```

#### Error Handling:
- Invalid QR format
- QR expired (more than 5 minutes)
- Wrong location (outside school)
- Already checked in
- Network error

---

### 4. **Attendance History (Priority: HIGH)**

#### UI:
- List of past attendances
- Filter by date range
- Color-coded status (Green=Hadir, Orange=Terlambat, Red=Alpha)
- Pull-to-refresh
- Infinite scroll / pagination

#### API:
```typescript
GET /api/v1/student/attendance-history?page=1&per_page=20&start_date=2026-01-01

Response: {
  data: Attendance[];
  meta: {
    current_page: number;
    total: number;
    per_page: number;
  }
}
```

---

### 5. **Schedule View (Priority: MEDIUM)**

#### UI:
- Weekly calendar view
- List of today's classes
- Subject, time, room info

#### API:
```typescript
GET /api/v1/student/schedule?date=2026-01-23

Response: {
  data: Schedule[];
}
```

---

### 6. **Profile (Priority: LOW)**

#### Display:
- Student photo
- Name, NIS, NISN
- Class
- Contact info
- Logout button

#### Features:
- View profile (read-only)
- Change password (v2)
- Logout

---

## 🔐 Security Implementation

### 1. **Token Management**

```typescript
// utils/storage.ts
import EncryptedStorage from 'react-native-encrypted-storage';

export const StorageKeys = {
  TOKEN: '@auth_token',
  USER: '@auth_user',
};

export const storage = {
  async setToken(token: string) {
    await EncryptedStorage.setItem(StorageKeys.TOKEN, token);
  },
  
  async getToken(): Promise<string | null> {
    return await EncryptedStorage.getItem(StorageKeys.TOKEN);
  },
  
  async removeToken() {
    await EncryptedStorage.removeItem(StorageKeys.TOKEN);
  },
  
  async setUser(user: any) {
    await EncryptedStorage.setItem(StorageKeys.USER, JSON.stringify(user));
  },
  
  async getUser() {
    const data = await EncryptedStorage.getItem(StorageKeys.USER);
    return data ? JSON.parse(data) : null;
  }
};
```

### 2. **API Interceptor**

```typescript
// api/client.ts
import axios from 'axios';
import { storage } from '../utils/storage';

const API_URL = 'http://YOUR_SERVER_IP:8000/api/v1';

const apiClient = axios.create({
  baseURL: API_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Request interceptor - add token
apiClient.interceptors.request.use(
  async (config) => {
    const token = await storage.getToken();
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// Response interceptor - handle 401
apiClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      await storage.removeToken();
      await storage.removeUser();
      // Navigate to login
      // NavigationService.navigate('Login');
    }
    return Promise.reject(error);
  }
);

export default apiClient;
```

---

## 🎨 Design System

### Colors
```typescript
// constants/colors.ts
export const Colors = {
  primary: '#3B82F6',
  primary_dark: '#2563EB',
  primary_light: '#60A5FA',
  
  success: '#10B981',
  warning: '#F59E0B',
  error: '#EF4444',
  info: '#3B82F6',
  
  background: '#F9FAFB',
  surface: '#FFFFFF',
  
  text_primary: '#111827',
  text_secondary: '#6B7280',
  text_disabled: '#9CA3AF',
  
  border: '#E5E7EB',
  divider: '#E5E7EB',
};
```

### Typography
```typescript
export const Typography = {
  h1: { fontSize: 32, fontWeight: '700' as const },
  h2: { fontSize: 24, fontWeight: '700' as const },
  h3: { fontSize: 20, fontWeight: '600' as const },
  body1: { fontSize: 16, fontWeight: '400' as const },
  body2: { fontSize: 14, fontWeight: '400' as const },
  caption: { fontSize: 12, fontWeight: '400' as const },
};
```

---

## 📦 Dependencies

### Required Packages

```json
{
  "dependencies": {
    "react": "^18.2.0",
    "react-native": "^0.73.0",
    
    // Navigation
    "@react-navigation/native": "^6.1.9",
    "@react-navigation/stack": "^6.3.20",
    "@react-navigation/bottom-tabs": "^6.5.11",
    "react-native-screens": "^3.29.0",
    "react-native-safe-area-context": "^4.8.2",
    
    // State Management
    "@reduxjs/toolkit": "^2.0.1",
    "react-redux": "^9.0.4",
    
    // API
    "axios": "^1.6.5",
    
    // QR Scanner
    "react-native-vision-camera": "^3.8.0",
    "vision-camera-code-scanner": "^0.2.0",
    
    // Location
    "@react-native-community/geolocation": "^3.2.1",
    "react-native-permissions": "^4.0.3",
    
    // Storage
    "react-native-encrypted-storage": "^4.0.3",
    
    // UI Components
    "react-native-vector-icons": "^10.0.3",
    "react-native-linear-gradient": "^2.8.3",
    
    // Utils
    "date-fns": "^3.0.6"
  },
  "devDependencies": {
    "@types/react": "^18.2.48",
    "@types/react-native": "^0.73.0",
    "typescript": "^5.3.3"
  }
}
```

---

## ✅ Implementation Checklist

### Phase 1: MVP (Week 1-2)
- [ ] Project setup (React Native init)
- [ ] Install dependencies
- [ ] Setup navigation structure
- [ ] Implement SecureStorage wrapper
- [ ] Create API client with interceptors
- [ ] Build Login screen
- [ ] Build Dashboard screen
- [ ] Implement QR Scanner (CRITICAL)
- [ ] Build Attendance History
- [ ] Setup Redux/Zustand store
- [ ] Basic error handling

### Phase 2: Enhancement (Week 3)
- [ ] Add Schedule screen
- [ ] Add Profile screen
- [ ] Implement pull-to-refresh
- [ ] Add loading states
- [ ] Improve error messages
- [ ] Add offline detection
- [ ] Permission handling (Camera, GPS)

### Phase 3: Polish (Week 4)
- [ ] Add animations
- [ ] Splash screen with logo
- [ ] App icon design
- [ ] Push notifications (optional)
- [ ] Biometric login (optional)
- [ ] Beta testing (TestFlight/Internal Testing)

### Phase 4: Release
- [ ] Production build
- [ ] Google Play Store release
- [ ] Apple App Store release

---

## 🧪 Testing Strategy

### Unit Tests
- API client tests
- Storage utility tests
- Validation function tests

### Integration Tests
- Login flow
- QR scan flow
- API integration tests

### E2E Tests (Detox)
- Complete user journey
- Critical paths (Login → Scan → History)

---

## 🚀 Deployment

### Android
```bash
cd android
./gradlew assembleRelease
# Output: android/app/build/outputs/apk/release/app-release.apk
```

### iOS
```bash
cd ios
pod install
# Open Xcode → Archive → Upload to App Store
```

---

## 📊 Performance Targets

- App launch: < 3 seconds
- QR scan detect: < 500ms
- API response: < 2 seconds
- Screen transition: 60fps
- Memory usage: < 150MB

---

## 🔍 Monitoring & Analytics

### Recommended Tools:
- **Crashlytics** (Firebase) - Crash reporting
- **Analytics** (Firebase) - User behavior
- **Sentry** - Error tracking

### Key Metrics:
- Daily Active Users (DAU)
- QR Scan success rate
- API error rate
- Average session duration

---

**Next Step:** Create boilerplate project?

---

**Version**: 1.0.0  
**Last Updated**: 2026-01-23
