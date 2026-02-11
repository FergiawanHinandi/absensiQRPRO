# API Response Standardization

## 📋 Overview

**Version:** 1.0.0  
**Date:** 2026-02-07  
**Status:** ✅ COMPLETED

### Objective
Standarisasi seluruh response API agar konsisten antara mobile dan web dengan format yang predictable dan mudah di-handle.

---

## 🎯 Masalah yang Diperbaiki

### ❌ Problem 1: Format Error Tidak Konsisten

**Before (Inconsistent):**
```php
// Controller A
return response()->json(['error' => 'Not found'], 404);

// Controller B
return response()->json(['message' => 'Not found', 'status' => 'error'], 404);

// Controller C
return response()->json(['success' => false, 'data' => null, 'message' => 'Not found'], 404);

// Controller D
throw new Exception('Not found');  // Returns HTML error page
```

**Issues:**
- Frontend tidak tahu field mana yang harus di-check
- Berbeda-beda antara endpoint
- Sulit untuk error handling yang konsisten

---

### ❌ Problem 2: Pagination Berbeda

**Before (Inconsistent):**
```php
// Controller A
return response()->json([
    'data' => $users->items(),
    'meta' => [
        'current_page' => $users->currentPage(),
        'last_page' => $users->lastPage(),
        'per_page' => $users->perPage(),
        'total' => $users->total(),
    ],
]);

// Controller B
return response()->json([
    'items' => $users->items(),
    'pagination' => [
        'page' => $users->currentPage(),
        'pages' => $users->lastPage(),
        'count' => $users->total(),
    ],
]);

// Controller C
return $users;  // Laravel default pagination format
```

**Issues:**
- Frontend harus handle 3 format berbeda
- Tidak ada `success` flag
- Tidak ada `message` field

---

### ❌ Problem 3: Success Response Tidak Konsisten

**Before:**
```php
// Controller A
return response()->json($data);

// Controller B
return response()->json(['data' => $data]);

// Controller C
return response()->json(['success' => true, 'data' => $data]);

// Controller D
return response()->json(['status' => 'ok', 'result' => $data]);
```

---

## ✅ Solusi: ResponseFormatter Class

### Standard Format

```json
{
  "success": true|false,
  "code": 200,
  "message": "Success message",
  "data": {...},
  "meta": {...}
}
```

### Field Definitions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `success` | boolean | ✅ Yes | Indicates if request was successful |
| `code` | integer | ✅ Yes | HTTP status code (200, 404, 500, etc.) |
| `message` | string | ✅ Yes | Human-readable message |
| `data` | any | ❌ No | Response data (null for errors) |
| `meta` | object | ❌ No | Additional metadata (pagination, etc.) |
| `errors` | object | ❌ No | Validation errors (only for 422) |

---

## 📝 ResponseFormatter Methods

### 1. **Success Response**

```php
ResponseFormatter::success($data, $message, $code)
```

**Example:**
```php
return ResponseFormatter::success(
    ['user' => $user],
    'User retrieved successfully'
);
```

**Output:**
```json
{
  "success": true,
  "code": 200,
  "message": "User retrieved successfully",
  "data": {
    "user": {...}
  }
}
```

---

### 2. **Error Response**

```php
ResponseFormatter::error($message, $code, $errors, $data)
```

**Example:**
```php
return ResponseFormatter::error(
    'User not found',
    404
);
```

**Output:**
```json
{
  "success": false,
  "code": 404,
  "message": "User not found"
}
```

---

### 3. **Paginated Response**

```php
ResponseFormatter::paginated($paginator, $message)
```

**Example:**
```php
$users = User::paginate(20);
return ResponseFormatter::paginated($users, 'Users retrieved successfully');
```

**Output:**
```json
{
  "success": true,
  "code": 200,
  "message": "Users retrieved successfully",
  "data": [
    {"id": 1, "name": "John"},
    {"id": 2, "name": "Jane"}
  ],
  "meta": {
    "pagination": {
      "current_page": 1,
      "last_page": 5,
      "per_page": 20,
      "total": 100,
      "from": 1,
      "to": 20
    }
  }
}
```

---

### 4. **Collection Response**

```php
ResponseFormatter::collection($collection, $message, $meta)
```

**Example:**
```php
$classes = Classroom::all();
return ResponseFormatter::collection($classes, 'Classes retrieved successfully');
```

**Output:**
```json
{
  "success": true,
  "code": 200,
  "message": "Classes retrieved successfully",
  "data": [
    {"id": 1, "name": "Class A"},
    {"id": 2, "name": "Class B"}
  ]
}
```

---

### 5. **Created Response (201)**

```php
ResponseFormatter::created($data, $message)
```

**Example:**
```php
$user = User::create($request->all());
return ResponseFormatter::created($user, 'User created successfully');
```

**Output:**
```json
{
  "success": true,
  "code": 201,
  "message": "User created successfully",
  "data": {
    "id": 123,
    "name": "John Doe"
  }
}
```

---

### 6. **Updated Response (200)**

```php
ResponseFormatter::updated($data, $message)
```

