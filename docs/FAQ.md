# Frequently Asked Questions (FAQ)

**Last Updated:** January 28, 2026  
**Version:** 1.0.0

---

## 📋 Table of Contents

1. [General Questions](#general-questions)
2. [Security Questions](#security-questions)
3. [Implementation Questions](#implementation-questions)
4. [Testing Questions](#testing-questions)
5. [Deployment Questions](#deployment-questions)
6. [Troubleshooting](#troubleshooting)
7. [Performance Questions](#performance-questions)

---

## 🎯 General Questions

### Q: What security features were implemented?

**A:** 5 critical security pillars:
1. **Multi-Tenant Security** - 3-layer defense-in-depth protection
2. **Hybrid QR Validation** - Fast & secure QR code validation
3. **Backend-Only Security** - Never trust the client principle
4. **Secure Mobile Tokens** - OS-level encryption (Keychain/Keystore)
5. **Advanced Rate Limiting** - 6-layer protection against attacks

**Total:** 37 files, 59 tests, 12 documentation files

---

### Q: Do I need to implement all 5 pillars?

**A:** **YES!** All 5 pillars work together for comprehensive security:
- Pillar 1-3: Backend security (CRITICAL)
- Pillar 4: Mobile security (CRITICAL)
- Pillar 5: Attack prevention (CRITICAL)

Skipping any pillar leaves significant security gaps.

---

### Q: Is this backward compatible?

**A:** **Mostly YES:**
- ✅ Backend: Fully backward compatible
- ✅ Web Frontend: No changes needed
- ⚠️ Mobile: Users need to re-login once (tokens migrated to Keychain/Keystore)

---

### Q: How long does implementation take?

**A:** 
- **Reading docs:** 2-3 hours
- **Running tests:** 30 minutes
- **Backend deployment:** 1-2 hours
- **Mobile deployment:** 2-3 hours
- **Total:** 1 day for experienced developers

---

## 🔒 Security Questions

### Q: Why is multi-tenant security important?

**A:** Without proper multi-tenant security:
- ❌ Admin from School A can access School B data
- ❌ Global scope can be bypassed with `withoutGlobalScope()`
- ❌ No "last line of defense"

**With 3-layer protection:**
- ✅ Global Scope (automatic filtering)
- ✅ Policy (CRITICAL - cannot be bypassed)
- ✅ Controller Authorization (explicit checks)

---

### Q: Why hybrid QR validation instead of just HMAC?

**A:** **HMAC-only problems:**
- ❌ Inactive students can still scan
- ❌ Transferred students can use old QR
- ❌ No audit trail
- ❌ Cannot revoke specific QR codes

**Hybrid solution:**
- ✅ Layer 1: HMAC (fast, 0 DB queries)
- ✅ Layer 2: Database check (secure, 1 query)
- ✅ Performance: Only +25ms (50ms → 75ms)
- ✅ Security: LOW → HIGH

---

### Q: Why can't I trust the frontend for security?

**A:** **Frontend is NOT secure because:**
- ❌ JavaScript can be modified by users
- ❌ Route guards can be bypassed
- ❌ API calls can be made directly (curl/Postman)
- ❌ Client-side validation can be disabled

**Backend is secure because:**
- ✅ Server-side code cannot be modified
- ✅ All requests go through middleware
- ✅ Policies enforce authorization
- ✅ Database queries are filtered

**Golden Rule:** Frontend = UX, Backend = Security

---

### Q: Why use Keychain/Keystore instead of AsyncStorage?

**A:** **AsyncStorage problems:**
- ❌ Plain text file (anyone can read)
- ❌ No encryption
- ❌ Vulnerable on rooted/jailbroken devices
- ❌ Can be extracted easily

**Keychain/Keystore benefits:**
- ✅ Hardware-backed encryption
- ✅ OS-level protection
- ✅ Cannot be extracted (even on rooted devices)
- ✅ Automatic encryption/decryption

---

### Q: What attacks does rate limiting prevent?

**A:** Rate limiting prevents:
1. **Brute Force Attacks** - Login: 5 attempts per 5 minutes
2. **DDoS Attacks** - Global: 1000 requests/min per IP
3. **Spam Attacks** - QR Scan: 10 scans/min per user+device
4. **API Abuse** - API: 60 requests/min per user

**Impact:**
- Brute force: 10 seconds → 16.7 hours (6000x slower!)
- DDoS: Server protected from overwhelming traffic
- Spam: Cannot game attendance system

---

## 🛠️ Implementation Questions

### Q: Do I need to update all controllers?

**A:** **Recommended but not required immediately:**
- ✅ New code: Always use `$this->authorize()`
- ⚠️ Existing code: Policies work automatically with global scope
- 📋 TODO: Add `$this->authorize()` to all controllers gradually

**Priority:**
1. High-risk endpoints (admin, reports)
2. Data modification endpoints (create, update, delete)
3. Read-only endpoints (index, show)

---

### Q: How do I add a new policy?

**A:** 
```bash
# 1. Generate policy
php artisan make:policy NewResourcePolicy --model=NewResource

# 2. Implement methods
class NewResourcePolicy {
    public function view(User $user, NewResource $resource) {
        return $user->school_id === $resource->school_id;
    }
}

# 3. Register in AuthServiceProvider
protected $policies = [
    NewResource::class => NewResourcePolicy::class,
];

# 4. Use in controller
$this->authorize('view', $newResource);

# 5. Write tests
php artisan make:test NewResourcePolicyTest
```

---

### Q: How do I customize rate limits?

**A:**
```php
// Option 1: Environment variables (.env)
RATE_LIMIT_GLOBAL=1000
RATE_LIMIT_LOGIN=5
RATE_LIMIT_SCAN=10

// Option 2: Middleware code
// Edit: app/Http/Middleware/AdvancedRateLimiting.php
protected function getMaxAttempts(string $type): int {
    return match ($type) {
        'login' => env('RATE_LIMIT_LOGIN', 5),
        // ...
    };
}
```

---

### Q: Can I disable rate limiting for testing?

**A:** **YES, but only in testing environment:**

```php
// tests/TestCase.php
protected function setUp(): void {
    parent::setUp();
    
    // Disable rate limiting in tests
    $this->withoutMiddleware(\App\Http\Middleware\AdvancedRateLimiting::class);
}
```

**⚠️ NEVER disable in production!**

---

## 🧪 Testing Questions

### Q: Why are there 59 tests?

**A:** Comprehensive coverage of all security scenarios:
- 16 tests: Multi-tenant security
- 13 tests: QR validation
- 17 tests: Backend security
- 13 tests: Rate limiting

**Each test verifies:**
- ✅ Security feature works correctly
- ✅ Attack scenarios are blocked
- ✅ Edge cases are handled

---

### Q: How long do tests take to run?

**A:**
- **All 59 tests:** ~30-60 seconds
- **Individual suite:** ~5-15 seconds
- **Single test:** <1 second

**Tip:** Run specific suites during development:
```bash
php artisan test --filter=PolicyEnforcementTest
```

---

### Q: What if a test fails?

**A:** **DO NOT PROCEED with deployment!**

**Debugging steps:**
1. Read error message carefully
2. Check test file for expected behavior
3. Run with verbose output: `php artisan test -v`
4. Clear caches: `php artisan config:clear`
5. Check database state
6. Review recent code changes

**Common causes:**
- Database not migrated
- Cache issues
- Environment configuration
- Code logic errors

---

### Q: How do I test mobile token storage?

**A:** **Manual verification:**

```typescript
// 1. Login
await AuthService.login('username', 'password');

// 2. Check logs (should see):
// ✅ "Tokens stored securely"
// ❌ Should NOT see actual token values

// 3. Close app completely
// 4. Reopen app
// 5. Should still be logged in ✅

// 6. Logout
await AuthService.logout();

// 7. Check logs (should see):
// ✅ "All data cleared"

// 8. Try to access protected endpoint
// Should fail with 401 ✅
```

---

## 🚀 Deployment Questions

### Q: Can I deploy to staging first?

**A:** **YES, HIGHLY RECOMMENDED!**

**Deployment flow:**
1. Deploy to staging
2. Run all tests
3. Monitor for 24-48 hours
4. Fix any issues
5. Deploy to production

**Benefits:**
- Catch issues before production
- Test with production-like data
- Verify performance
- Train team

---

### Q: Will users need to re-login after mobile update?

**A:** **YES, one-time re-login required.**

**Reason:** Tokens migrated from AsyncStorage to Keychain/Keystore

**User experience:**
1. Update app from store
2. Open app
3. Redirected to login
4. Login once
5. Tokens now secure ✅

**Communication:**
- Notify users before update
- Explain it's for security
- Provide support for issues

---

### Q: What if deployment fails?

**A:** **Follow rollback procedure:**

```bash
# 1. Switch to previous version
git checkout previous-tag

# 2. Rollback migrations (if needed)
php artisan migrate:rollback --step=1

# 3. Clear caches
php artisan config:clear && php artisan cache:clear

# 4. Reinstall dependencies
composer install --no-dev

# 5. Recache
php artisan config:cache && php artisan route:cache

# 6. Restart services
sudo systemctl restart php8.2-fpm nginx
```

**See:** `docs/implementation/DEPLOYMENT_GUIDE.md` for details

---

### Q: How do I verify deployment was successful?

**A:** **Post-deployment checklist:**

```bash
# 1. Health check
curl https://api.school.com/api/v1/health
# Expected: {"status":"ok"}

# 2. Login test
curl -X POST https://api.school.com/api/v1/auth/login \
  -d '{"username":"test","password":"password"}'
# Expected: {"success":true,"token":"..."}

# 3. Rate limiting test
for i in {1..6}; do
  curl -X POST https://api.school.com/api/v1/auth/login \
    -d '{"username":"admin","password":"wrong"}'
done
# Expected: First 5 return 401, 6th returns 429

# 4. Check logs
tail -f storage/logs/laravel.log
# Expected: No errors

# 5. Mobile app test
# - Login works
# - QR scanning works
# - Logout works
```

---

## 🆘 Troubleshooting

### Q: Tests fail with "Class not found"

**A:**
```bash
# Solution
composer dump-autoload
php artisan config:clear
php artisan cache:clear

# Then run tests again
php artisan test
```

---

### Q: Rate limiting not working

**A:**
```bash
# Check Redis connection
redis-cli ping
# Expected: PONG

# If Redis not running
sudo systemctl start redis

# Check cache driver in .env
CACHE_DRIVER=redis

# Clear config
php artisan config:clear

# Verify middleware registered
php artisan route:list | grep rate.limit
```

---

### Q: Mobile tokens not persisting

**A:**
```bash
# Check keychain installation
npm list react-native-keychain
# Expected: react-native-keychain@8.2.0

# If not installed
npm install react-native-keychain

# iOS
cd ios && pod install && cd ..
npm run ios

# Android
cd android && ./gradlew clean && cd ..
npm run android

# Check logs for errors
# Should see "Tokens stored securely"
# Should NOT see actual token values
```

---

### Q: 500 Server Error after deployment

**A:**
```bash
# Check logs
tail -f storage/logs/laravel.log

# Common causes & solutions:

# 1. Permission issues
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# 2. Missing .env
cp .env.example .env
php artisan key:generate

# 3. Database connection
# Check .env database credentials
php artisan migrate

# 4. Cache issues
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

---

### Q: Cross-school access still possible

**A:** **Check implementation:**

```bash
# 1. Verify policies registered
php artisan tinker
Gate::policies();
# Should show all policies

# 2. Test policy
$admin = User::where('role_type', 'school_admin')->first();
$otherStudent = User::where('school_id', '!=', $admin->school_id)->first();
Gate::forUser($admin)->allows('view', $otherStudent);
// Expected: false

# 3. Check controller uses authorize
// Search for: $this->authorize()

# 4. Run tests
php artisan test --filter=CrossTenantAccessTest
```

---

### Q: QR scanning too slow

**A:** **Performance optimization:**

```bash
# 1. Check current performance
grep "Attendance recorded" storage/logs/laravel.log | tail -20
# Target: ~75ms

# 2. Optimize database
# Add indexes if needed
php artisan migrate

# 3. Enable query caching
# In .env:
CACHE_DRIVER=redis

# 4. Check Redis performance
redis-cli --latency
# Should be <1ms

# 5. Profile slow queries
# Enable slow query log in MySQL
```

---

## ⚡ Performance Questions

### Q: What's the performance impact?

**A:**
- **Multi-Tenant:** No impact (policy checks only when needed)
- **QR Validation:** +25ms (50ms → 75ms) - acceptable
- **Backend Security:** No impact (middleware already exists)
- **Mobile Tokens:** No impact (OS-level, instant)
- **Rate Limiting:** ~1-2ms per request - negligible

**Overall:** Minimal impact, massive security gain

---

### Q: How can I improve performance?

**A:**
1. **Use Redis for cache & rate limiting**
   ```env
   CACHE_DRIVER=redis
   ```

2. **Enable query caching**
   ```php
   User::remember(60)->where('role_type', 'student')->get();
   ```

3. **Add database indexes**
   ```php
   $table->index(['school_id', 'role_type']);
   ```

4. **Use eager loading**
   ```php
   User::with('school', 'classes')->get();
   ```

5. **Cache expensive operations**
   ```php
   Cache::remember('dashboard_stats', 300, function() {
       return calculateStats();
   });
   ```

---

### Q: What are acceptable response times?

**A:**
| Endpoint | Target | Warning | Critical |
|----------|--------|---------|----------|
| QR Scan | <100ms | 100-200ms | >200ms |
| API Calls | <50ms | 50-100ms | >100ms |
| Reports | <500ms | 500-1000ms | >1000ms |
| Dashboard | <200ms | 200-500ms | >500ms |

---

## 📚 Additional Questions

### Q: Where can I find more information?

**A:**
- **Main Docs:** `docs/README.md`
- **Security Overview:** `docs/security/00-OVERVIEW.md`
- **Testing Guide:** `docs/implementation/TESTING_GUIDE.md`
- **Deployment Guide:** `docs/implementation/DEPLOYMENT_GUIDE.md`
- **Monitoring Guide:** `docs/implementation/MONITORING_GUIDE.md`
- **Quick Reference:** `docs/QUICK_REFERENCE.md`

---

### Q: How do I get support?

**A:**
1. **Check documentation** - Most questions answered there
2. **Review test files** - Examples of correct usage
3. **Check logs** - Error messages provide clues
4. **Run tests** - Verify implementation
5. **Contact team** - If still stuck

---

### Q: Can I contribute improvements?

**A:** **YES!** Contributions welcome:
1. Fix bugs
2. Improve documentation
3. Add tests
4. Optimize performance
5. Enhance security

**Process:**
1. Create branch
2. Make changes
3. Write tests
4. Update docs
5. Submit PR

---

## 🎉 Still Have Questions?

**Not answered here?**
1. Check main documentation: `docs/README.md`
2. Review implementation guides
3. Search test files for examples
4. Contact development team

---

**Last Updated:** January 28, 2026  
**Maintained by:** Development Team  
**For Updates:** Check `docs/README.md`

**Happy Coding! 🚀**
