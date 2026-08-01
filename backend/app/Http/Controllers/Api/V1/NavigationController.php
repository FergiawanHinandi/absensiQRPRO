<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeacherRole;
use Illuminate\Http\Request;

class NavigationController extends Controller
{
    /**
     * Get navigation menu based on authenticated user's role
     * 
     * Server determines what user can see - no client-side role checking
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNavigation(Request $request)
    {
        $user = $request->user();
        $roleType = $user->role_type;

        // Get navigation config based on role
        $navigation = $this->getNavigationForRole($roleType, $user);

        return response()->json([
            'success' => true,
            'data' => $navigation,
        ]);
    }

    /**
     * Get permissions for authenticated user
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPermissions(Request $request)
    {
        $user = $request->user();
        
        $permissions = $user->getAllPermissions()->map(function ($permission) {
            return [
                'name' => $permission->name,
                'description' => $permission->description ?? null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Get navigation menu items for specific role
     * 
     * @param string $roleType
     * @param \App\Models\User $user
     * @return array
     */
    private function getNavigationForRole(string $roleType, $user): array
    {
        return match ($roleType) {
            'super_admin' => $this->getSuperAdminNavigation(),
            'school_admin' => $this->getSchoolAdminNavigation(),
            'principal' => $this->getPrincipalNavigation(),
            'vice_principal' => $this->getVicePrincipalNavigation(),
            'teacher' => $this->getTeacherNavigation($user),
            'homeroom_teacher' => $this->getHomeroomTeacherNavigation(),
            'staff' => $this->getStaffNavigation(),
            'student' => $this->getStudentNavigation(),
            'parent' => $this->getParentNavigation(),
            default => [],
        };
    }

