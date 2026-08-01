# 🔥 N+1 QUERY PREVENTION - QUICK REFERENCE

## ❌ NEVER DO THIS

```php
// ❌ Query in loop
$students = Student::all();
foreach ($students as $student) {
    echo $student->class->name; // N queries!
}

// ❌ Accessing relationship without ->with()
$students = Student::where('is_active', true)->get();
return view('students', compact('students')); 
// Then in blade: {{ $student->class->name }} causes N+1!

// ❌ Loading full models when you only need counts
foreach ($students as $student) {
    $count = $student->attendances->count(); // Loads ALL attendances!
}
```

---

## ✅ ALWAYS DO THIS

```php
// ✅ Eager load relationships
$students = Student::with('class')->get();
foreach ($students as $student) {
    echo $student->class->name; // No extra query!
}

// ✅ Multiple relationships
$students = Student::with(['class', 'school', 'profile'])->get();

// ✅ Nested relationships
$attendances = Attendance::with([
    'student',
    'schedule.class',
    'schedule.subject',
    'schedule.teacher'
])->get();

// ✅ Select specific columns
$students = Student::with([
    'class:id,name',
    'school:id,name'
])->get();

// ✅ Use withCount for counting
$students = Student::withCount('attendances')->get();
echo $student->attendances_count; // No query!

// ✅ Batch load before loop
$studentIds = $students->pluck('id');
$attendances = Attendance::whereIn('student_id', $studentIds)
    ->get()
    ->groupBy('student_id');

foreach ($students as $student) {
    $studentAttendances = $attendances->get($student->id, collect());
}
```

---

## 📋 MANDATORY EAGER LOADS

### Attendance Model
```php
Attendance::with([
    'student:id,name,nis',
    'schedule.class:id,name',
    'schedule.subject:id,name',
    'schedule.teacher:id,name'
])
```

### Student (User)
```php
User::where('role_type', 'student')
    ->with(['class:id,name', 'school:id,name'])
```

### Teacher (User)
```php
User::where('role_type', 'teacher')
    ->with([
        'school:id,name',
        'teacherSubjects.subject',
        'teacherRoles.class'
    ])
```

### Schedule
```php
Schedule::with([
    'class:id,name',
    'subject:id,name',
    'teacher:id,name',
    'academicYear:id,name'
])
```

---

## 🧪 QUICK TEST

```php
// Enable query log
DB::enableQueryLog();

// Your code here
$students = Student::with('class')->get();

// Check queries
$queries = DB::getQueryLog();
dd(count($queries)); // Should be LOW (usually 2-3)

// ✅ Good: 2-3 queries for any number of records
// ❌ Bad: Queries increase with number of records
```

---

## 🚀 INSTALL DEBUGBAR

```bash
composer require barryvdh/laravel-debugbar --dev
```

Then check the "Queries" tab in the bottom toolbar.

**Goal:** Query count should NOT increase when data increases!

---

## ⚡ PERFORMANCE TARGETS

| Records | Max Queries | Status |
|---------|-------------|--------|
| 10 | 5 | ✅ |
| 100 | 5 | ✅ |
| 1000 | 5 | ✅ |
| 10000 | 5 | ✅ |

**If queries increase with records = N+1 problem! Fix it!**

---

## 🔧 QUICK FIXES

### Problem: Accessing class in loop
```php
// ❌ Before
$students = Student::all();

// ✅ After
$students = Student::with('class')->get();
```

### Problem: Nested relationships
```php
// ❌ Before
$attendances = Attendance::with('schedule')->get();
// Then: $attendance->schedule->class->name (N+1!)

// ✅ After
$attendances = Attendance::with('schedule.class')->get();
```

### Problem: Query in map/loop
```php
// ❌ Before
$students->map(function($s) {
    $count = Attendance::where('student_id', $s->id)->count();
});

// ✅ After
$studentIds = $students->pluck('id');
$counts = Attendance::whereIn('student_id', $studentIds)
    ->selectRaw('student_id, count(*) as total')
    ->groupBy('student_id')
    ->pluck('total', 'student_id');

$students->map(function($s) use ($counts) {
    $count = $counts->get($s->id, 0);
});
```

---

## 📚 LEARN MORE

- Full Guide: `backend/N+1_QUERY_FIX_COMPLETE.md`
- Code Examples: `backend/app/Examples/N1QueryPreventionExamples.php`
- Laravel Docs: https://laravel.com/docs/eloquent-relationships#eager-loading

---

**Remember:** If in doubt, use `DB::enableQueryLog()` and check!
