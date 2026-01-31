# 🚀 ACTION PLAN - IMPLEMENTASI MODUL DASHBOARD

## 📊 **RINGKASAN SITUASI**

**Status Saat Ini**: 45/156 modul implemented (29%)
**Target**: 95% completion dalam 10 minggu
**Prioritas**: Critical modules first untuk operasional dasar

---

## 🎯 **FASE 1: CRITICAL MODULES (Minggu 1-3)**

### **Week 1: School Admin Core (8 modul)**

#### **Day 1-2: Jadwal & Mapel**
1. **Jadwal Pelajaran** - `/admin/schedules`
   - Backend: ScheduleController, ScheduleService
   - Frontend: AdminSchedules.tsx (sudah ada, perlu content)
   - Features: CRUD jadwal, assign guru-mapel-kelas

2. **Daftar Mapel** - `/admin/subjects`
   - Backend: SubjectController, SubjectService
   - Frontend: AdminSubjects.tsx (sudah ada, perlu content)
   - Features: CRUD mata pelajaran, kode mapel

#### **Day 3-4: Assignment & Placement**
3. **Assign Kelas & Mapel** - `/admin/teachers/assignments`
   - Backend: TeacherAssignmentController
   - Frontend: TeacherAssignments.tsx (baru)
   - Features: Assign guru ke kelas dan mapel

4. **Penempatan Kelas** - `/admin/students/placement`
   - Backend: StudentPlacementController
   - Frontend: StudentPlacement.tsx (baru)
   - Features: Assign siswa ke kelas, mutasi kelas

#### **Day 5: Attendance Settings**
5. **Pengaturan Jam Absensi** - `/admin/attendance/settings`
   - Backend: AttendanceSettingsController
   - Frontend: AdminAttendanceSettings.tsx (sudah ada, perlu content)
   - Features: Set jam masuk/pulang, toleransi

### **Week 2: School Admin Reports & Settings (3 modul)**

#### **Day 1-2: Reports**
6. **Rekap Bulanan** - `/admin/reports/monthly`
   - Backend: MonthlyReportController
   - Frontend: MonthlyReport.tsx (baru)
   - Features: Generate laporan bulanan, filter

7. **Export PDF/Excel** - `/admin/reports/export`
   - Backend: ReportExportController (enhance existing)
   - Frontend: ReportExport.tsx (baru)
   - Features: Export berbagai format

#### **Day 3: School Profile**
8. **Profil Sekolah** - `/admin/settings/profile`
   - Backend: SchoolProfileController
   - Frontend: SchoolProfile.tsx (sudah ada, perlu content)
   - Features: Edit profil sekolah, logo, info

### **Week 3: Teacher Core (4 modul)**

#### **Day 1-2: Schedule & QR**
1. **Jadwal Mengajar** - `/teacher/schedules`
   - Backend: TeacherScheduleController
   - Frontend: TeacherSchedules.tsx (baru)
   - Features: View jadwal mengajar, status

2. **Generate QR** - `/teacher/attendance/qr`
   - Backend: QRGeneratorController (enhance existing)
   - Frontend: QRGenerator.tsx (baru)
   - Features: Generate QR per sesi, timer

#### **Day 3-4: Manual & Profile**
3. **Validasi Manual** - `/teacher/attendance/manual`
   - Backend: ManualAttendanceController
   - Frontend: ManualAttendance.tsx (baru)
   - Features: Input manual attendance

4. **Data Pribadi** - `/teacher/profile/personal`
   - Backend: TeacherProfileController
   - Frontend: TeacherProfile.tsx (baru)
   - Features: Edit profil guru, foto

---

## 🎯 **FASE 2: HIGH PRIORITY MODULES (Minggu 4-7)**

### **Week 4: Student Dashboard (3 modul)**

#### **Day 1-3: Student Core**
1. **Dashboard** - `/student/dashboard`
   - Backend: StudentDashboardController (enhance existing)
   - Frontend: StudentDashboard.tsx (baru)
   - Features: Attendance summary, schedule today

2. **Riwayat Absensi** - `/student/history`
   - Backend: StudentHistoryController
   - Frontend: StudentHistory.tsx (baru)
   - Features: View attendance history, stats

3. **Jadwal Saya** - `/student/schedule`
   - Backend: StudentScheduleController
   - Frontend: StudentSchedule.tsx (baru)
   - Features: View personal schedule

### **Week 5: School Admin Advanced (6 modul)**

#### **Day 1-2: Teacher Management**
1. **Guru Kelas** - `/admin/teachers/homeroom`
   - Backend: HomeroomTeacherController
   - Frontend: HomeroomTeachers.tsx (baru)
   - Features: Assign wali kelas

2. **Mapel ↔ Guru** - `/admin/subjects/teacher-mapping`
   - Backend: SubjectTeacherMappingController
   - Frontend: SubjectTeacherMapping.tsx (baru)
   - Features: Map subjects to teachers

#### **Day 3-4: Student & Parent**
3. **Kartu Pelajar & QR** - `/admin/students/cards`
   - Backend: StudentCardController
   - Frontend: StudentCards.tsx (baru)
   - Features: Generate student ID cards

4. **Akun Orang Tua** - `/admin/parents`
   - Backend: ParentAccountController
   - Frontend: AdminParents.tsx (sudah ada, perlu content)
   - Features: Manage parent accounts

#### **Day 5: Schedule & Reports**
5. **Jam Masuk/Pulang** - `/admin/schedules/timing`
   - Backend: ScheduleTimingController
   - Frontend: ScheduleTiming.tsx (baru)
   - Features: Set school hours

6. **Absensi per Kelas** - `/admin/reports/class`
   - Backend: ClassReportController
   - Frontend: ClassReport.tsx (baru)
   - Features: Class attendance reports