---

### 7. **Deleted Response (200)**

```php
ResponseFormatter::deleted($message)
```

**Example:**
```php
$user->delete();
return ResponseFormatter::deleted('User deleted successfully');
```

**Output:**
```json
{
  "success": true,
  "code": 200,
  "message": "User deleted successfully",
  "data": null
}
```

---

### 8. **Validation Error (422)**

```php
ResponseFormatter::validationError($errors, $message)
```

**Example:**
```php
return ResponseFormatter::validationError(
    ['email' => ['The email field is required.']],
    'Validation failed'
);
```

**Output:**
```json
{
  "success": false,
  "code": 422,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

---

### 9. **Unauthorized (401)**

```php
ResponseFormatter::unauthorized($message)
```

---

### 10. **Forbidden (403)**

```php
ResponseFormatter::forbidden($message)
```

---

### 11. **Not Found (404)**

```php
ResponseFormatter::notFound($message)
```

---

### 12. **Server Error (500)**

```php
ResponseFormatter::serverError($message, $errors)
```

**Note:** `$errors` only included in debug mode

---

### 13. **Too Many Requests (429)**

```php
ResponseFormatter::tooManyRequests($message, $retryAfter)
```

**Output:**
```json
{
  "success": false,
  "code": 429,
  "message": "Too many requests",
  "meta": {
    "retry_after": 60
  }
}
```

**Headers:** `Retry-After: 60`

---

### 14. **Custom with Meta**

```php
ResponseFormatter::withMeta($data, $message, $meta, $code)
```

**Example:**
```php
return ResponseFormatter::withMeta(
    ['attendance' => $attendance],
    'Attendance recorded',
    ['timestamp' => now()->toIso8601String()],
    200
);
```

**Output:**
```json
{
  "success": true,
  "code": 200,
  "message": "Attendance recorded",
  "data": {
    "attendance": {...}
  },
  "meta": {
    "timestamp": "2026-02-07T14:50:53+08:00"
  }
}
```

---

## 🔄 Migration Examples

### Example 1: Simple Success Response

#### ❌ BEFORE:
```php
public function index()
{
    $users = User::all();
    return response()->json($users);
}
```

#### ✅ AFTER:
```php
use App\Http\Responses\ResponseFormatter;

public function index()
{
    $users = User::all();
    return ResponseFormatter::collection($users, 'Users retrieved successfully');
}
```

---

### Example 2: Paginated Response

#### ❌ BEFORE:
```php
public function index(Request $request)
{
    $students = Student::paginate(20);
    
    return response()->json([
        'data' => $students->items(),
        'meta' => [
            'current_page' => $students->currentPage(),
            'last_page' => $students->lastPage(),
            'per_page' => $students->perPage(),
            'total' => $students->total(),
        ],
    ]);
}
```

#### ✅ AFTER:
```php
use App\Http\Responses\ResponseFormatter;

public function index(Request $request)
{
    $students = Student::paginate(20);
    
    return ResponseFormatter::paginated($students, 'Students retrieved successfully');
}
```

---

### Example 3: Error Response

#### ❌ BEFORE:
```php
public function show($id)
{
    $user = User::find($id);
    
    if (!$user) {
        return response()->json(['error' => 'User not found'], 404);
    }
    
    return response()->json($user);
}
```

#### ✅ AFTER:
```php
use App\Http\Responses\ResponseFormatter;

public function show($id)
{
    $user = User::find($id);
    
    if (!$user) {
        return ResponseFormatter::notFound('User not found');
    }
    
    return ResponseFormatter::success($user, 'User retrieved successfully');
}
```

---

### Example 4: Validation Error

#### ❌ BEFORE:
```php
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email',
        'name' => 'required',
    ]);
    
    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }
    
    $user = User::create($request->all());
    return response()->json($user, 201);
}
```

#### ✅ AFTER:
```php
use App\Http\Responses\ResponseFormatter;

public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email',
        'name' => 'required',
    ]);
    
    if ($validator->fails()) {
        return ResponseFormatter::validationError(
            $validator->errors()->toArray(),
            'Validation failed'
        );
    }
    
    $user = User::create($request->all());
    return ResponseFormatter::created($user, 'User created successfully');
}
```

**Or use FormRequest:**
```php
use App\Http\Requests\StoreUserRequest;
use App\Http\Responses\ResponseFormatter;

public function store(StoreUserRequest $request)
{
    // Validation handled by FormRequest
    $user = User::create($request->validated());
    return ResponseFormatter::created($user, 'User created successfully');
}
```

---

### Example 5: Dashboard Summary

#### ❌ BEFORE:
```php
public function dashboardSummary(Request $request)
{
    $summary = Cache::remember('dashboard_summary', 60, function () {
        return [
            'total_students' => Student::count(),
            'total_teachers' => Teacher::count(),
        ];
    });
    
    return response()->json($summary);
}
```

#### ✅ AFTER:
```php
use App\Http\Responses\ResponseFormatter;

