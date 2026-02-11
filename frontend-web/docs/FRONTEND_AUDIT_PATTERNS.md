# Frontend Architecture & Best Practices
**AbsensiQRPro - Frontend Audit Report**  
**Date:** February 7, 2026

---

## Executive Summary

### Issues Found: 23
| Severity | Count | Status |
|----------|-------|--------|
| 🔴 Critical | 1 | ✅ Fixed |
| 🟠 High | 8 | ✅ 2 Fixed, 6 Documented |
| 🟡 Medium | 9 | Documented |
| ⚪ Low | 5 | Documented |

### Core Principles Violated
1. ❌ **Client-side business logic** - Status calculations in frontend
2. ❌ **Hardcoded error messages** - Ignoring API responses
3. ❌ **Mock data in production** - Parent module hooks
4. ✅ **Auth flow** - Mostly centralized (minor issues)

---

## 1. Recommended Folder Structure

```
frontend-web/src/
├── lib/                      # Core infrastructure
│   ├── api.ts               # Axios instance + interceptors
│   ├── secureTokenStore.ts  # Token management
│   └── echo.ts              # WebSocket client
│
├── utils/                    # Pure utilities
│   ├── errorHandler.ts      # ✅ Centralized error extraction
│   ├── toast.ts             # Toast notifications
│   └── formatters.ts        # Date, currency formatters (NO logic)
│
├── types/                    # TypeScript interfaces
│   ├── api.ts               # API response types
│   ├── models.ts            # Domain model types
│   └── attendance.ts        # Attendance-specific types
│
├── store/                    # Global state (Zustand)
│   ├── authStore.ts         # Auth state (single source)
│   └── maintenanceStore.ts  # Maintenance mode
│
├── modules/                  # Feature modules
│   ├── admin/
│   │   ├── hooks/           # React Query hooks (data fetching)
│   │   ├── components/      # Presentation components
│   │   └── pages/           # Page components
│   ├── teacher/
│   ├── parent/
│   └── auth/
│
├── components/               # Shared components
│   ├── common/              # ErrorMessage, Loading, etc.
│   ├── layout/              # Sidebar, Header, etc.
│   └── ui/                  # Buttons, Inputs, etc.
│
└── pages/                    # Route-level pages
```

---

## 2. API Consumption Patterns

### ❌ WRONG: Client Calculates Business Logic

```tsx
// BAD: Frontend calculating attendance status
const getAttendanceStatus = (checkInTime: string, scheduleStart: string) => {
    const checkIn = new Date(`2024-01-01 ${checkInTime}`);
    const start = new Date(`2024-01-01 ${scheduleStart}`);
    
    if (checkIn > start) {
        return 'late';  // ❌ Business logic in client!
    }
    return 'present';
};

// BAD: Frontend calculating attendance rate
const attendanceRate = Math.round((present / total) * 100);  // ❌
```

### ✅ CORRECT: All State from API

```tsx
// GOOD: Use pre-calculated values from API
interface AttendanceResponse {
    status: 'present' | 'late' | 'absent';  // Determined by backend
    status_label: string;                     // "Hadir" - localized by backend
    is_late: boolean;                         // Backend checked against schedule
    attendance_rate: number;                  // Pre-calculated by backend
    attendance_rate_formatted: string;        // "95.5%"
}

// Component just displays
const AttendanceCard = ({ data }: { data: AttendanceResponse }) => (
    <div>
        <span className={getStatusColor(data.status)}>{data.status_label}</span>
        <span>Kehadiran: {data.attendance_rate_formatted}</span>
    </div>
);
```

---

## 3. Error Handling Pattern

### ❌ WRONG: Hardcoded Error Messages

```tsx
// BAD: Ignoring API error message
try {
    await apiClient.post('/attendance/scan', data);
} catch (error) {
    showToast.error('Gagal menyimpan absensi');  // ❌ Hardcoded!
}

// BAD: Generic catch without API message
.catch(() => setError('Gagal memuat data'))  // ❌ Ignores response
```

### ✅ CORRECT: Use Centralized Error Handler

```tsx
import { getErrorMessage, handleApiError } from '@/utils/errorHandler';
import showToast from '@/utils/toast';

// Option 1: Extract message manually
try {
    await apiClient.post('/attendance/scan', data);
} catch (error) {
    const message = getErrorMessage(error);  // ✅ Extracts API message
    showToast.error(message);
}

// Option 2: Use convenience function
try {
    await apiClient.post('/attendance/scan', data);
} catch (error) {
    handleApiError(error, showToast);  // ✅ One-liner
}

// Option 3: With React Query
const { error } = useQuery({...});
if (error) {
    return <ErrorMessage message={getErrorMessage(error)} />;  // ✅
}

// For validation errors (422)
import { getValidationErrors } from '@/utils/errorHandler';

try {
    await apiClient.post('/users', formData);
} catch (error) {
    const fieldErrors = getValidationErrors(error);
    // { name: 'Nama harus diisi', email: 'Email tidak valid' }
    setErrors(fieldErrors);
}
```

