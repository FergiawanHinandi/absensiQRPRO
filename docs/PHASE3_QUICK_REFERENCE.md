# Phase 3 Quick Reference

## Week 7: Optimization

### N+1 Query Fixes Checklist
- [ ] TeacherDashboardController::getAlerts()
- [ ] TeacherDashboardController::getCorrectionList()
- [ ] TeacherDashboardController::getMonthlyAnalytics()
- [ ] AdminDashboardController::getStudentRiskList()
- [ ] PrincipalDashboardController::getSchoolOverview()
- [ ] PrincipalDashboardController::getTeacherPerformance()
- [ ] PrincipalDashboardController::getClassPerformance()
- [ ] (11 more methods...)

### Quick Optimization Patterns

```php
// Eager Loading
Model::with(['relation'])->get();

// Subquery
Model::select([
    'id',
    DB::raw('(SELECT COUNT(*) FROM ...) as count')
])->get();

// Cache
Cache::remember("key", 300, fn() => expensive_query());
```

## Week 8: Testing

### Test Files to Create
```
tests/
├── Unit/ (40 files)
├── Feature/ (60 files)
├── Integration/ (10 files)
└── E2E/ (10 files)
```

### Quick Test Commands
```bash
# Run all tests
php artisan test

# With coverage
php artisan test --coverage --min=90

# Specific suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Parallel
php artisan test --parallel
```

## Success Metrics
- ✅ 0 N+1 queries
- ✅ Cache hit > 80%
- ✅ Coverage > 90%
- ✅ Load time < 3s
- ✅ 0 regressions