public function dashboardSummary(Request $request)
{
    $summary = Cache::remember('dashboard_summary', 60, function () {
        return [
            'total_students' => Student::count(),
            'total_teachers' => Teacher::count(),
        ];
    });
    
    return ResponseFormatter::success($summary, 'Dashboard summary retrieved successfully');
}
```

---

### Example 6: Attendance Scan

#### ❌ BEFORE:
```php
public function scan(Request $request)
{
    try {
        $attendance = $this->attendanceService->checkIn($student, $data);
        
        return response()->json([
            'status' => 'success',
            'attendance' => $attendance,
        ]);
    } catch (AttendanceException $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 400);
    }
}
```

#### ✅ AFTER:
```php
use App\Http\Responses\ResponseFormatter;

public function scan(Request $request)
{
    try {
        $attendance = $this->attendanceService->checkIn($student, $data);
        
        return ResponseFormatter::success(
            ['attendance' => $attendance],
            'Attendance recorded successfully'
        );
    } catch (AttendanceException $e) {
        return ResponseFormatter::error($e->getMessage(), 400);
    }
}
```

---

## 📱 Frontend Integration

### TypeScript Interface

```typescript
// types/api.ts
export interface ApiResponse<T = any> {
  success: boolean;
  code: number;
  message: string;
  data?: T;
  meta?: {
    pagination?: {
      current_page: number;
      last_page: number;
      per_page: number;
      total: number;
      from: number;
      to: number;
    };
    [key: string]: any;
  };
  errors?: {
    [key: string]: string[];
  };
}
```

### Axios Interceptor

```typescript
// api/client.ts
import axios from 'axios';
import { ApiResponse } from '../types/api';

const apiClient = axios.create({
  baseURL: process.env.REACT_APP_API_URL,
});

// Response interceptor
apiClient.interceptors.response.use(
  (response) => {
    // All responses now have consistent format
    const data: ApiResponse = response.data;
    
    if (!data.success) {
      // Handle API-level errors
      throw new Error(data.message);
    }
    
    return response;
  },
  (error) => {
    // Handle network errors
    if (error.response) {
      const data: ApiResponse = error.response.data;
      throw new Error(data.message || 'An error occurred');
    }
    throw error;
  }
);

export default apiClient;
```

### React Hook Example

```typescript
// hooks/useUsers.ts
import { useState, useEffect } from 'react';
import apiClient from '../api/client';
import { ApiResponse } from '../types/api';

interface User {
  id: number;
  name: string;
  email: string;
}

export const useUsers = () => {
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  
  useEffect(() => {
    const fetchUsers = async () => {
      try {
        const response = await apiClient.get<ApiResponse<User[]>>('/users');
        
        // Consistent format - always check response.data.data
        setUsers(response.data.data || []);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'An error occurred');
      } finally {
        setLoading(false);
      }
    };
    
    fetchUsers();
  }, []);
  
  return { users, loading, error };
};
```

### React Native Example

```typescript
// api/attendance.ts
import axios from 'axios';
import { ApiResponse } from '../types/api';

export const scanAttendance = async (data: ScanData): Promise<Attendance> => {
  try {
    const response = await axios.post<ApiResponse<{ attendance: Attendance }>>(
      '/api/v1/attendance/scan',
      data
    );
    
    // Consistent format - always response.data.data
    return response.data.data!.attendance;
  } catch (error) {
    if (axios.isAxiosError(error) && error.response) {
      const apiError: ApiResponse = error.response.data;
      throw new Error(apiError.message);
    }
    throw error;
  }
};
```

---

## 🔧 FormRequest Integration

### Custom FormRequest Base Class

```php
<?php

namespace App\Http\Requests;

use App\Http\Responses\ResponseFormatter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest as BaseFormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class FormRequest extends BaseFormRequest
{
    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     * @return void
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            ResponseFormatter::validationError(
                $validator->errors()->toArray(),
                'Validation failed'
            )
        );
    }
}
```

### Usage

```php
<?php

namespace App\Http\Requests;

class StoreUserRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    
    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
        ];
    }
}
```

**Controller:**
```php
public function store(StoreUserRequest $request)
{
    // Validation errors automatically formatted with ResponseFormatter
    $user = User::create($request->validated());
    return ResponseFormatter::created($user, 'User created successfully');
}
```

---

## ✅ Summary

### Changes Made
1. ✅ Created `ResponseFormatter` class
2. ✅ Standardized response format
3. ✅ Added helper methods for common responses
4. ✅ Created TypeScript interfaces
5. ✅ Added frontend integration examples

### Standard Format
```json
{
  "success": true|false,
  "code": 200,
  "message": "Success message",
  "data": {...},
  "meta": {...}
}
```

### Benefits
- 🎯 **Consistent format** across all endpoints
- 📱 **Easy frontend integration** (no guessing)
- 🔧 **Type-safe** with TypeScript
- 📊 **Predictable pagination** format
- ⚡ **Better error handling**
- 🧪 **Easier testing**

---

**Status:** ✅ PRODUCTION READY  
**Next Action:** Refactor all controllers to use ResponseFormatter