---

## 4. Data Fetching with React Query

### ❌ WRONG: useState + useEffect + Mock Data

```tsx
// BAD: Manual state management with mock data
const [data, setData] = useState(null);
const [loading, setLoading] = useState(true);

useEffect(() => {
    setTimeout(() => {
        setData(MOCK_DATA);  // ❌ Mock in production!
        setLoading(false);
    }, 500);
}, []);
```

### ✅ CORRECT: React Query Hooks

```tsx
// GOOD: Proper API hook
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api';

export const useStudentInfo = (studentId?: number) => {
    return useQuery({
        queryKey: ['student', studentId],
        queryFn: async () => {
            const response = await apiClient.get(`/students/${studentId}`);
            return response.data.data;  // ✅ Real API data
        },
        staleTime: 5 * 60 * 1000,  // 5 minutes cache
    });
};

// Usage in component
const StudentCard = ({ studentId }) => {
    const { data, isLoading, error, refetch } = useStudentInfo(studentId);
    
    if (isLoading) return <Loading />;
    if (error) return <ErrorMessage message={getErrorMessage(error)} onRetry={refetch} />;
    
    return <div>{data.name}</div>;  // ✅ All data from API
};
```

---

## 5. Auth Flow - Single Entry Point

### Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     Auth Flow Diagram                        │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Login Form                                                  │
│      │                                                       │
│      ▼                                                       │
│  useAuthStore.login()  ◄─── Single entry point              │
│      │                                                       │
│      ├── API: POST /auth/login                              │
│      │                                                       │
│      ├── tokenStore.setToken(token)  ◄─── Memory storage    │
│      │                                                       │
│      ├── sessionIndicator.set()  ◄─── Tab detection         │
│      │                                                       │
│      └── Navigate to role dashboard                         │
│                                                              │
│  ─────────────────────────────────────────────────────────  │
│                                                              │
│  401/403 Response (any API call)                            │
│      │                                                       │
│      ▼                                                       │
│  api.ts interceptor                                         │
│      │                                                       │
│      ├── tokenStore.clearToken()                            │
│      │                                                       │
│      ├── sessionIndicator.clear()                           │
│      │                                                       │
│      └── Redirect to /login                                 │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### ✅ Correct Pattern

```tsx
// lib/secureTokenStore.ts - ONLY place tokens are managed
export const tokenStore = {
    token: null as string | null,
    
    setToken(token: string) {
        this.token = token;
        // Also store in sessionStorage for tab refresh
        sessionStorage.setItem(TOKEN_KEY, token);
    },
    
    getToken(): string | null {
        if (this.token) return this.token;
        return sessionStorage.getItem(TOKEN_KEY);
    },
    
    clearToken() {
        this.token = null;
        sessionStorage.removeItem(TOKEN_KEY);
    }
};

// store/authStore.ts - Single auth state source
export const useAuthStore = create<AuthState>((set) => ({
    user: null,
    isAuthenticated: false,
    
    login: async (credentials) => {
        const response = await apiClient.post('/auth/login', credentials);
        const { token, user } = response.data.data;
        
        tokenStore.setToken(token);  // ✅ Centralized
        set({ user, isAuthenticated: true });
        
        return user;
    },
    
    logout: async () => {
        await apiClient.post('/auth/logout').catch(() => {});
        tokenStore.clearToken();  // ✅ Centralized
        set({ user: null, isAuthenticated: false });
    }
}));
```

---

## 6. Component Refactoring Examples

### Example 1: Smart Component → Dumb Component

#### ❌ BEFORE: Component with business logic

```tsx
// BAD: ClassAttendanceCard calculates rate
const ClassAttendanceCard = ({ classData }) => {
    // ❌ Business logic in component
    const calculatePercentage = (value, total) => {
        return total > 0 ? Math.round((value / total) * 100) : 0;
    };
    
    const attendanceRate = calculatePercentage(
        classData.present, 
        classData.total_students
    );
    
    // ❌ Client decides color based on rate
    const getRateColor = (rate) => {
        if (rate >= 90) return 'text-green-500';
        if (rate >= 75) return 'text-yellow-500';
        return 'text-red-500';
    };
    
    return (
        <div>
            <span className={getRateColor(attendanceRate)}>
                {attendanceRate}%
            </span>
        </div>
    );
};
```

#### ✅ AFTER: Pure presentation component

