import { useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { useAuthStore } from './modules/auth/stores/useAuthStore';
import { LoginPage } from './modules/auth/pages/LoginPage';
// Remove AuthDebug - no longer needed

import TeacherDashboard from './pages/Teacher/TeacherDashboard';
import ParentDashboard from './pages/Parent/ParentDashboard';

// Placeholder Pages
import DashboardLayout from './components/layout/DashboardLayout';
import { SuperAdminLayout } from './components/layout/SuperAdminLayout';
import PlaceholderPage from './pages/PlaceholderPage';

// Placeholder Pages (AdminDashboard is real, others are placeholders for now if not imported)
import AdminDashboard from './pages/Admin/AdminDashboard';
import AdminAnomalies from './pages/Admin/AdminAnomalies';
import AdminClassAttendance from './pages/Admin/AdminClassAttendance';
import AdminTeacherAbsent from './pages/Admin/AdminTeacherAbsent';
import AdminLateAlpha from './pages/Admin/AdminLateAlpha';
import AdminTeachers from './pages/Admin/AdminTeachers';
import AdminStudents from './pages/Admin/AdminStudents';
import AdminClasses from './pages/Admin/AdminClasses';
import AdminSubjects from './pages/Admin/AdminSubjects';
import AdminSchedules from './pages/Admin/AdminSchedules';
import AdminParents from './pages/Admin/AdminParents';
import AdminReports from './pages/Admin/AdminReports';
import AdminSchoolSettings from './pages/Admin/AdminSchoolSettings';
import AdminAttendanceSettings from './pages/Admin/AdminAttendanceSettings';
import SecurityMonitoring from './pages/Admin/SecurityMonitoring';
import RiskOverview from './pages/Admin/RiskOverview';
import { AdminAccountGenerator } from './pages/Admin/AdminAccountGenerator';
import { SuperAdminDashboard } from './pages/SuperAdmin/SuperAdminDashboard';
import { NewDashboard } from './pages/SuperAdmin/NewDashboard';
import { SchoolsManagement } from './pages/SuperAdmin/SchoolsManagement';
import { SchoolActivation } from './pages/SuperAdmin/SchoolActivation';
import { PricingPage } from './modules/billing/pages/PricingPage';
import PrincipalDashboard from './pages/Principal/PrincipalDashboard';
import { PackageLimits } from './pages/SuperAdmin/PackageLimits';
import { AdminSchoolManagement } from './pages/SuperAdmin/AdminSchoolManagement';
import { SubscriptionPackages } from './pages/SuperAdmin/SubscriptionPackages';
import { PaymentHistory } from './pages/SuperAdmin/PaymentHistory';
import { InvoiceManagement } from './pages/SuperAdmin/InvoiceManagement';
import { ActivityLogs } from './pages/SuperAdmin/ActivityLogs';
import { ResetAccess } from './pages/SuperAdmin/ResetAccess';
import { RolePermission } from './pages/SuperAdmin/RolePermission';
import { AuditLog } from './pages/SuperAdmin/AuditLog';
import { RateLimit } from './pages/SuperAdmin/RateLimit';
import { FeatureFlags } from './pages/SuperAdmin/FeatureFlags';
import { GlobalAttendanceRecap } from './pages/SuperAdmin/Reports/GlobalAttendanceRecap';
import { PlatformStatistics } from './pages/SuperAdmin/Reports/PlatformStatistics';
import { GlobalExportResults } from './pages/SuperAdmin/Reports/GlobalExportResults';
import { HomeroomDailyAttendance } from './pages/Teacher/Homeroom/HomeroomDailyAttendance';
import { HomeroomPermissions } from './pages/Teacher/Homeroom/HomeroomPermissions';
import { AnnouncementsManagement } from './pages/SuperAdmin/AnnouncementsManagement';
import { BackupDatabase } from './pages/SuperAdmin/System/BackupDatabase';
import { MaintenanceMode } from './pages/SuperAdmin/System/MaintenanceMode';
import TeacherHeatmapPage from './modules/admin/pages/TeacherHeatmapPage';
import AdminStudentCards from './pages/Admin/AdminStudentCards';
import AdminPhotoReview from './pages/Admin/AdminPhotoReview';
import AdminNotificationLogs from './pages/Admin/AdminNotificationLogs';
import AdminAttendanceQrMode from './pages/Admin/AdminAttendanceQrMode';
import AdminAttendanceOverride from './pages/Admin/AdminAttendanceOverride';
import AdminAttendanceTolerance from './pages/Admin/AdminAttendanceTolerance';

const adminPlaceholderRoutes: { path: string; title: string }[] = [
  // Placeholder khusus fitur admin yang belum diimplementasikan.
];

const getDashboardPath = (role: string) => {
  switch (role) {
    case 'super_admin': return '/super-admin/dashboard';
    case 'admin':
    case 'school_admin': return '/admin/dashboard';
    case 'principal': return '/principal/dashboard';
    case 'teacher':
    case 'homeroom_teacher': return '/teacher/dashboard';
    case 'student': return '/student/dashboard';
    case 'parent': return '/parent/dashboard';
    default: return '/login';
  }
};

const ProtectedRoute = ({ children, allowedRoles }: { children: React.ReactNode, allowedRoles: string[] }) => {
  const { user, isAuthenticated, isLoading } = useAuthStore();

  if (isLoading) return <div className="flex h-screen items-center justify-center">Loading...</div>;

  if (!isAuthenticated) return <Navigate to="/login" replace />;

  if (user && !allowedRoles.includes(user.role_type)) {
    // Jika role tidak sesuai, redirect ke dashboard mereka sendiri
    return <Navigate to={getDashboardPath(user.role_type)} replace />;
  }

  return children;
};

import { AppErrorBoundary } from './components/common/AppErrorBoundary';

function App() {
  const checkAuth = useAuthStore((state) => state.checkAuth);

  useEffect(() => {
    checkAuth();
  }, [checkAuth]);

  return (
    <AppErrorBoundary>
      <BrowserRouter>
        <Toaster />
        <Routes>
          <Route path="/login" element={<LoginPage />} />

          {/* New Super Admin Dashboard (Modern V2) */}
          <Route path="/super-admin/dashboard-v2" element={
            <ProtectedRoute allowedRoles={['super_admin']}>
              <NewDashboard />
            </ProtectedRoute>
          } />

          {/* Protected Dashboard Routes */}
          <Route element={<DashboardLayout />}>

            {/* PRINCIPAL */}
            <Route path="/principal">
              <Route path="dashboard" element={
                <ProtectedRoute allowedRoles={['principal']}>
                  <PrincipalDashboard />
                </ProtectedRoute>
              } />
              <Route path="monitoring" element={
                <ProtectedRoute allowedRoles={['principal']}>
                  <PlaceholderPage title="Monitoring Absensi" />
                </ProtectedRoute>
              } />
              <Route path="reports" element={
                <ProtectedRoute allowedRoles={['principal']}>
                  <PlaceholderPage title="Laporan Sekolah" />
                </ProtectedRoute>
              } />
              <Route path="approvals" element={
                <ProtectedRoute allowedRoles={['principal']}>
                  <PlaceholderPage title="Approval" />
                </ProtectedRoute>
              } />
              <Route path="*" element={
                <ProtectedRoute allowedRoles={['principal']}>
                  <PlaceholderPage title="Fitur Kepala Sekolah" />
                </ProtectedRoute>
              } />
            </Route>

            {/* TEACHER */}
            <Route path="/teacher">
              <Route path="dashboard" element={
                <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                  <TeacherDashboard />
                </ProtectedRoute>
              } />
              <Route path="*" element={
                <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                  <PlaceholderPage title="Fitur Guru" />
                </ProtectedRoute>
              } />
            </Route>

            {/* ADMIN */}
            <Route path="/admin">
              <Route path="dashboard" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminDashboard />
                </ProtectedRoute>
              } />
              <Route path="dashboard/class-attendance" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminClassAttendance />
                </ProtectedRoute>
              } />
              <Route path="dashboard/teacher-absent" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminTeacherAbsent />
                </ProtectedRoute>
              } />
              <Route path="dashboard/late-absent" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminLateAlpha />
                </ProtectedRoute>
              } />
              <Route path="dashboard/anomalies" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminAnomalies />
                </ProtectedRoute>
              } />
              <Route path="risk-overview" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin', 'principal']}>
                  <RiskOverview />
                </ProtectedRoute>
              } />
              <Route path="security-monitoring" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin', 'super_admin']}>
                  <SecurityMonitoring />
                </ProtectedRoute>
              } />
              <Route path="teacher-heatmap" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin', 'super_admin']}>
                  <TeacherHeatmapPage />
                </ProtectedRoute>
              } />
              <Route path="teachers/*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminTeachers />
                </ProtectedRoute>
              } />
              <Route path="students/*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminStudents />
                </ProtectedRoute>
              } />
              <Route path="classes/*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminClasses />
                </ProtectedRoute>
              } />
              <Route path="subjects/*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminSubjects />
                </ProtectedRoute>
              } />
              <Route path="schedules/*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminSchedules />
                </ProtectedRoute>
              } />
              <Route path="attendance/qr-mode" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminAttendanceQrMode />
                </ProtectedRoute>
              } />
              <Route path="attendance/override" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminAttendanceOverride />
                </ProtectedRoute>
              } />
              <Route path="attendance/tolerance" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminAttendanceTolerance />
                </ProtectedRoute>
              } />
              <Route path="attendance/*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminAttendanceSettings />
                </ProtectedRoute>
              } />
              <Route path="parents/*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminParents />
                </ProtectedRoute>
              } />
              <Route path="reports/*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminReports />
                </ProtectedRoute>
              } />
              <Route path="settings/*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminSchoolSettings />
                </ProtectedRoute>
              } />
              <Route path="accounts/generate" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminAccountGenerator />
                </ProtectedRoute>
              } />
              <Route path="billing/pricing" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <PricingPage />
                </ProtectedRoute>
              } />
              <Route path="student-cards" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminStudentCards />
                </ProtectedRoute>
              } />
              <Route path="photo-review" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminPhotoReview />
                </ProtectedRoute>
              } />
              <Route path="notifications" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <AdminNotificationLogs />
                </ProtectedRoute>
              } />
              {adminPlaceholderRoutes.map((route) => (
                <Route
                  key={route.path}
                  path={route.path}
                  element={
                    <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                      <PlaceholderPage title={route.title} />
                    </ProtectedRoute>
                  }
                />
              ))}
              <Route path="*" element={
                <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                  <PlaceholderPage title="Fitur Admin" />
                </ProtectedRoute>
              } />
            </Route>

            {/* STUDENT */}
            <Route path="/student">
              <Route path="dashboard" element={
                <ProtectedRoute allowedRoles={['student']}>
                  <PlaceholderPage title="Dashboard Siswa" />
                </ProtectedRoute>
              } />
              <Route path="history" element={
                <ProtectedRoute allowedRoles={['student']}>
                  <PlaceholderPage title="Riwayat Absensi" />
                </ProtectedRoute>
              } />
              <Route path="schedule" element={
                <ProtectedRoute allowedRoles={['student']}>
                  <PlaceholderPage title="Jadwal Saya" />
                </ProtectedRoute>
              } />
              <Route path="profile" element={
                <ProtectedRoute allowedRoles={['student']}>
                  <PlaceholderPage title="Profil Siswa" />
                </ProtectedRoute>
              } />
              <Route path="*" element={
                <ProtectedRoute allowedRoles={['student']}>
                  <PlaceholderPage title="Fitur Siswa" />
                </ProtectedRoute>
              } />
            </Route>

            {/* PARENT */}
            <Route path="/parent">
              <Route path="dashboard" element={
                <ProtectedRoute allowedRoles={['parent']}>
                  <ParentDashboard />
                </ProtectedRoute>
              } />
              <Route path="children-history" element={
                <ProtectedRoute allowedRoles={['parent']}>
                  <PlaceholderPage title="Riwayat Anak" />
                </ProtectedRoute>
              } />
              <Route path="permissions" element={
                <ProtectedRoute allowedRoles={['parent']}>
                  <PlaceholderPage title="Izin / Sakit" />
                </ProtectedRoute>
              } />
              <Route path="*" element={<Navigate to="dashboard" replace />} />
            </Route>


            {/* TEACHER & HOMEROOM */}
            <Route path="/teacher">
              <Route path="dashboard" element={
                <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                  <TeacherDashboard />
                </ProtectedRoute>
              } />
              <Route path="permissions" element={
                <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                  <HomeroomPermissions />
                </ProtectedRoute>
              } />
              {/* Homeroom Specific */}
              <Route path="class-attendance/daily" element={
                <ProtectedRoute allowedRoles={['homeroom_teacher']}>
                  <HomeroomDailyAttendance />
                </ProtectedRoute>
              } />
              {/* Common Teacher Pages */}
              <Route path="schedules" element={
                <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                  <PlaceholderPage title="Jadwal Mengajar" />
                </ProtectedRoute>
              } />
              <Route path="attendance/*" element={
                <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                  <PlaceholderPage title="Absensi Mapel" />
                </ProtectedRoute>
              } />
              <Route path="reports/*" element={
                <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                  <PlaceholderPage title="Laporan Guru" />
                </ProtectedRoute>
              } />
              <Route path="profile/*" element={
                <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                  <PlaceholderPage title="Profil Guru" />
                </ProtectedRoute>
              } />
            </Route>

            {/* SUPER ADMIN */}
          </Route>

          {/* SUPER ADMIN - Standalone Layout (Dark Theme) */}
          <Route element={<SuperAdminLayout />}>
            <Route path="/super-admin/dashboard" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <SuperAdminDashboard />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/schools" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <SchoolsManagement />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/schools/activation" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <SchoolActivation />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/schools/packages" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <PackageLimits />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/users/admins" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <AdminSchoolManagement />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/billing/packages" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <SubscriptionPackages />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/billing/payment-history" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <PaymentHistory />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/billing/invoices" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <InvoiceManagement />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/security/roles" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <RolePermission />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/security/audit" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <AuditLog />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/security/rate-limit" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <RateLimit />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/users/activity-logs" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <ActivityLogs />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/users/reset-access" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <ResetAccess />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/config/features" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <FeatureFlags />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/reports/attendance" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <GlobalAttendanceRecap />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/reports/statistics" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <PlatformStatistics />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/reports/export" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <GlobalExportResults />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/announcements" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <AnnouncementsManagement />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/system/backup" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <BackupDatabase />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/system/maintenance" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <MaintenanceMode />
              </ProtectedRoute>
            } />
            <Route path="/super-admin/*" element={
              <ProtectedRoute allowedRoles={['super_admin']}>
                <PlaceholderPage title="Fitur Super Admin" />
              </ProtectedRoute>
            } />
          </Route>

          <Route path="/" element={
            <RootRedirect />
          } />
        </Routes>
      </BrowserRouter>
    </AppErrorBoundary>
  );
}

const RootRedirect = () => {
  const { user, isAuthenticated, isLoading } = useAuthStore();
  if (isLoading) return <div className="flex h-screen items-center justify-center">Loading...</div>;
  if (isAuthenticated && user) {
    return <Navigate to={getDashboardPath(user.role_type)} replace />;
  }
  return <Navigate to="/login" replace />;
};

export default App;
