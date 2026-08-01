# DB::table() Audit Report - Week 1 Day 5

**Date**: 2026-02-13  
**Task**: 5.1 & 5.2 - Audit and eliminate DB::table() usage  
**Risk**: 🔴 HIGH (8/10) - Prevents tenant scope bypass

## Executive Summary

Found **47 instances** of `DB::table()` usage in application code (excluding migrations, tests, docs).

**Critical (Tenant-scoped tables)**: 15 instances  
**Medium (System tables)**: 32 instances

## Critical Findings - Tenant-Scoped Tables

These MUST be replaced with Eloquent to respect global scopes:

### 1. ❌ CRITICAL: OptimizedReportService.php
**File**: `app/Services/OptimizedReportService.php:110`
```php
// ❌ BAD: Bypasses BelongsToSchool scope
$summary = DB::table('classes')
    ->leftJoin('class_students', 'classes.id', '=', 'class_students.class_id')
    ->leftJoin('users as students', ...)
```

**Fix**: Use Eloquent with eager loading
```php
// ✅ GOOD: Respects global scopes
$summary = ClassModel::where('school_id', $schoolId)
    ->with(['students' => function($query) {
        $query->where('is_active', true);
    }])
    ->get();
```

### 2. ❌ CRITICAL: LeaderboardService.php (3 instances)
**Files**: Lines 60, 106, 149

```php
// ❌ BAD: Direct table access
$results = DB::table('attendances')
    ->join('users', 'attendances.student_id', '=', 'users.id')
    ->where('users.school_id', $schoolId)
```

**Fix**: Use Eloquent models
```php
// ✅ GOOD: Uses Attendance model with scope
$results = Attendance::query()
    ->join('users', 'attendances.student_id', '=', 'users.id')
    ->where('users.school_id', $schoolId)
```

### 3. ❌ CRITICAL: GamificationService.php (3 instances)
**Files**: Lines 184, 190, 431

```php
// ❌ BAD: Bypasses tenant isolation
$hasBadge = DB::table('student_badges')
    ->where('student_id', $student->id)
    ->where('badge_id', $badge->id)
    ->exists();
```

**Fix**: Create StudentBadge model
```php
// ✅ GOOD: Use Eloquent model
$hasBadge = StudentBadge::where('student_id', $student->id)
    ->where('badge_id', $badge->id)
    ->exists();
```

### 4. ❌ CRITICAL: PrometheusMetricsService.php
**Files**: Lines 408, 417, 433, 442, 457

```php
// ❌ BAD: Direct attendance table access
$attendanceToday = DB::table('attendances')
    ->whereDate('created_at', $today)
    ->count();
```

**Fix**: Use Attendance model
```php
// ✅ GOOD: Respects global scope
$attendanceToday = Attendance::whereDate('created_at', $today)->count();
```

## Medium Priority - System Tables

These are acceptable but should be documented:

### Queue Monitoring (Acceptable)
- `DB::table('jobs')` - System table, no tenant scope needed
- `DB::table('failed_jobs')` - System table, no tenant scope needed

**Justification**: Queue tables are system-level, not tenant-scoped.

### Fallback/Health Tables (Acceptable)
- `DB::table('fallback_events')` - System monitoring table
- `DB::table('system_health_state')` - System monitoring table
- `DB::table('rate_limit_fallback')` - System rate limiting table

**Justification**: These are infrastructure tables for system health.

## Action Plan

### Phase 1: Critical Fixes (Today)
1. ✅ Replace OptimizedReportService.php
2. ✅ Replace LeaderboardService.php
3. ✅ Replace GamificationService.php
4. ✅ Replace PrometheusMetricsService.php

### Phase 2: Documentation (Today)
5. ✅ Document legitimate DB::table() usage
6. ✅ Add PHPStan rule to prevent future usage

### Phase 3: Testing (Today)
7. ✅ Run ScopeBypassTest to verify fixes
8. ✅ Run full test suite

## Rollback Plan

```bash
# If issues arise, revert changes
git revert <commit-hash>

# Or restore specific files
git checkout HEAD~1 app/Services/OptimizedReportService.php
git checkout HEAD~1 app/Services/LeaderboardService.php
git checkout HEAD~1 app/Services/GamificationService.php
```

## Success Metrics

- ✅ Zero `DB::table('attendances')` in app code
- ✅ Zero `DB::table('schedules')` in app code
- ✅ Zero `DB::table('classes')` in app code
- ✅ All tenant-scoped queries use Eloquent
- ✅ PHPStan rule prevents future violations