```tsx
// GOOD: Component only displays API data
interface ClassAttendanceData {
    class_name: string;
    present: number;
    total_students: number;
    attendance_rate: number;           // Pre-calculated by backend
    attendance_rate_display: string;   // "92.5%"
    rate_status: 'good' | 'warning' | 'critical';  // Backend determines
}

const ClassAttendanceCard = ({ data }: { data: ClassAttendanceData }) => {
    // ✅ Just map status to styles
    const statusStyles = {
        good: 'text-green-500',
        warning: 'text-yellow-500', 
        critical: 'text-red-500'
    };
    
    return (
        <div>
            <span className={statusStyles[data.rate_status]}>
                {data.attendance_rate_display}  {/* ✅ From API */}
            </span>
        </div>
    );
};
```

### Example 2: Fixing Mock Data Hooks

#### ❌ BEFORE: Mock data

```tsx
// BAD: Returns fake data
export const useStudentInfo = () => {
    const [data, setData] = useState(null);
    
    useEffect(() => {
        setTimeout(() => {
            setData({
                id: 1,
                name: 'Budi Santoso',  // ❌ Hardcoded!
            });
        }, 500);
    }, []);
    
    return { data, isLoading: !data };
};
```

#### ✅ AFTER: Real API hook

```tsx
// GOOD: Real API call
export const useStudentInfo = (studentId?: number) => {
    return useQuery({
        queryKey: ['student', studentId],
        queryFn: async () => {
            const response = await apiClient.get(`/parent/children/${studentId}`);
            return response.data.data;  // ✅ Real data from API
        },
    });
};
```

---

## 7. Files Modified in This Audit

| File | Change | Priority |
|------|--------|----------|
| [utils/errorHandler.ts](../src/utils/errorHandler.ts) | Complete rewrite with proper typing | ✅ Done |
| [modules/parent/hooks.ts](../src/modules/parent/hooks.ts) | Replaced mock data with React Query | ✅ Done |
| [pages/Admin/AdminDashboard.tsx](../src/pages/Admin/AdminDashboard.tsx) | Use API attendance_rate, use getErrorMessage | ✅ Done |

---

## 8. Remaining Issues to Fix

### High Priority

| File | Issue | Fix |
|------|-------|-----|
| `pages/Admin/Classes/*.tsx` | Hardcoded error messages | Use `getErrorMessage(error)` |
| `modules/teacher/pages/HomeroomDailyAttendance.tsx` | Mock data + hardcoded errors | Implement real API |
| `modules/teacher/pages/HomeroomPermissions.tsx` | Generic error in catch | Use `getErrorMessage(error)` |
| `components/dashboard/PackageLimits.tsx` | Magic numbers (90%, 75%) | Get thresholds from API |

### Medium Priority

| File | Issue | Fix |
|------|-------|-----|
| Multiple files | `.catch(() => setError('...')` pattern | Replace with `getErrorMessage` |
| `components/layout/Sidebar.tsx` | Hardcoded email check for super_admin | Use role_type only |
| Multiple pages | Client-side role routing logic | Centralize in utility |

### Pattern to Search & Replace

```bash
# Find hardcoded error messages
grep -r "showToast.error\('" --include="*.tsx" src/
grep -r "setError\('" --include="*.tsx" src/
grep -r "catch(() =>" --include="*.tsx" src/

# Replace pattern
# FROM: .catch(() => setError('Gagal memuat data'))
# TO:   .catch((err) => setError(getErrorMessage(err)))
```

---

## 9. API Response Contract

### Standard Success Response

```typescript
interface ApiSuccessResponse<T> {
    success: true;
    message?: string;
    data: T;
    meta?: {
        current_page?: number;
        total?: number;
        // ...pagination
    };
}
```

### Standard Error Response

```typescript
interface ApiErrorResponse {
    success: false;
    message: string;          // Human-readable, localized by backend
    error?: string;           // Error code (optional)
    errors?: {                // Validation errors (422)
        [field: string]: string[];
    };
    details?: {               // Additional context
        reason?: string;
        action?: string;
        contact?: string;
    };
}
```

### Attendance-Specific Fields

```typescript
interface AttendanceRecord {
    id: number;
    student_id: number;
    student_name: string;
    
    // ✅ All these are determined by BACKEND
    status: 'present' | 'late' | 'absent' | 'sick' | 'permit' | 'alpha';
    status_label: string;     // "Hadir", "Terlambat", etc.
    is_late: boolean;
    late_minutes: number;     // How late (calculated by backend)
    
    check_in_time: string | null;
    check_out_time: string | null;
    
    // Pre-calculated by backend
    attendance_rate?: number;
    attendance_rate_display?: string;  // "95.5%"
}
```

---

## 10. Checklist for New Components

When creating new components, verify:

- [ ] ❓ Does this component calculate any attendance status? → Move to backend
- [ ] ❓ Does this component calculate rates/percentages? → Use API values
- [ ] ❓ Does this component have hardcoded error messages? → Use `getErrorMessage`
- [ ] ❓ Does this component access localStorage for tokens? → Use `tokenStore`
- [ ] ❓ Does this component check roles for business logic? → API should return permitted actions
- [ ] ❓ Does this component have magic numbers? → Get from API config
