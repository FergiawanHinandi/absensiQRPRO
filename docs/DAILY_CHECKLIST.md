# 📋 DAILY CHECKLIST - 15 Day Sprint
## Print & Tempel di Meja Kerja Anda!

---

## WEEK 1: BACKEND CRITICAL FIXES

### ☐ DAY 1: N+1 Query Audit
- [ ] Install Laravel Debugbar: `composer require barryvdh/laravel-debugbar --dev`
- [ ] Test dashboard: Count queries (should be 50+)
- [ ] Document slow endpoints in `docs/n1_audit_results.md`
- [ ] **Deliverable**: List of top 5 worst endpoints

### ☐ DAY 2: Fix N+1 Queries
- [ ] Add eager loading to `DashboardController::index()`
- [ ] Add eager loading to `AdminDashboardController::index()`
- [ ] Test: Query count reduced from 50+ to <10
- [ ] Test: Dashboard loads in <1 second
- [ ] **Deliverable**: Dashboard performance improved

### ☐ DAY 3: Database Indexes
- [ ] Create migration: `add_performance_indexes_to_attendances_table`
- [ ] Add indexes to `attendances`, `schedules`, `users` tables
- [ ] Run migration: `php artisan migrate`
- [ ] Test: Queries 10-100x faster
- [ ] **Deliverable**: All indexes applied

### ☐ DAY 4: API Caching
- [ ] Install Redis (or use Docker)
- [ ] Create `CacheResponse` middleware
- [ ] Apply to dashboard routes (TTL: 5 min)
- [ ] Test: Second request 10-20x faster
- [ ] **Deliverable**: Cache hit rate >80%

### ☐ DAY 5: Rate Limiting
- [ ] Configure rate limits in `bootstrap/app.php`
- [ ] Apply to login (5/min), QR scan (10/min), global (60/min)
- [ ] Test: 6th login attempt returns 429
- [ ] **Deliverable**: Rate limiting active

---

## WEEK 2: FRONTEND OPTIMIZATION

### ☐ DAY 6: Dashboard Charts
- [ ] Install recharts: `npm install recharts`
- [ ] Create `AttendanceChart` component
- [ ] Add to Teacher Dashboard
- [ ] Test: Charts render correctly
- [ ] **Deliverable**: Visual dashboard with charts

### ☐ DAY 7: Real-time Updates
- [ ] Install Laravel Echo: `npm install laravel-echo pusher-js`
- [ ] Configure Echo in `src/services/echo.ts`
- [ ] Subscribe to `teacher.{id}` channel
- [ ] Test: Scan QR → Dashboard updates live
- [ ] **Deliverable**: Real-time dashboard

### ☐ DAY 8: Error Boundaries
- [ ] Create `ErrorBoundary` component
- [ ] Wrap app in `src/main.tsx`
- [ ] Create `LoadingSpinner` and `SkeletonLoader`
- [ ] Test: Throw error → Boundary catches it
- [ ] **Deliverable**: Graceful error handling

### ☐ DAY 9: Mobile Memory Leaks
- [ ] Fix interval cleanup in `useEffect`
- [ ] Fix event listener cleanup
- [ ] Replace `Image` with `FastImage`
- [ ] Test: Monitor memory usage
- [ ] **Deliverable**: No memory leaks

### ☐ DAY 10: Navigation Performance
- [ ] Lazy load screens with `React.lazy()`
- [ ] Add `Suspense` with loading fallback
- [ ] Build release APK
- [ ] Test: Smooth navigation
- [ ] **Deliverable**: Optimized navigation

---

## WEEK 3: MOBILE & FINAL POLISH

### ☐ DAY 11: Offline Queue
- [ ] Install AsyncStorage
- [ ] Create `offlineQueue` service
- [ ] Test: Save attendance offline
- [ ] **Deliverable**: Offline queue working

### ☐ DAY 12: Sync Service
- [ ] Create `syncService` with auto-sync
- [ ] Test: Turn off WiFi → Scan → Turn on WiFi → Syncs
- [ ] **Deliverable**: Offline sync working

### ☐ DAY 13: Biometric Login
- [ ] Install `react-native-biometrics`
- [ ] Create `biometricAuth` service
- [ ] Add to Login Screen
- [ ] Test: Fingerprint login works
- [ ] **Deliverable**: Biometric auth enabled

### ☐ DAY 14: TypeScript Strict
- [ ] Enable strict mode in `tsconfig.json`
- [ ] Fix all type errors
- [ ] Run `npm run type-check`
- [ ] **Deliverable**: 0 TypeScript errors

### ☐ DAY 15: Final Testing
- [ ] Backend tests: `php artisan test` (all pass)
- [ ] Frontend tests: `npm run test` (all pass)
- [ ] Build production: `npm run build`
- [ ] Deploy to staging
- [ ] **Deliverable**: Production ready!

---

## 🚨 EMERGENCY CONTACTS

**If Stuck**:
1. Check `docs/15_DAY_PRODUCTION_SPRINT.md` for detailed steps
2. Google error message
3. Ask in Laravel/React Discord
4. Stack Overflow

**Rollback Commands**:
```bash
# Backend
git reset --hard HEAD~1
php artisan migrate:rollback

# Frontend
git checkout HEAD~1 -- frontend-web/
npm install && npm run build
```

---

## 📊 PROGRESS TRACKER

**Week 1**: [ ] [ ] [ ] [ ] [ ]  
**Week 2**: [ ] [ ] [ ] [ ] [ ]  
**Week 3**: [ ] [ ] [ ] [ ] [ ]

**Overall**: _____ / 15 days completed

---

## 🎯 SUCCESS CRITERIA

- [ ] Dashboard loads in <1 second
- [ ] API queries reduced from 50+ to <10
- [ ] Mobile app doesn't crash
- [ ] All tests passing
- [ ] 0 TypeScript errors
- [ ] Production deployed

---

**Motivasi**: "You got this! 💪 Satu hari satu task, 15 hari jadi production ready!"

**Mulai**: _____ / _____ / _____  
**Target Selesai**: _____ / _____ / _____
