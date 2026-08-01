# Quick Start Guide - Query Plan Verification

**Task 13.4**: Verify query plans with EXPLAIN

---

## TL;DR - Run This Now

```bash
cd backend
php artisan benchmark:queries --iterations=10
```

That's it! The command will:
- ✅ Test all 7 critical queries
- ✅ Verify index usage with EXPLAIN
- ✅ Benchmark performance
- ✅ Show color-coded results

---

## What Gets Tested

### P0 Critical (Must Pass)
1. **QR Code Validation** - Target: < 5ms
2. **Subscription Check** - Target: < 10ms

### P1 High Priority (Should Pass)
3. **Student History** - Target: < 20ms
4. **Daily Report** - Target: < 30ms

### P2 Medium Priority (Nice to Pass)
5. **Teacher Schedule** - Target: < 20ms
6. **Users by Role** - Target: < 20ms
7. **Class Students** - Target: < 15ms

---

## Reading the Results

### ✅ Good Result
```
🔍 Testing: QR Code Validation
  ✅ Uses Index: YES
  📌 Index Name: idx_qr_codes_validation
  ✅ Expected Index: MATCH
  ⏱️  Avg: 2.45ms ✅
```
**Meaning**: Index is working perfectly!

---

### ⚠️ Warning Result
```
🔍 Testing: Student History
  ✅ Uses Index: YES
  📌 Index Name: idx_attendance_student_history_sorted
  ⏱️  Avg: 75ms ⚠️
```
**Meaning**: Index is used but performance could be better. Acceptable for now.

---

### ❌ Failed Result
```
🔍 Testing: Daily Report
  ❌ Uses Index: NO (Full table scan)
  🔍 Scan Type: ALL
  ⏱️  Avg: 250ms ❌
```
**Meaning**: Index not being used! Needs investigation.

---

## Quick Fixes

### If Index Not Used

```bash
# 1. Check if migration ran
php artisan migrate:status

# 2. Run migration if needed
php artisan migrate

# 3. Verify index exists
php artisan tinker
>>> DB::select("SHOW INDEX FROM attendances");
```

### If Performance Poor

```bash
# Update database statistics
php artisan tinker
>>> DB::statement("ANALYZE TABLE attendances");
>>> DB::statement("ANALYZE TABLE users");
>>> DB::statement("ANALYZE TABLE schedules");
```

---

## Advanced Usage

### More Iterations (More Accurate)
```bash
php artisan benchmark:queries --iterations=50
```

### Save Results to File
```bash
php artisan benchmark:queries > benchmark_results.txt
```

### Compare Before/After
```bash
# Before migration
php artisan benchmark:queries > before.txt

# After migration
php artisan migrate
php artisan benchmark:queries > after.txt

# Compare
diff before.txt after.txt
```

---

## Alternative: Run Analysis Script

```bash
cd backend
php artisan tinker < database/analysis/verify_query_plans.php
```

Same tests, different format. Use whichever you prefer!

---

## What Success Looks Like

```
════════════════════════════════════════════════════════════════
  VERIFICATION SUMMARY
════════════════════════════════════════════════════════════════

📊 Total Tests: 7
✅ Passed: 7
❌ Failed: 0
⚠️  Skipped: 0

✅ All tested indexes are working correctly!
```

---

## Need Help?

See full documentation:
- `QUERY_PLAN_VERIFICATION.md` - Complete guide
- `TASK_13.4_COMPLETION.md` - Task completion report

Or ask the team! 🚀

---

**Quick Reference**:
- ✅ = < 50ms (Excellent)
- ⚠️ = 50-100ms (Acceptable)  
- ❌ = > 100ms (Needs work)

