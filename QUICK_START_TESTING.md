# 🚀 QUICK START TESTING GUIDE

## 📋 **TESTING CHECKLIST**

### **1. Backend API Testing**

#### **Start Backend Server**
```bash
cd backend
php artisan serve --port=8000
```

#### **Test Login API**
```bash
# PowerShell
Invoke-WebRequest -Uri "http://localhost:8000/api/v1/auth/login" -Method POST -Headers @{"Content-Type"="application/json"} -Body '{"username":"superadmin","password":"password123"}' -UseBasicParsing

# Expected Response: 200 OK with access_token
```

#### **Test Health Check**
```bash
Invoke-WebRequest -Uri "http://localhost:8000/api/v1/health" -UseBasicParsing

# Expected Response: 200 OK with system status
```

### **2. Frontend Testing**

#### **Start Frontend Server**
```bash
cd frontend-web
npm run dev
```

#### **Test Login Flow**
1. Open browser: `http://localhost:5173/login`
2. Enter credentials:
   - Username: `superadmin`
   - Password: `password123`
3. Click "Masuk"
4. Should redirect to: `http://localhost:5173/super-admin/dashboard`

#### **Test Error Boundary**
1. Open browser console
2. Navigate to any page
3. Trigger error (if any)
4. Should show friendly error page instead of white screen

### **3. Database Testing**

#### **Check Migrations**
```bash
cd backend
php artisan migrate:status

# All migrations should be "Ran"
```

#### **Test Database Connection**
```bash
php artisan tinker
DB::connection()->getPdo();
# Should return PDO object without errors
```

### **4. Performance Testing**

#### **Test Daily Report Performance**
```bash
# Time the request
Measure-Command { Invoke-WebRequest -Uri "http://localhost:8000/api/v1/attendance/daily-report?date=2026-01-31" -Headers @{"Authorization"="Bearer YOUR_TOKEN"} -UseBasicParsing }

# Should complete in < 1 second
```

#### **Test Student List Performance**
```bash
# Time the request
Measure-Command { Invoke-WebRequest -Uri "http://localhost:8000/api/v1/admin/students?per_page=50" -Headers @{"Authorization"="Bearer YOUR_TOKEN"} -UseBasicParsing }

# Should complete in < 2 seconds
```

### **5. Security Testing**

#### **Test Rate Limiting**
```bash
# Try login 6 times with wrong password
for ($i=1; $i -le 6; $i++) {
    Invoke-WebRequest -Uri "http://localhost:8000/api/v1/auth/login" -Method POST -Headers @{"Content-Type"="application/json"} -Body '{"username":"superadmin","password":"wrong"}' -UseBasicParsing
}

# 6th request should return 429 Too Many Requests
```

#### **Test HMAC Verification**
```bash
# Try webhook without signature
Invoke-WebRequest -Uri "http://localhost:8000/api/v1/webhooks/midtrans" -Method POST -Headers @{"Content-Type"="application/json"} -Body '{"order_id":"test"}' -UseBasicParsing

# Should return 401 Unauthorized
```

### **6. Mobile App Testing**

#### **Check React Native Setup**
```bash
cd AbsensiQRMobile
npm install
npx react-native doctor

# Should show all checks passed
```

#### **Test Security Utils**
```bash
# Check if security utils are properly implemented
cat src/utils/securityUtils.ts

# Should contain proper jail-monkey implementation
```

---

## 🎯 **EXPECTED RESULTS**

### **✅ PASS Criteria**
- Backend API responds in < 1 second
- Frontend loads without console errors
- Login flow works end-to-end
- Database queries optimized
- Security measures active
- Error boundaries working

### **❌ FAIL Criteria**
- Any 500 server errors
- Frontend white screen
- Login redirect fails
- Database connection errors
- Security bypasses possible
- Unhandled exceptions

---

## 🔧 **TROUBLESHOOTING**

### **Backend Issues**
```bash
# Check logs
tail -f backend/storage/logs/laravel.log

# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

### **Frontend Issues**
```bash
# Check console for errors
# Clear browser cache
# Restart dev server
npm run dev
```

### **Database Issues**
```bash
# Reset database
php artisan migrate:fresh --seed

# Check connection
php artisan tinker
DB::select('SELECT 1');
```

---

## 📊 **PERFORMANCE BENCHMARKS**

### **Target Performance**
- Login API: < 800ms
- Daily Report: < 200ms
- Student List: < 500ms
- Page Load: < 1.2s
- Memory Usage: < 100MB

### **Security Benchmarks**
- Rate Limiting: Active
- HMAC Verification: Working
- SQL Injection: Protected
- XSS Protection: Active
- CSRF Protection: Enabled

---

*Testing Guide - Updated: 31 Januari 2026*