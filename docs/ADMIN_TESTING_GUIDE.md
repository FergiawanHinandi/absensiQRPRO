# School Admin Dashboard - Testing Guide

## 🧪 Testing Overview

This guide provides step-by-step instructions for testing all School Admin dashboard features.

## 📋 Prerequisites

### 1. Backend Setup
- Laravel backend running on `http://localhost:8000`
- Database seeded with test data
- At least one `school_admin` user created

### 2. Frontend Setup
```bash
cd frontend-web
npm install
npm run dev
```

### 3. Test Credentials
```
Username: admin@school.com
Password: password123
Role: school_admin
```

## ✅ Feature Testing Checklist

### 1. Dashboard Home (`/admin/dashboard`)

**Test Cases:**
- [ ] Page loads without errors
- [ ] All 5 stat cards display correct data:
  - [ ] Present count
  - [ ] Late count
  - [ ] Alpha count
  - [ ] Attendance rate percentage
  - [ ] High-risk students count
- [ ] Bar chart renders with class attendance data
- [ ] Pie chart shows attendance distribution
- [ ] Date selector changes data when clicked
- [ ] Clicking high-risk card navigates to `/admin/risk-overview`
- [ ] Data auto-refreshes every 60 seconds

**Expected Behavior:**
- Loading spinner appears initially
- Data displays after API response
- Charts are interactive (hover tooltips)
- No console errors

---

### 2. Subject Management (`/admin/subjects`)

**Test Cases:**
- [ ] Subject list displays correctly
- [ ] Search/filter works
- [ ] "Add Subject" button opens form
- [ ] Create new subject:
  - [ ] Fill form with valid data
  - [ ] Submit successfully
  - [ ] New subject appears in list
  - [ ] Toast notification shows success
- [ ] Edit existing subject:
  - [ ] Click edit button
  - [ ] Modify data
  - [ ] Save changes
  - [ ] Changes reflect in list
- [ ] Delete subject:
  - [ ] Click delete button
  - [ ] Confirmation dialog appears
  - [ ] Confirm deletion
  - [ ] Subject removed from list

**Expected Behavior:**
- Form validation works (required fields)
- Error messages display for invalid input
- List updates after CRUD operations
- Confirmation required for deletion

---

### 3. Schedule Management (`/admin/schedules`)

**Test Cases:**
- [ ] Schedule list displays correctly
- [ ] Filter by day of week works
- [ ] Search by class/teacher/subject works
- [ ] "Add Schedule" button opens form
- [ ] Create new schedule:
  - [ ] Select day, time, class, subject, teacher
  - [ ] Submit successfully
  - [ ] New schedule appears in list
- [ ] Edit existing schedule:
  - [ ] Click edit button
  - [ ] Modify time/room
  - [ ] Save changes
- [ ] Delete schedule:
  - [ ] Click delete button
  - [ ] Confirm deletion
  - [ ] Schedule removed

**Expected Behavior:**
- Time picker works correctly
- Dropdown selectors populated with data
- No overlapping schedules allowed
- Active/inactive toggle works

---

### 4. Attendance Settings (`/admin/attendance`)

**Test Cases:**
- [ ] Current settings display correctly
- [ ] Form fields populated with existing values
- [ ] Update check-in start time:
  - [ ] Change time
  - [ ] Save settings
  - [ ] Success notification appears
- [ ] Update check-in end time
- [ ] Update late threshold (minutes)
- [ ] Update QR validity (minutes)
- [ ] Preview section shows updated values
- [ ] Cancel button resets form

**Expected Behavior:**
- Time inputs work correctly
- Number inputs validate range (0-60 for threshold)
- Settings persist after save
- Preview updates in real-time

---

### 5. Student Card Management (`/admin/student-cards`)

**Test Cases:**
- [ ] Progress dashboard displays:
  - [ ] Total students count
  - [ ] Active cards count
  - [ ] Pending cards count
  - [ ] Not generated count
- [ ] Pie chart shows distribution
- [ ] Per-class progress table displays
- [ ] Progress bars show correct percentages
- [ ] Bulk generation:
  - [ ] Select a class
  - [ ] Click "Generate Kartu Kelas"
  - [ ] Confirmation dialog appears
  - [ ] Confirm action
  - [ ] Success notification shows
  - [ ] Progress updates

**Expected Behavior:**
- Statistics are accurate
- Chart is interactive
- Bulk generation processes all students
- Progress updates after generation

---

### 6. Photo Review (`/admin/photo-review`)

**Test Cases:**
- [ ] Pending photos display in grid
- [ ] Photo count shows correctly
- [ ] Each photo card shows:
  - [ ] Student photo
  - [ ] Student name
  - [ ] Username
  - [ ] Class name
  - [ ] Upload date
