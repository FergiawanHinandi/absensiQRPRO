import React, { Suspense, useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { useAuthStore } from './modules/auth/stores/useAuthStore';
import { AppErrorBoundary } from './components/common/AppErrorBoundary';
import DashboardLayout from './components/layout/DashboardLayout';
import { SuperAdminLayout } from './components/layout/SuperAdminLayout';
import PlaceholderPage from './pages/PlaceholderPage';

// Auth - tetap statik (dipakai semua user)
import { LoginPage } from './modules/auth/pages/LoginPage';
import RegisterPage from './pages/Auth/RegisterPage';
import ForgotPasswordPage from './pages/Auth/ForgotPasswordPage';

// Role-specific Login Pages
import { RoleSelectorPage } from './modules/auth/pages/RoleSelectorPage';
import { SuperAdminLoginPage } from './modules/auth/pages/SuperAdminLoginPage';
import { AdminLoginPage } from './modules/auth/pages/AdminLoginPage';
import { TeacherLoginPage } from './modules/auth/pages/TeacherLoginPage';
import { StudentLoginPage } from './modules/auth/pages/StudentLoginPage';
import { ParentLoginPage } from './modules/auth/pages/ParentLoginPage';

// ============================================
// LAZY IMPORTS - TEACHER PAGES
// ============================================
const TeacherDashboard = React.lazy(() => import('./pages/Teacher/TeacherDashboard'));
const AttendanceQR = React.lazy(() => import('./pages/Teacher/AttendanceQR'));
const ManualAttendancePage = React.lazy(() => import('./pages/Teacher/ManualAttendancePage'));
const TeacherSchedulePage = React.lazy(() => import('./pages/Teacher/TeacherSchedulePage'));
const TeacherAttendanceList = React.lazy(() => import('./pages/Teacher/TeacherAttendanceList'));
const TeacherPersonalReport = React.lazy(() => import('./pages/Teacher/TeacherPersonalReport'));
const TeacherSessionHistory = React.lazy(() => import('./pages/Teacher/TeacherSessionHistory'));
const HomeroomReport = React.lazy(() => import('./pages/Teacher/HomeroomReport'));
const HomeroomDailyAttendance = React.lazy(() =>
  import('./pages/Teacher/Homeroom/HomeroomDailyAttendance').then(m => ({ default: m.HomeroomDailyAttendance }))
);
const HomeroomPermissions = React.lazy(() =>
  import('./pages/Teacher/Homeroom/HomeroomPermissions').then(m => ({ default: m.HomeroomPermissions }))
);
const TeacherProfilePage = React.lazy(() => import('./modules/teacher/pages/TeacherProfilePage'));
const TeacherClassListPage = React.lazy(() => import('./modules/teacher/pages/TeacherClassListPage'));
const TeacherPasswordChange = React.lazy(() => import('./pages/Teacher/TeacherPasswordChange'));
const TeacherLoginHistory = React.lazy(() => import('./pages/Teacher/TeacherLoginHistory'));
const HomeroomAttendanceNotes = React.lazy(() => import('./pages/Teacher/Homeroom/HomeroomAttendanceNotes'));
const HomeroomClassRecap = React.lazy(() => import('./pages/Teacher/Homeroom/HomeroomClassRecap'));

// ============================================
// LAZY IMPORTS - ADMIN PAGES
// ============================================
const AdminDashboard = React.lazy(() => import('./pages/Admin/AdminDashboard'));
const AdminAnomalies = React.lazy(() => import('./pages/Admin/AdminAnomalies'));
const AdminClassAttendance = React.lazy(() => import('./pages/Admin/AdminClassAttendance'));
const AdminTeacherAbsent = React.lazy(() => import('./pages/Admin/AdminTeacherAbsent'));
const AdminLateAlpha = React.lazy(() => import('./pages/Admin/AdminLateAlpha'));
const AdminTeachers = React.lazy(() => import('./pages/Admin/AdminTeachers'));
const AdminStudents = React.lazy(() => import('./pages/Admin/AdminStudents'));
const AdminClasses = React.lazy(() => import('./pages/Admin/AdminClasses'));
const AdminSubjects = React.lazy(() => import('./pages/Admin/AdminSubjects'));
const AdminSchedules = React.lazy(() => import('./pages/Admin/AdminSchedules'));
const AdminParents = React.lazy(() => import('./pages/Admin/AdminParents'));
const AdminReports = React.lazy(() => import('./pages/Admin/AdminReports'));
const AdminSchoolSettings = React.lazy(() => import('./pages/Admin/AdminSchoolSettings'));
const AdminAttendanceSettings = React.lazy(() => import('./pages/Admin/AdminAttendanceSettings'));
const SecurityMonitoring = React.lazy(() => import('./pages/Admin/SecurityMonitoring'));
const RiskOverview = React.lazy(() => import('./pages/Admin/RiskOverview'));
const AdminAccountGenerator = React.lazy(() =>
  import('./pages/Admin/AdminAccountGenerator').then(m => ({ default: m.AdminAccountGenerator }))
);
const SchoolProfile = React.lazy(() => import('./pages/Admin/SchoolProfile'));
const ActiveAcademicYear = React.lazy(() => import('./pages/Admin/ActiveAcademicYear'));
const TeacherHeatmapPage = React.lazy(() => import('./modules/admin/pages/TeacherHeatmapPage'));
const AdminStudentCards = React.lazy(() => import('./pages/Admin/AdminStudentCards'));
const AdminPhotoReview = React.lazy(() => import('./pages/Admin/AdminPhotoReview'));
const AdminNotificationLogs = React.lazy(() => import('./pages/Admin/AdminNotificationLogs'));
const AdminAttendanceQrMode = React.lazy(() => import('./pages/Admin/AdminAttendanceQrMode'));
const AdminAttendanceOverride = React.lazy(() => import('./pages/Admin/AdminAttendanceOverride'));
const AdminAttendanceTolerance = React.lazy(() => import('./pages/Admin/AdminAttendanceTolerance'));
const AdminBillingHistory = React.lazy(() => import('./pages/Admin/AdminBillingHistory'));
const PricingPage = React.lazy(() =>
  import('./modules/billing/pages/PricingPage').then(m => ({ default: m.PricingPage }))
);

// ============================================
// LAZY IMPORTS - STUDENT PAGES
// ============================================
const StudentDashboard = React.lazy(() => import('./pages/Student/StudentDashboard'));
const StudentAttendanceHistory = React.lazy(() => import('./pages/Student/StudentAttendanceHistory'));
const StudentSchedule = React.lazy(() => import('./pages/Student/StudentSchedule'));
const StudentProfile = React.lazy(() => import('./pages/Student/StudentProfile'));

// ============================================
// LAZY IMPORTS - PRINCIPAL PAGES
// ============================================
const PrincipalDashboard = React.lazy(() => import('./pages/Principal/PrincipalDashboard'));
const PrincipalMonitoring = React.lazy(() => import('./pages/Principal/PrincipalMonitoring'));
const PrincipalReports = React.lazy(() => import('./pages/Principal/PrincipalReports'));
const PrincipalApprovals = React.lazy(() => import('./pages/Principal/PrincipalApprovals'));

// ============================================
// LAZY IMPORTS - PARENT PAGES
// ============================================
const ParentDashboard = React.lazy(() => import('./pages/Parent/ParentDashboard'));
const ParentProfilePage = React.lazy(() => import('./modules/parent/pages/ParentProfilePage'));
const ParentStudentListPage = React.lazy(() => import('./modules/parent/pages/ParentStudentListPage'));
const ParentPermissionPage = React.lazy(() =>
  import('./pages/Parent/ParentPermissionPage').then(m => ({ default: m.ParentPermissionPage }))
);

// ============================================
// LAZY IMPORTS - GAMIFICATION PAGES
// ============================================
const LeaderboardPage = React.lazy(() => import('./modules/gamification/pages/LeaderboardPage'));
const BadgesPage = React.lazy(() => import('./modules/gamification/pages/BadgesPage'));

// ============================================
// LAZY IMPORTS - SUPER ADMIN PAGES
// ============================================
const SuperAdminDashboard = React.lazy(() =>
  import('./pages/SuperAdmin/SuperAdminDashboard').then(m => ({ default: m.SuperAdminDashboard }))
);
const NewDashboard = React.lazy(() =>
  import('./pages/SuperAdmin/NewDashboard').then(m => ({ default: m.NewDashboard }))
);
const SchoolsManagement = React.lazy(() =>
  import('./pages/SuperAdmin/SchoolsManagement').then(m => ({ default: m.SchoolsManagement }))
);
const SchoolActivation = React.lazy(() =>
  import('./pages/SuperAdmin/SchoolActivation').then(m => ({ default: m.SchoolActivation }))
);
const PackageLimits = React.lazy(() =>
  import('./pages/SuperAdmin/PackageLimits').then(m => ({ default: m.PackageLimits }))
);
const AdminSchoolManagement = React.lazy(() =>
  import('./pages/SuperAdmin/AdminSchoolManagement').then(m => ({ default: m.AdminSchoolManagement }))
);
const SubscriptionPackages = React.lazy(() =>
  import('./pages/SuperAdmin/SubscriptionPackages').then(m => ({ default: m.SubscriptionPackages }))
);
const PaymentHistory = React.lazy(() =>
  import('./pages/SuperAdmin/PaymentHistory').then(m => ({ default: m.PaymentHistory }))
);
const InvoiceManagement = React.lazy(() =>
  import('./pages/SuperAdmin/InvoiceManagement').then(m => ({ default: m.InvoiceManagement }))
);
const ActivityLogs = React.lazy(() =>
  import('./pages/SuperAdmin/ActivityLogs').then(m => ({ default: m.ActivityLogs }))
);
const ResetAccess = React.lazy(() =>
  import('./pages/SuperAdmin/ResetAccess').then(m => ({ default: m.ResetAccess }))
);
const RolePermission = React.lazy(() =>
  import('./pages/SuperAdmin/RolePermission').then(m => ({ default: m.RolePermission }))
);
const AuditLog = React.lazy(() =>
  import('./pages/SuperAdmin/AuditLog').then(m => ({ default: m.AuditLog }))
);
const RateLimit = React.lazy(() =>
  import('./pages/SuperAdmin/RateLimit').then(m => ({ default: m.RateLimit }))
);
const FeatureFlags = React.lazy(() =>
  import('./pages/SuperAdmin/FeatureFlags').then(m => ({ default: m.FeatureFlags }))
);
const GlobalAttendanceRecap = React.lazy(() =>
  import('./pages/SuperAdmin/Reports/GlobalAttendanceRecap').then(m => ({ default: m.GlobalAttendanceRecap }))
);
const PlatformStatistics = React.lazy(() =>
  import('./pages/SuperAdmin/Reports/PlatformStatistics').then(m => ({ default: m.PlatformStatistics }))
);
const GlobalExportResults = React.lazy(() =>
  import('./pages/SuperAdmin/Reports/GlobalExportResults').then(m => ({ default: m.GlobalExportResults }))
);
const AnnouncementsManagement = React.lazy(() =>
  import('./pages/SuperAdmin/AnnouncementsManagement').then(m => ({ default: m.AnnouncementsManagement }))
);
const BackupDatabase = React.lazy(() =>
  import('./pages/SuperAdmin/System/BackupDatabase').then(m => ({ default: m.BackupDatabase }))
);
const MaintenanceMode = React.lazy(() =>
  import('./pages/SuperAdmin/System/MaintenanceMode').then(m => ({ default: m.MaintenanceMode }))
);
const SystemHealth = React.lazy(() =>
  import('./pages/SuperAdmin/System/SystemHealth').then(m => ({ default: m.SystemHealth }))
);
const ScheduleTemplate = React.lazy(() =>
  import('./pages/SuperAdmin/ScheduleTemplate').then(m => ({ default: m.ScheduleTemplate }))
);
const SystemManagement = React.lazy(() =>
  import('./pages/SuperAdmin/SystemManagement').then(m => ({ default: m.SystemManagement }))
);
const AcademicYear = React.lazy(() =>
  import('./pages/SuperAdmin/AcademicYear').then(m => ({ default: m.AcademicYear }))
);
const SuperAdminProfile = React.lazy(() =>
  import('./pages/SuperAdmin/SuperAdminProfile').then(m => ({ default: m.SuperAdminProfile }))
);

// ============================================
// LOADING COMPONENT
// ============================================
const PageLoader = () => (
  <div className="flex h-screen items-center justify-center bg-gray-50">
    <div className="flex flex-col items-center gap-3">
      <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600" />
      <span className="text-sm text-gray-500">Memuat halaman...</span>
    </div>
  </div>
);

// ============================================
// ROUTE CONFIG
// ============================================
const roleRoutes: Record<string, string> = {
  super_admin: '/super-admin/dashboard',
  admin: '/admin/dashboard',
  school_admin: '/admin/dashboard',
  teacher: '/teacher/dashboard',
  homeroom_teacher: '/teacher/dashboard',
  principal: '/principal/dashboard',
  student: '/student/dashboard',
  parent: '/parent/dashboard',
};

const getDashboardPath = (role: string) => {
  return roleRoutes[role] || '/login';
};

const ProtectedRoute = ({ children, allowedRoles }: { children: React.ReactNode; allowedRoles: string[] }) => {
  const { user, isAuthenticated, isLoading } = useAuthStore();

  if (isLoading) return <PageLoader />;

  if (!isAuthenticated) return <Navigate to="/login" replace />;

  if (user && !allowedRoles.includes(user.role_type)) {
    return <Navigate to={getDashboardPath(user.role_type)} replace />;
  }

  return children;
};

// ============================================
// APP COMPONENT
// ============================================
function App() {
  const checkAuth = useAuthStore((state) => state.checkAuth);

  useEffect(() => {
    checkAuth();
  }, [checkAuth]);

  return (
    <AppErrorBoundary>
      <BrowserRouter>
        <Toaster />
        <Suspense fallback={<PageLoader />}>
          <Routes>
            <Route path="/login" element={<RoleSelectorPage />} />
            <Route path="/login/super-admin" element={<SuperAdminLoginPage />} />
            <Route path="/login/admin" element={<AdminLoginPage />} />
            <Route path="/login/teacher" element={<TeacherLoginPage />} />
            <Route path="/login/student" element={<StudentLoginPage />} />
            <Route path="/login/parent" element={<ParentLoginPage />} />
            <Route path="/register" element={<RegisterPage />} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />

            {/* Super Admin Dashboard V2 - in DashboardLayout */}
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
                    <PrincipalMonitoring />
                  </ProtectedRoute>
                } />
                <Route path="reports" element={
                  <ProtectedRoute allowedRoles={['principal']}>
                    <PrincipalReports />
                  </ProtectedRoute>
                } />
                <Route path="approvals" element={
                  <ProtectedRoute allowedRoles={['principal']}>
                    <PrincipalApprovals />
                  </ProtectedRoute>
                } />
                <Route path="class-performance" element={
                  <ProtectedRoute allowedRoles={['principal']}>
                    <PrincipalReports />
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
                <Route path="attendance/qr" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <AttendanceQR />
                  </ProtectedRoute>
                } />
                <Route path="attendance/manual" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <ManualAttendancePage />
                  </ProtectedRoute>
                } />
                <Route path="attendance/manual/:sessionId" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <ManualAttendancePage />
                  </ProtectedRoute>
                } />
                <Route path="permissions" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <HomeroomPermissions />
                  </ProtectedRoute>
                } />
                <Route path="class-attendance/daily" element={
                  <ProtectedRoute allowedRoles={['homeroom_teacher']}>
                    <HomeroomDailyAttendance />
                  </ProtectedRoute>
                } />
                <Route path="class-attendance/permissions" element={
                  <ProtectedRoute allowedRoles={['homeroom_teacher']}>
                    <HomeroomPermissions />
                  </ProtectedRoute>
                } />
                <Route path="class-attendance/notes" element={
                  <ProtectedRoute allowedRoles={['homeroom_teacher']}>
                    <HomeroomAttendanceNotes />
                  </ProtectedRoute>
                } />
                <Route path="class-attendance/recap" element={
                  <ProtectedRoute allowedRoles={['homeroom_teacher']}>
                    <HomeroomClassRecap />
                  </ProtectedRoute>
                } />
                <Route path="schedules" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <TeacherSchedulePage />
                  </ProtectedRoute>
                } />
                <Route path="profile" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <TeacherProfilePage />
                  </ProtectedRoute>
                } />
                <Route path="profile/password" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <TeacherPasswordChange />
                  </ProtectedRoute>
                } />
                <Route path="profile/login-history" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <TeacherLoginHistory />
                  </ProtectedRoute>
                } />
                <Route path="classes" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <TeacherClassListPage />
                  </ProtectedRoute>
                } />
                <Route path="attendance/list" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <TeacherAttendanceList />
                  </ProtectedRoute>
                } />
                <Route path="attendance/*" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <PlaceholderPage title="Absensi Mapel" />
                  </ProtectedRoute>
                } />
                <Route path="reports/attendance" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <TeacherPersonalReport />
                  </ProtectedRoute>
                } />
                <Route path="reports/sessions" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <TeacherSessionHistory />
                  </ProtectedRoute>
                } />
                <Route path="reports/homeroom" element={
                  <ProtectedRoute allowedRoles={['homeroom_teacher']}>
                    <HomeroomReport />
                  </ProtectedRoute>
                } />
                <Route path="reports/*" element={
                  <ProtectedRoute allowedRoles={['teacher', 'homeroom_teacher']}>
                    <PlaceholderPage title="Laporan Guru" />
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
                <Route path="billing/history" element={
                  <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                    <AdminBillingHistory />
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
                <Route path="school-profile" element={
                  <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                    <SchoolProfile />
                  </ProtectedRoute>
                } />
                <Route path="academic-year" element={
                  <ProtectedRoute allowedRoles={['admin', 'school_admin']}>
                    <ActiveAcademicYear />
                  </ProtectedRoute>
                } />
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
                    <StudentDashboard />
                  </ProtectedRoute>
                } />
                <Route path="history" element={
                  <ProtectedRoute allowedRoles={['student']}>
                    <StudentAttendanceHistory />
                  </ProtectedRoute>
                } />
                <Route path="schedule" element={
                  <ProtectedRoute allowedRoles={['student']}>
                    <StudentSchedule />
                  </ProtectedRoute>
                } />
                <Route path="profile" element={
                  <ProtectedRoute allowedRoles={['student']}>
                    <StudentProfile />
                  </ProtectedRoute>
                } />
                <Route path="leaderboard" element={
                  <ProtectedRoute allowedRoles={['student']}>
                    <LeaderboardPage />
                  </ProtectedRoute>
                } />
                <Route path="badges" element={
                  <ProtectedRoute allowedRoles={['student']}>
                    <BadgesPage />
                  </ProtectedRoute>
                } />
                <Route path="*" element={<Navigate to="dashboard" replace />} />
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
                    <ParentStudentListPage />
                  </ProtectedRoute>
                } />
                <Route path="permissions" element={
                  <ProtectedRoute allowedRoles={['parent']}>
                    <ParentPermissionPage />
                  </ProtectedRoute>
                } />
                <Route path="profile" element={
                  <ProtectedRoute allowedRoles={['parent']}>
                    <ParentProfilePage />
                  </ProtectedRoute>
                } />
                <Route path="students" element={
                  <ProtectedRoute allowedRoles={['parent']}>
                    <ParentStudentListPage />
                  </ProtectedRoute>
                } />
                <Route path="*" element={<Navigate to="dashboard" replace />} />
              </Route>

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
              <Route path="/super-admin/system/health" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <SystemHealth />
                </ProtectedRoute>
              } />
              <Route path="/super-admin/academic-year" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <AcademicYear />
                </ProtectedRoute>
              } />
              <Route path="/super-admin/schedule-templates" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <ScheduleTemplate />
                </ProtectedRoute>
              } />
              <Route path="/super-admin/system" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <SystemManagement />
                </ProtectedRoute>
              } />
              <Route path="/super-admin/gamification/leaderboard" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <LeaderboardPage />
                </ProtectedRoute>
              } />
              <Route path="/super-admin/gamification/badges" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <BadgesPage />
                </ProtectedRoute>
              } />
              <Route path="/super-admin/profile" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <SuperAdminProfile />
                </ProtectedRoute>
              } />
              <Route path="/super-admin/security-monitoring" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <SecurityMonitoring />
                </ProtectedRoute>
              } />
              <Route path="/super-admin/teacher-heatmap" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <TeacherHeatmapPage />
                </ProtectedRoute>
              } />
              <Route path="/super-admin/*" element={
                <ProtectedRoute allowedRoles={['super_admin']}>
                  <PlaceholderPage title="Fitur Super Admin" />
                </ProtectedRoute>
              } />
            </Route>

            <Route path="/" element={<RootRedirect />} />
          </Routes>
        </Suspense>
      </BrowserRouter>
    </AppErrorBoundary>
  );
}

const RootRedirect = () => {
  const { user, isAuthenticated, isLoading } = useAuthStore();
  if (isLoading) return <PageLoader />;
  if (isAuthenticated && user) {
    return <Navigate to={getDashboardPath(user.role_type)} replace />;
  }
  return <Navigate to="/login" replace />;
};

export default App;