    /**
     * BE-02 FIX: Path disesuaikan dengan actual routes di App.tsx SuperAdmin section
     */
    private function getSuperAdminNavigation(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'path' => '/super-admin/dashboard',
                'icon' => 'LayoutDashboard',
                'section' => 'Main',
            ],
            [
                'label' => 'Sekolah',
                'path' => '/super-admin/schools',
                'icon' => 'School',
                'section' => 'Management',
                'children' => [
                    [
                        'label' => 'Daftar Sekolah',
                        'path' => '/super-admin/schools',
                        'icon' => 'List',
                    ],
                    [
                        'label' => 'Aktivasi Sekolah',
                        'path' => '/super-admin/schools/activation',
                        'icon' => 'ToggleRight',
                    ],
                    [
                        'label' => 'Paket & Limit',
                        'path' => '/super-admin/schools/packages',
                        'icon' => 'Package',
                    ],
                ],
            ],
            [
                'label' => 'Pengguna',
                'path' => '/super-admin/users/admins',
                'icon' => 'Users',
                'section' => 'Management',
                'children' => [
                    [
                        'label' => 'Admin Sekolah',
                        'path' => '/super-admin/users/admins',
                        'icon' => 'UserCheck',
                    ],
                    [
                        'label' => 'Log Aktivitas',
                        'path' => '/super-admin/users/activity-logs',
                        'icon' => 'Activity',
                    ],
                    [
                        'label' => 'Reset Akses',
                        'path' => '/super-admin/users/reset-access',
                        'icon' => 'RefreshCw',
                    ],
                ],
            ],
            [
                'label' => 'Billing',
                'path' => '/super-admin/billing/packages',
                'icon' => 'CreditCard',
                'section' => 'Management',
                'children' => [
                    [
                        'label' => 'Paket Langganan',
                        'path' => '/super-admin/billing/packages',
                        'icon' => 'Package',
                    ],
                    [
                        'label' => 'Riwayat Pembayaran',
                        'path' => '/super-admin/billing/payment-history',
                        'icon' => 'Receipt',
                    ],
                    [
                        'label' => 'Invoice',
                        'path' => '/super-admin/billing/invoices',
                        'icon' => 'FileText',
                    ],
                ],
            ],
            [
                'label' => 'Laporan',
                'path' => '/super-admin/reports/attendance',
                'icon' => 'BarChart',
                'section' => 'Reports',
                'children' => [
                    [
                        'label' => 'Rekap Absensi',
                        'path' => '/super-admin/reports/attendance',
                        'icon' => 'ClipboardCheck',
                    ],
                    [
                        'label' => 'Statistik Platform',
                        'path' => '/super-admin/reports/statistics',
                        'icon' => 'TrendingUp',
                    ],
                    [
                        'label' => 'Export Global',
                        'path' => '/super-admin/reports/export',
                        'icon' => 'Download',
                    ],
                ],
            ],
            [
                'label' => 'Keamanan',
                'path' => '/super-admin/security/roles',
                'icon' => 'Shield',
                'section' => 'System',
                'children' => [
                    [
                        'label' => 'Role & Izin',
                        'path' => '/super-admin/security/roles',
                        'icon' => 'Lock',
                    ],
                    [
                        'label' => 'Audit Log',
                        'path' => '/super-admin/security/audit',
                        'icon' => 'FileSearch',
                    ],
                    [
                        'label' => 'Rate Limit',
                        'path' => '/super-admin/security/rate-limit',
                        'icon' => 'Gauge',
                    ],
                ],
            ],
            [
                'label' => 'Sistem',
                'path' => '/super-admin/system',
                'icon' => 'Settings',
                'section' => 'System',
                'children' => [
                    [
                        'label' => 'Manajemen Sistem',
                        'path' => '/super-admin/system',
                        'icon' => 'Server',
                    ],
                    [
                        'label' => 'Kesehatan Sistem',
                        'path' => '/super-admin/system/health',
                        'icon' => 'Activity',
                    ],
                    [
                        'label' => 'Backup Database',
                        'path' => '/super-admin/system/backup',
                        'icon' => 'Database',
                    ],
                    [
                        'label' => 'Mode Maintenance',
                        'path' => '/super-admin/system/maintenance',
                        'icon' => 'Tool',
                    ],
                ],
            ],
            [
                'label' => 'Konfigurasi',
                'path' => '/super-admin/config/features',
                'icon' => 'Sliders',
                'section' => 'System',
                'children' => [
                    [
                        'label' => 'Feature Flags',
                        'path' => '/super-admin/config/features',
                        'icon' => 'ToggleLeft',
                    ],
                    [
                        'label' => 'Template Jadwal',
                        'path' => '/super-admin/schedule-templates',
                        'icon' => 'Calendar',
                    ],
                    [
                        'label' => 'Tahun Ajaran',
                        'path' => '/super-admin/academic-year',
                        'icon' => 'CalendarRange',
                    ],
                ],
            ],
            [
                'label' => 'Pengumuman',
                'path' => '/super-admin/announcements',
                'icon' => 'Bell',
                'section' => 'System',
            ],
        ];
    }

    /**
     * BE-01 FIX: Path disesuaikan dengan actual routes di App.tsx Admin section
     * 
     * Route yang ADA di App.tsx:
     * - /admin/dashboard
     * - /admin/dashboard/class-attendance
     * - /admin/dashboard/teacher-absent
     * - /admin/dashboard/late-absent
     * - /admin/dashboard/anomalies
     * - /admin/students, /admin/teachers, /admin/classes, /admin/subjects
     * - /admin/schedules, /admin/parents, /admin/reports
     * - /admin/attendance/qr-mode, override, tolerance
     * - /admin/attendance/* → AdminAttendanceSettings
     * - /admin/settings, /admin/risk-overview, /admin/security-monitoring
     * - /admin/student-cards, /admin/photo-review, /admin/notifications
     * - /admin/school-profile, /admin/academic-year
     */
    private function getSchoolAdminNavigation(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'path' => '/admin/dashboard',
                'icon' => 'LayoutDashboard',
                'section' => 'Main',
                'children' => [
                    [
                        'label' => 'Absensi Kelas',
                        'path' => '/admin/dashboard/class-attendance',
                        'icon' => 'Users',
                    ],
                    [
                        'label' => 'Guru Absen',
                        'path' => '/admin/dashboard/teacher-absent',
                        'icon' => 'UserX',
                    ],
                    [
                        'label' => 'Terlambat & Alpha',
                        'path' => '/admin/dashboard/late-absent',
                        'icon' => 'Clock',
                    ],
                    [
                        'label' => 'Anomali',
                        'path' => '/admin/dashboard/anomalies',
                        'icon' => 'AlertTriangle',
                    ],
                ],
            ],
            [
                'label' => 'Siswa',
                'path' => '/admin/students',
                'icon' => 'Users',
                'section' => 'Management',
            ],
            [
                'label' => 'Guru',
                'path' => '/admin/teachers',
                'icon' => 'UserCheck',
                'section' => 'Management',
            ],
            [
                'label' => 'Kelas',
                'path' => '/admin/classes',
                'icon' => 'School',
                'section' => 'Management',
            ],
            [
                'label' => 'Mata Pelajaran',
                'path' => '/admin/subjects',
                'icon' => 'BookOpen',
                'section' => 'Management',
            ],
            [
                'label' => 'Jadwal',
                'path' => '/admin/schedules',
                'icon' => 'CalendarDays',
                'section' => 'Management',
            ],
            [
                'label' => 'Orang Tua',
                'path' => '/admin/parents',
                'icon' => 'Heart',
                'section' => 'Management',
            ],
            [
                'label' => 'Kartu Siswa',
                'path' => '/admin/student-cards',
                'icon' => 'CreditCard',
                'section' => 'Management',
            ],
            [
                'label' => 'Review Foto',
                'path' => '/admin/photo-review',
                'icon' => 'Image',
                'section' => 'Management',
            ],
            [
                'label' => 'Laporan',
                'path' => '/admin/reports',
                'icon' => 'FileText',
                'section' => 'Reports',
            ],
            [
                'label' => 'Risiko Absensi',
                'path' => '/admin/risk-overview',
                'icon' => 'AlertCircle',
                'section' => 'Reports',
            ],
            [
                'label' => 'Pengaturan Absensi',
                'path' => '/admin/attendance',
                'icon' => 'ClipboardCheck',
                'section' => 'Settings',
                'children' => [
                    [
                        'label' => 'Mode QR',
                        'path' => '/admin/attendance/qr-mode',
                        'icon' => 'QrCode',
                    ],
                    [
                        'label' => 'Override',
                        'path' => '/admin/attendance/override',
                        'icon' => 'Edit',
                    ],
                    [
                        'label' => 'Toleransi',
                        'path' => '/admin/attendance/tolerance',
                        'icon' => 'Clock',
                    ],
                ],
            ],
            [
                'label' => 'Monitoring Keamanan',
                'path' => '/admin/security-monitoring',
                'icon' => 'Shield',
                'section' => 'Settings',
            ],
            [
                'label' => 'Notifikasi',
                'path' => '/admin/notifications',
                'icon' => 'Bell',
                'section' => 'Settings',
            ],
            [
                'label' => 'Profil Sekolah',
                'path' => '/admin/school-profile',
                'icon' => 'Building',
                'section' => 'Settings',
            ],
            [
                'label' => 'Tahun Ajaran',
                'path' => '/admin/academic-year',
                'icon' => 'Calendar',
                'section' => 'Settings',
            ],
            [
                'label' => 'Pengaturan',
                'path' => '/admin/settings',
                'icon' => 'Settings',
                'section' => 'Settings',
            ],
        ];
    }

    private function getPrincipalNavigation(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'path' => '/principal/dashboard',
                'icon' => 'LayoutDashboard',
                'section' => 'Main',
            ],
            [
                'label' => 'Monitoring',
                'path' => '/principal/monitoring',
                'icon' => 'Monitor',
                'section' => 'Main',
            ],
            [
                'label' => 'Laporan',
                'path' => '/principal/reports',
                'icon' => 'FileText',
                'section' => 'Reports',
            ],
            [
                'label' => 'Persetujuan',
                'path' => '/principal/approvals',
                'icon' => 'CheckSquare',
                'section' => 'Reports',
            ],
            [
                'label' => 'Kinerja Kelas',
                'path' => '/principal/class-performance',
                'icon' => 'TrendingUp',
                'section' => 'Reports',
            ],
        ];
    }

    private function getVicePrincipalNavigation(): array
    {
        return $this->getPrincipalNavigation();
    }

    /**
     * BE-07 FIX: Tidak gunakan $user->teacher (relasi tidak ada di User model).
     *            Gunakan query TeacherRole untuk cek apakah teacher adalah homeroom teacher.
     * 
     * BE-03 FIX: Path untuk homeroom menu disesuaikan:
     *   - /teacher/class-attendance/daily (ada di App.tsx)
     *   - /teacher/class-attendance/permissions (ada di App.tsx)
     *   - /teacher/permissions (ada di App.tsx)
     */
    private function getTeacherNavigation($user): array
    {
        $navigation = [
            [
                'label' => 'Dashboard',
                'path' => '/teacher/dashboard',
                'icon' => 'LayoutDashboard',
                'section' => 'Main',
            ],
            [
                'label' => 'Jadwal Saya',
                'path' => '/teacher/schedules',
                'icon' => 'CalendarDays',
                'section' => 'Main',
            ],
            [
                'label' => 'Absensi',
                'path' => '/teacher/attendance/qr',
                'icon' => 'ClipboardCheck',
                'section' => 'Main',
                'children' => [
                    [
                        'label' => 'Scan QR',
                        'path' => '/teacher/attendance/qr',
                        'icon' => 'QrCode',
                    ],
                    [
                        'label' => 'Input Manual',
                        'path' => '/teacher/attendance/manual',
                        'icon' => 'Edit',
                    ],
                ],
            ],
            [
                'label' => 'Perizinan',
                'path' => '/teacher/permissions',
                'icon' => 'FileCheck',
                'section' => 'Main',
            ],
            [
                'label' => 'Profil',
                'path' => '/teacher/profile',
                'icon' => 'User',
                'section' => 'Main',
            ],
        ];

        // BE-07 FIX: Cek homeroom teacher menggunakan role_type field langsung
        // atau dengan query TeacherRole (hindari lazy loading error)
        if ($user->role_type === 'homeroom_teacher') {
            $navigation[] = [
                'label' => 'Wali Kelas',
                'path' => '/teacher/class-attendance/daily',
                'icon' => 'Users',
                'section' => 'Homeroom',
                'children' => [
                    [
                        'label' => 'Absensi Harian',
                        'path' => '/teacher/class-attendance/daily',
                        'icon' => 'ClipboardList',
                    ],
                    [
                        'label' => 'Izin Siswa',
                        'path' => '/teacher/class-attendance/permissions',
                        'icon' => 'FileCheck',
                    ],
                ],
            ];
        } else {
            // Untuk teacher biasa, cek apakah memiliki peran wali kelas via TeacherRole
            // Gunakan query langsung agar tidak memicu lazy loading
            $isHomeroomViaRole = TeacherRole::where('teacher_id', $user->id)
                ->where('is_homeroom_teacher', true)
                ->exists();

            if ($isHomeroomViaRole) {
                $navigation[] = [
                    'label' => 'Wali Kelas',
                    'path' => '/teacher/class-attendance/daily',
                    'icon' => 'Users',
                    'section' => 'Homeroom',
                    'children' => [
                        [
                            'label' => 'Absensi Harian',
                            'path' => '/teacher/class-attendance/daily',
                            'icon' => 'ClipboardList',
                        ],
                        [
                            'label' => 'Izin Siswa',
                            'path' => '/teacher/class-attendance/permissions',
                            'icon' => 'FileCheck',
                        ],
                    ],
                ];
            }
        }

        return $navigation;
    }

    /**
     * BE-03 FIX: Path disesuaikan dengan App.tsx homeroom routes
     */
    private function getHomeroomTeacherNavigation(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'path' => '/teacher/dashboard',
                'icon' => 'LayoutDashboard',
                'section' => 'Main',
            ],
            [
                'label' => 'Jadwal Saya',
                'path' => '/teacher/schedules',
                'icon' => 'CalendarDays',
                'section' => 'Main',
            ],
            [
                'label' => 'Absensi',
                'path' => '/teacher/attendance/qr',
                'icon' => 'ClipboardCheck',
                'section' => 'Main',
                'children' => [
                    [
                        'label' => 'Scan QR',
                        'path' => '/teacher/attendance/qr',
                        'icon' => 'QrCode',
                    ],
                    [
                        'label' => 'Input Manual',
                        'path' => '/teacher/attendance/manual',
                        'icon' => 'Edit',
                    ],
                ],
            ],
            [
                'label' => 'Perizinan',
                'path' => '/teacher/permissions',
                'icon' => 'FileCheck',
                'section' => 'Main',
            ],
            [
                'label' => 'Profil',
                'path' => '/teacher/profile',
                'icon' => 'User',
                'section' => 'Main',
            ],
            [
                'label' => 'Wali Kelas',
                'path' => '/teacher/class-attendance/daily',
                'icon' => 'Users',
                'section' => 'Homeroom',
                'children' => [
                    [
                        'label' => 'Absensi Harian',
                        'path' => '/teacher/class-attendance/daily',
                        'icon' => 'ClipboardList',
                    ],
                    [
                        'label' => 'Izin Siswa',
                        'path' => '/teacher/class-attendance/permissions',
                        'icon' => 'FileCheck',
                    ],
                ],
            ],
        ];
    }

    private function getStaffNavigation(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'path' => '/staff/dashboard',
                'icon' => 'LayoutDashboard',
                'section' => 'Main',
            ],
        ];
    }

    /**
     * BE-05 FIX: Path disesuaikan dengan App.tsx student routes
     * - /student/history (bukan /student/attendance)
     */
    private function getStudentNavigation(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'path' => '/student/dashboard',
                'icon' => 'LayoutDashboard',
                'section' => 'Main',
            ],
            [
                'label' => 'Riwayat Absensi',
                'path' => '/student/history',   // FIX: was /student/attendance
                'icon' => 'ClipboardCheck',
                'section' => 'Main',
            ],
            [
                'label' => 'Jadwal',
                'path' => '/student/schedule',
                'icon' => 'CalendarDays',
                'section' => 'Main',
            ],
            [
                'label' => 'Leaderboard',
                'path' => '/student/leaderboard',
                'icon' => 'Trophy',
                'section' => 'Main',
            ],
            [
                'label' => 'Lencana',
                'path' => '/student/badges',
                'icon' => 'Award',
                'section' => 'Main',
            ],
            [
                'label' => 'Profil',
                'path' => '/student/profile',
                'icon' => 'User',
                'section' => 'Main',
            ],
        ];
    }

    /**
     * BE-04 FIX: Path disesuaikan dengan App.tsx parent routes
     * - /parent/children-history (bukan /parent/children)
     */
    private function getParentNavigation(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'path' => '/parent/dashboard',
                'icon' => 'LayoutDashboard',
                'section' => 'Main',
            ],
            [
                'label' => 'Riwayat Anak',
                'path' => '/parent/children-history',   // FIX: was /parent/children
                'icon' => 'Users',
                'section' => 'Main',
            ],
            [
                'label' => 'Semua Anak',
                'path' => '/parent/students',
                'icon' => 'Heart',
                'section' => 'Main',
            ],
            [
                'label' => 'Perizinan',
                'path' => '/parent/permissions',
                'icon' => 'FileCheck',
                'section' => 'Main',
            ],
            [
                'label' => 'Profil',
                'path' => '/parent/profile',
                'icon' => 'User',
                'section' => 'Main',
            ],
        ];
    }
}