### **Week 6: Parent & Principal (4 modul)**

#### **Day 1-2: Parent Portal**
1. **Riwayat Anak** - `/parent/children-history`
   - Backend: ParentChildHistoryController
   - Frontend: ChildrenHistory.tsx (baru)
   - Features: View children attendance

2. **Izin / Sakit** - `/parent/permissions`
   - Backend: ParentPermissionController
   - Frontend: ParentPermissions.tsx (baru)
   - Features: Submit permission requests

#### **Day 3-4: Principal Dashboard**
3. **Monitoring Absensi** - `/principal/monitoring`
   - Backend: PrincipalMonitoringController
   - Frontend: PrincipalMonitoring.tsx (baru)
   - Features: School-wide monitoring

4. **Laporan Sekolah** - `/principal/reports`
   - Backend: PrincipalReportController
   - Frontend: PrincipalReports.tsx (baru)
   - Features: Executive reports

### **Week 7: Teacher Advanced (4 modul)**

#### **Day 1-2: Attendance & Reports**
1. **Daftar Hadir** - `/teacher/attendance/list`
   - Backend: AttendanceListController
   - Frontend: AttendanceList.tsx (baru)
   - Features: View attendance lists

2. **Rekap Absensi** - `/teacher/reports/attendance`
   - Backend: TeacherAttendanceReportController
   - Frontend: TeacherAttendanceReport.tsx (baru)
   - Features: Teacher's attendance reports

#### **Day 3-4: Profile & Notes**
3. **Ganti Password** - `/teacher/profile/password`
   - Backend: PasswordChangeController
   - Frontend: ChangePassword.tsx (baru)
   - Features: Change password form

4. **Catatan Kehadiran** - `/teacher/class-attendance/notes`
   - Backend: AttendanceNotesController
   - Frontend: AttendanceNotes.tsx (baru)
   - Features: Add notes to attendance

---

## 🎯 **FASE 3: MEDIUM PRIORITY MODULES (Minggu 8-10)**

### **Week 8-10: Remaining Modules**
- School Admin: Settings, Billing, Advanced Reports
- Teacher: Advanced Features, History
- Super Admin: System Management, Global Reports
- Principal: Approvals, Advanced Monitoring

---

## 🛠️ **DEVELOPMENT TEMPLATE**

### **Backend Template**
```php
<?php
namespace App\Http\Controllers\Api\V1\[Role];

use App\Http\Controllers\Controller;
use App\Http\Requests\[Role]\[Module]Request;
use App\Services\[Module]Service;
use Illuminate\Http\JsonResponse;

class [Module]Controller extends Controller
{
    protected [Module]Service $service;

    public function __construct([Module]Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->service->getAll($request->user()->school_id);
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store([Module]Request $request): JsonResponse
    {
        $data = $this->service->create($request->validated());
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(int $id): JsonResponse
    {
        $data = $this->service->getById($id);
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function update([Module]Request $request, int $id): JsonResponse
    {
        $data = $this->service->update($id, $request->validated());
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);
        return response()->json(['success' => true, 'message' => 'Deleted successfully']);
    }
}
```

### **Frontend Template**
```tsx
import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/Card';
import { apiClient } from '../../lib/api';

interface [Module]Data {
    id: number;
    name: string;
    // Add other fields
}

const [Module]Page: React.FC = () => {
    const [data, setData] = useState<[Module]Data[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/admin/[module-endpoint]');
            setData(response.data.data);
        } catch (err: any) {
            setError(err.response?.data?.message || 'Failed to load data');
        } finally {
            setLoading(false);
        }
    };

    if (loading) {
        return <div className="flex justify-center items-center h-64">Loading...</div>;
    }

    if (error) {
        return <div className="text-red-600">{error}</div>;
    }

    return (
        <div className="space-y-6">
            <div className="flex justify-between items-center">
                <h1 className="text-2xl font-bold">[Module Title]</h1>
                <button className="bg-blue-600 text-white px-4 py-2 rounded">
                    Add New
                </button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>[Module] List</CardTitle>
                </CardHeader>
                <CardContent>
                    {/* Add your content here */}
                </CardContent>
            </Card>
        </div>
    );
};

export default [Module]Page;
```

---

## 📋 **DAILY CHECKLIST**

### **Setiap Modul Baru:**
- [ ] Backend Controller & Service
- [ ] Frontend Component
- [ ] API Routes
- [ ] Frontend Routes
- [ ] Form Validation
- [ ] Error Handling
- [ ] Authorization Check
- [ ] Basic Testing
- [ ] Documentation Update

### **End of Week:**
- [ ] Integration Testing
- [ ] UI/UX Review
- [ ] Performance Check
- [ ] Security Audit
- [ ] Documentation Complete

---

## 🎯 **SUCCESS METRICS**

### **Week 1-3 Target:**
- 15 critical modules implemented
- Core functionality working
- Basic CRUD operations complete

### **Week 4-7 Target:**
- 35 total modules implemented
- Advanced features working
- User workflows complete

### **Week 8-10 Target:**
- 90%+ modules implemented
- System fully functional
- Production ready

---

## 🚨 **RISK MITIGATION**

### **Technical Risks:**
- **Database Performance**: Add indexes for new queries
- **API Rate Limiting**: Implement proper throttling
- **Frontend Performance**: Lazy loading, pagination
- **Security**: Proper authorization on all endpoints

### **Timeline Risks:**
- **Scope Creep**: Stick to defined features
- **Quality Issues**: Daily code reviews
- **Integration Problems**: Continuous testing
- **Resource Constraints**: Prioritize critical features

---

*Action Plan Created: 31 Januari 2026*
*Target Completion: 10 April 2026*
*Success Rate Target: 95%*