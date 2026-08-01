# Bulk Insert Optimization Guide

## Objective
Optimize user import for large datasets (>5000 records) using bulk insert while ensuring data integrity and security.

---

## The Problem

### Current Approach (Inefficient for Large Datasets)
```php
// Traditional way - 5000 separate INSERT queries
foreach ($students as $studentData) {
    User::create([
        'name' => $studentData['name'],
        'email' => $studentData['email'],
        'password' => Hash::make($studentData['password']),
        // ... other fields
    ]);
}

// Result: 5000 queries, ~60-120 seconds for 5000 records
```

**Problems:**
- One database query per record
- Extremely slow for large datasets
- High database connection overhead
- Can timeout on large imports

### Optimized Approach (Bulk Insert)
```php
// Prepare all data first
$users = [];
foreach ($students as $studentData) {
    $users[] = [
        'name' => $studentData['name'],
        'email' => $studentData['email'],
        'password' => Hash::make($studentData['password']),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

// Single INSERT query
User::insert($users);

// Result: 1 query, ~2-5 seconds for 5000 records
```

**Benefits:**
- Single database query
- 20-40x faster for large datasets
- No timeout issues
- Efficient resource usage

---

## Implementation Pattern

### Step 1: Detect Large Dataset

```php
public function import(Request $request)
{
    $students = $this->parseImportFile($request->file('file'));
    
    $count = count($students);
    
    if ($count > 5000) {
        // Use bulk insert for large datasets
        return $this->bulkInsert($students);
    } else {
        // Use normal create for small datasets (supports events/observers)
        return $this->normalInsert($students);
    }
}
```

### Step 2: Implement Bulk Insert

```php
private function bulkInsert(array $students): array
{
    DB::beginTransaction();
    
    try {
        $now = now();
        $schoolId = auth()->user()->school_id;
        
        // Prepare data with pre-hashed passwords
        $usersData = [];
        foreach ($students as $student) {
            $usersData[] = [
                'school_id' => $schoolId,
                'name' => $student['name'],
                'email' => $student['email'],
                'username' => $student['username'],
                'password' => Hash::make($student['password']), // Hash before insert!
                'nis' => $student['nis'] ?? null,
                'role_type' => 'student',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        
        // Bulk insert - single query
        User::insert($usersData);
        
        DB::commit();
        
        return [
            'success' => true,
            'created' => count($usersData),
        ];
    } catch (\Exception $e) {
        DB::rollBack();
        throw $e;
    }
}
```

### Step 3: Handle Password Hashing

**CRITICAL:** Passwords MUST be hashed BEFORE bulk insert!

```php
// ✅ CORRECT - Hash before insert
$usersData = [];
foreach ($students as $student) {
    $usersData[] = [
        'password' => Hash::make($student['password']), // Hash HERE
        // ... other fields
    ];
}
User::insert($usersData);
```

```php
// ❌ WRONG - Cannot hash after insert
User::insert($students); // Passwords stored as plain text!
```

### Step 4: Validate Before Bulk Insert

```php
private function validateForBulkInsert(array $students): array
{
    $errors = [];
    $validData = [];
    
    $existingEmails = User::whereIn('email', array_column($students, 'email'))
        ->pluck('email')
        ->toArray();
    
    $existingNIS = User::whereIn('nis', array_column($students, 'nis'))
        ->pluck('nis')
        ->toArray();
    
    foreach ($students as $index => $student) {
        $rowErrors = [];
        
        // Validate required fields
        if (empty($student['name'])) {
            $rowErrors[] = 'Nama wajib diisi';
        }
        
        if (empty($student['email']) || !filter_var($student['email'], FILTER_VALIDATE_EMAIL)) {
            $rowErrors[] = 'Email tidak valid';
        }
        
        // Check duplicates
        if (in_array($student['email'], $existingEmails)) {
            $rowErrors[] = 'Email sudah terdaftar';
        }
        
        if (isset($student['nis']) && in_array($student['nis'], $existingNIS)) {
            $rowErrors[] = 'NIS sudah terdaftar';
        }
        
        // Check for duplicates within import data
        $emailCount = collect($students)->where('email', $student['email'])->count();
        if ($emailCount > 1) {
            $rowErrors[] = 'Email duplikat dalam file import';
        }
        
        if (empty($rowErrors)) {
            $validData[] = $student;
        } else {
            $errors[$index + 1] = $rowErrors; // Row number (1-indexed)
        }
    }
    
    return [
        'valid' => $validData,
        'errors' => $errors,
    ];
}
```