- [ ] Approve photo:
  - [ ] Click "Setujui" button
  - [ ] Confirmation dialog appears
  - [ ] Confirm action
  - [ ] Photo removed from pending list
  - [ ] Success notification shows
- [ ] Reject photo:
  - [ ] Click "Tolak" button
  - [ ] Reason input appears
  - [ ] Enter reason (optional)
  - [ ] Confirm rejection
  - [ ] Photo removed from list
- [ ] Empty state shows when no pending photos

**Expected Behavior:**
- Photos load correctly
- Grid is responsive
- Actions update list immediately
- Guidelines are visible

---

### 7. Reports & Export (`/admin/reports`)

**Test Cases:**
- [ ] Daily report preview displays:
  - [ ] Total students
  - [ ] Present count
  - [ ] Late count
  - [ ] Alpha count
  - [ ] Attendance rate with progress bar
- [ ] Monthly report preview displays (when month selected)
- [ ] Export PDF:
  - [ ] Select report type (daily/monthly)
  - [ ] Select date/month
  - [ ] Select class (optional)
  - [ ] Click "Export PDF"
  - [ ] File downloads successfully
  - [ ] PDF opens correctly
- [ ] Export Excel:
  - [ ] Same steps as PDF
  - [ ] Excel file downloads
  - [ ] Excel opens with correct data
- [ ] Class filter works
- [ ] Date/month selector updates preview

**Expected Behavior:**
- Previews update when date changes
- Export buttons show loading state
- Files download with correct names
- Export includes filtered data

---

### 8. Notification & Risk Logs (`/admin/notifications`)

**Test Cases:**
- [ ] Notification logs display in timeline
- [ ] Each notification shows:
  - [ ] Title
  - [ ] Message
  - [ ] Type (with color coding)
  - [ ] Recipient info
  - [ ] Status
  - [ ] Timestamp
- [ ] Risk status changes display:
  - [ ] Student name
  - [ ] Old status
  - [ ] New status
  - [ ] Change description
  - [ ] Timestamp
- [ ] Date filter works (7/14/30 days)
- [ ] Pagination:
  - [ ] "Previous" button works
  - [ ] "Next" button works
  - [ ] Page number displays correctly
  - [ ] Buttons disabled at boundaries
- [ ] Empty states show when no data

**Expected Behavior:**
- Logs sorted by date (newest first)
- Color coding matches notification type
- Pagination loads new data
- Filter updates displayed logs

---

## 🔍 Cross-Feature Testing

### Navigation
- [ ] Sidebar links work correctly
- [ ] Breadcrumbs show current location
- [ ] Back button works
- [ ] Direct URL access works

### Authentication
- [ ] Login required for all pages
- [ ] Logout works correctly
- [ ] Session persists on refresh
- [ ] Unauthorized roles redirected

### Responsive Design
- [ ] Desktop (1920x1080) - All features work
- [ ] Laptop (1366x768) - Layout adapts
- [ ] Tablet (768x1024) - Mobile-friendly
- [ ] Mobile (375x667) - Touch-friendly

### Performance
- [ ] Dashboard loads in <2 seconds
- [ ] Large tables render smoothly
- [ ] Export completes in reasonable time
- [ ] No memory leaks on navigation
- [ ] Images load efficiently

### Error Handling
- [ ] Network error shows message
- [ ] API error shows toast
- [ ] Retry button works
- [ ] Form validation errors display
- [ ] 404 page shows for invalid routes

---

## 🐛 Bug Reporting Template

When you find a bug, report it with this format:

```markdown
**Page/Feature**: [e.g., Student Card Management]
**Route**: [e.g., /admin/student-cards]
**User Role**: [e.g., school_admin]

**Steps to Reproduce**:
1. Navigate to...
2. Click on...
3. Enter...
4. Observe...

**Expected Behavior**:
[What should happen]

**Actual Behavior**:
[What actually happened]

**Screenshots**:
[Attach if applicable]

**Console Errors**:
[Copy any errors from browser console]

**Environment**:
- Browser: [e.g., Chrome 120]
- OS: [e.g., Windows 11]
- Screen Size: [e.g., 1920x1080]
```

---

## ✅ Testing Sign-Off

### Tester Information
- **Name**: _______________
- **Date**: _______________
- **Environment**: _______________

### Results Summary
- **Total Test Cases**: _____ / _____
- **Passed**: _____
- **Failed**: _____
- **Blocked**: _____

### Critical Issues Found
1. _____________________________
2. _____________________________
3. _____________________________

### Recommendation
- [ ] **PASS** - Ready for production
- [ ] **CONDITIONAL PASS** - Minor issues, can deploy with fixes
- [ ] **FAIL** - Critical issues, cannot deploy

### Notes
_____________________________
_____________________________
_____________________________

---

**Last Updated**: February 2026  
**Version**: 1.0.0  
**Status**: Ready for Testing