---

## Complete Implementation

### AdminStudentController (Full Example)

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminStudentController extends Controller
{
    /**
     * Import students from CSV/Excel
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx|max:10240', // Max 10MB
        ]);

        try {
            // Parse file
            $students = $this->parseImportFile($request->file('file'));
            
            if (empty($students)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File kosong atau format tidak valid',
                ], 422);
            }

            // Validate data
            $validation = $this->validateForBulkInsert($students);
            
            if (!empty($validation['errors'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Terdapat data yang tidak valid',
                    'errors' => $validation['errors'],
                ], 422);
            }

            $validStudents = $validation['valid'];
            $count = count($validStudents);

            // Choose import method based on count
            if ($count > 5000) {
                $result = $this->bulkInsert($validStudents);
            } else {
                $result = $this->normalInsert($validStudents);
            }

            return response()->json([
                'success' => true,
                'message' => "Berhasil mengimpor {$result['created']} siswa",
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengimpor data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Normal insert for small datasets (<= 5000 records)
     * Triggers model events and observers
     */
    private function normalInsert(array $students): array
    {
        $created = 0;
        $skipped = 0;

        DB::beginTransaction();
        try {
            foreach ($students as $studentData) {
                try {
                   User::create([
                        'school_id' => auth()->user()->school_id,
                        'name' => $studentData['name'],
                        'email' => $studentData['email'],
                        'username' => $studentData['username'],
                        'password' => Hash::make($studentData['password']),
                        'nis' => $studentData['nis'] ?? null,
                        'class_id' => $studentData['class_id'] ?? null,
                        'gender' => $studentData['gender'] ?? 'L',
                        'phone' => $studentData['phone'] ?? null,
                        'address' => $studentData['address'] ?? null,
                        'role_type' => 'student',
                        'is_active' => true,
                    ]);
                    $created++;
                } catch (\Exception $e) {
                    $skipped++;
                    \Log::warning("Skipped student import: " . $e->getMessage());
                }
            }

            DB::commit();

            return [
                'created' => $created,
                'skipped' => $skipped,
                'method' => 'normal',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Bulk insert for large datasets (> 5000 records)
     * Much faster but bypasses model events
     */
    private function bulkInsert(array $students): array
    {
        DB::beginTransaction();
        
        try {
            $now = now();
            $schoolId = auth()->user()->school_id;
            $chunkSize = 1000; // Insert in chunks to avoid memory issues
            $totalCreated = 0;

            // Process in chunks
            $chunks = array_chunk($students, $chunkSize);

            foreach ($chunks as $chunk) {
                $usersData = [];
                
                foreach ($chunk as $student) {
                    $usersData[] = [
                        'school_id' => $schoolId,
                        'name' => $student['name'],
                        'email' => $student['email'],
                        'username' => $student['username'],
                        'password' => Hash::make($student['password']), // Pre-hash!
                        'nis' => $student['nis'] ?? null,
                        'class_id' => $student['class_id'] ?? null,
                        'gender' => $student['gender'] ?? 'L',
                        'phone' => $student['phone'] ?? null,
                        'address' => $student['address'] ?? null,
                        'role_type' => 'student',
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // Bulk insert chunk
                User::insert($usersData);
                $totalCreated += count($usersData);
            }

            DB::commit();

            return [
                'created' => $totalCreated,
                'skipped' => 0,
                'method' => 'bulk',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Parse import file (CSV/Excel)
     */
    private function parseImportFile($file): array
    {
        $extension = $file->getClientOriginalExtension();
        
        if ($extension === 'csv') {
            return $this->parseCSV($file);
        } elseif (in_array($extension, ['xlsx', 'xls'])) {
            return $this->parseExcel($file);
        }

        throw new \Exception('Format file tidak didukung');
    }

    /**
     * Parse CSV file
     */
    private function parseCSV($file): array
    {
        $students = [];
        $handle = fopen($file->getRealPath(), 'r');
        
        // Skip header row
        $header = fgetcsv($handle);
        
        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) continue; //Skip empty rows
            
            $students[] = [
                'name' => $row[0] ?? '',
                'email' => $row[1] ?? '',
                'username' => $row[2] ?? '',
                'password' => $row[3] ?? 'default123',
                'nis' => $row[4] ?? null,
                'class_id' => $row[5] ?? null,
                'gender' => $row[6] ?? 'L',
                'phone' => $row[7] ?? null,
                'address' => $row[8] ?? null,
            ];
        }
        
        fclose($handle);
        return $students;
    }

    /**
     * Parse Excel file (requires maatwebsite/excel)
     */
    private function parseExcel($file): array
    {
        // Implementation using Laravel Excel or PhpSpreadsheet
        // ...
        return [];
    }

    /**
     * Validate students data before bulk insert
     */
    private function validateForBulkInsert(array $students): array
    {
        $errors = [];
        $validData = [];
        $schoolId = auth()->user()->school_id;

        // Get existing emails and NIS in single query
        $existingEmails = User::where('school_id', $schoolId)
            ->whereIn('email', array_column($students, 'email'))
            ->pluck('email')
            ->toArray();

        $existingNIS = User::where('school_id', $schoolId)
            ->whereIn('nis', array_filter(array_column($students, 'nis')))
            ->pluck('nis')
            ->toArray();

        // Track emails/NIS in current import to detect duplicates
        $importEmails = [];
        $importNIS = [];

        foreach ($students as $index => $student) {
            $rowErrors = [];

            // Required field validation
            if (empty($student['name'])) {
                $rowErrors[] = 'Nama wajib diisi';
            }

            if (empty($student['email'])) {
                $rowErrors[] = 'Email wajib diisi';
            } elseif (!filter_var($student['email'], FILTER_VALIDATE_EMAIL)) {
                $rowErrors[] = 'Format email tidak valid';
            } elseif (in_array($student['email'], $existingEmails)) {
                $rowErrors[] = 'Email sudah terdaftar di sistem';
            } elseif (isset($importEmails[$student['email']])) {
                $rowErrors[] = "Email duplikat dengan baris " . $importEmails[$student['email']];
            }

            if (empty($student['username'])) {
                $rowErrors[] = 'Username wajib diisi';
            }

            // NIS validation
            if (!empty($student['nis'])) {
                if (in_array($student['nis'], $existingNIS)) {
                    $rowErrors[] = 'NIS sudah terdaftar di sistem';
                } elseif (isset($importNIS[$student['nis']])) {
                    $rowErrors[] = "NIS duplikat dengan baris " . $importNIS[$student['nis']];
                }
            }

            // Gender validation
            if (!empty($student['gender']) && !in_array($student['gender'], ['L', 'P'])) {
                $rowErrors[] = 'Jenis kelamin harus L atau P';
            }

            if (empty($rowErrors)) {
                $validData[] = $student;
                $importEmails[$student['email']] = $index + 2; // +2 for header and 1-indexed
                if (!empty($student['nis'])) {
                    $importNIS[$student['nis']] = $index + 2;
                }
            } else {
                $errors[$index + 2] = $rowErrors; // Row number in file
            }
        }

        return [
            'valid' => $validData,
            'errors' => $errors,
        ];
    }
}
```

---

## Performance Comparison

| Method | Records | Time | Queries | Memory |
|--------|---------|------|---------|--------|
| Normal Insert | 100 | ~2s | 100 | Low |
| Normal Insert | 1,000 | ~20s | 1,000 | Low |
| Normal Insert | 5,000 | ~100s | 5,000 | Medium |
| **Bulk Insert** | **5,000** | **~3s** | **5** | **Medium** |
| **Bulk Insert** | **10,000** | **~6s** | **10** | **High** |
| **Bulk Insert** | **50,000** | **~30s** | **50** | **High** |

---

## Important Considerations

### 1. Model Events Won't Fire
```php
// With User::create() - events fire
User::creating(function ($user) {
    // This WILL run
});

// With User::insert() - events DON'T fire
User::creating(function ($user) {
    // This WON'T run!
});
```

**Solution:** Manually handle any logic that relies on events:
```php
// After bulk insert, if you need to trigger something:
$insertedUsers = User::whereIn('email', $emails)->get();
foreach ($insertedUsers as $user) {
    event(new UserCreated($user));
}
```

### 2. Timestamps Must Be Set Manually
```php
// ✅ CORRECT
$usersData[] = [
    'created_at' => now(),
    'updated_at' => now(),
    // ... other fields
];
User::insert($usersData);
```

```php
// ❌ WRONG - timestamps will be NULL
$usersData[] = [
    // No timestamps!
];
User::insert($usersData);
```

### 3. Chunk Large Datasets
```php
// For very large datasets, process in chunks
$chunks = array_chunk($students, 1000);

foreach ($chunks as $chunk) {
    $usersData = /* ... prepare chunk ... */;
    User::insert($usersData);
}
```

### 4. Memory Considerations
```php
// For extremely large files (100k+ records), use streaming
$handle = fopen($file, 'r');
$buffer = [];
$bufferSize = 1000;

while (($row = fgetcsv($handle)) !== false) {
    $buffer[] = $this->prepareUserData($row);
    
    if (count($buffer) >= $bufferSize) {
        User::insert($buffer);
        $buffer = []; // Clear buffer
    }
}

if (!empty($buffer)) {
    User::insert($buffer); // Insert remaining
}

fclose($handle);
```

---

## Testing

```php
public function test_bulk_insert_performance()
{
    $students = User::factory()->count(5000)->make()->toArray();
    
    $start = microtime(true);
    $this->bulkInsert($students);
    $elapsed = microtime(true) - $start;
    
    $this->assertLessThan(10, $elapsed); // Should complete in under 10 seconds
    $this->assertEquals(5000, User::count());
}

public function test_passwords_are_hashed_in_bulk_insert()
{
    $students = [
        ['name' => 'Test', 'email' => 'test@example.com', 'password' => 'plain123'],
    ];
    
    $this->bulkInsert($students);
    
    $user = User::where('email', 'test@example.com')->first();
    $this->assertTrue(Hash::check('plain123', $user->password));
    $this->assertNotEquals('plain123', $user->password);
}
```

---

## Recommended Thresholds

| Records | Method | Reason |
|---------|--------|--------|
| < 100 | Normal Insert | Events needed, performance not critical |
| 100 - 5,000 | Normal Insert | Balance between features and speed |
| > 5,000 | **Bulk Insert** | Performance critical |
| > 50,000 | **Chunked Bulk Insert** | Memory optimization |

---

## Implementation Checklist

- [ ] Add bulk insert method to controllers
- [ ] Implement proper validation before bulk insert
- [ ] Hash passwords BEFORE insert
- [ ] Set timestamps manually
- [ ] Handle model events if needed
- [ ] Process in chunks for large datasets
- [ ] Test with large datasets (10k+ records)
- [ ] Monitor memory usage
- [ ] Add logging for debugging
- [ ] Document for team

**Priority:** Medium-High  
**Estimated Time:** 3-4 hours
**Impact:** Significant performance improvement for large imports
