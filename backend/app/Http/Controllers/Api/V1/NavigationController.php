<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
            ],
            [
                'label' => 'Paket Langganan',
                'path' => '/super-admin/packages',
                'icon' => 'Package',
                'section' => 'Management',
            ],
            [
                'label' => 'Pembayaran',
                'path' => '/super-admin/payments',
                'icon' => 'CreditCard',
                'section' => 'Management',
            ],
            [
                'label' => 'Pengguna',
                'path' => '/super-admin/users',
                'icon' => 'Users',
                'section' => 'Management',
            ],
            [
                'label' => 'Laporan',
                'path' => '/super-admin/reports',
                'icon' => 'FileText',
                'section' => 'Reports',
            ],
            [
                'label' => 'Pengaturan',
                'path' => '/super-admin/settings',
                'icon' => 'Settings',
                'section' => 'System',
            ],
        ];
    }

    private function getSchoolAdminNavigation(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'path' => '/admin/dashboard',
                'icon' => 'LayoutDashboard',
                'section' => 'Main',
            ],
            [
                'label' => 'Absensi',
                'path' => '/admin/attendance',
                'icon' => 'ClipboardCheck',
                'section' => 'Main',
                'children' => [
                    [
                        'label' => 'Hari Ini',
                        'path' => '/admin/attendance/today',
                        'icon' => 'Calendar',
                    ],
                    [
                        'label' => 'Riwayat',
                        'path' => '/admin/attendance/history',
                        'icon' => 'History',
                    ],
                    [
                        'label' => 'Laporan',
                        'path' => '/admin/attendance/reports',
                        'icon' => 'FileText',
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
                'label' => 'Jadwal',
                'path' => '/admin/schedules',
                'icon' => 'CalendarDays',
                'section' => 'Management',
            ],
            [
                'label' => 'Pengaturan',
                'path' => '/admin/settings',
                'icon' => 'Settings',
                'section' => 'System',
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
                'children' => [
                    [
                        'label' => 'Absensi',
                        'path' => '/principal/reports/attendance',
                        'icon' => 'ClipboardCheck',
                    ],
                    [
                        'label' => 'Kinerja Guru',
                        'path' => '/principal/reports/teachers',
                        'icon' => 'UserCheck',
                    ],
                    [
                        'label' => 'Kinerja Siswa',
                        'path' => '/principal/reports/students',
                        'icon' => 'Users',
                    ],
                ],
            ],
        ];
    }

    private function getVicePrincipalNavigation(): array
    {
        return $this->getPrincipalNavigation();
    }

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
                'path' => '/teacher/attendance',
                'icon' => 'ClipboardCheck',
                'section' => 'Main',
            ],
        ];

        // Check if teacher is homeroom teacher
        $teacher = $user->teacher;
        if ($teacher && $teacher->is_homeroom_teacher) {
            $navigation[] = [
                'label' => 'Wali Kelas',
                'path' => '/teacher/homeroom',
                'icon' => 'Users',
                'section' => 'Homeroom',
                'children' => [
                    [
                        'label' => 'Siswa Saya',
                        'path' => '/teacher/homeroom/students',
                        'icon' => 'Users',
                    ],
                    [
                        'label' => 'Absensi Kelas',
                        'path' => '/teacher/homeroom/attendance',
                        'icon' => 'ClipboardCheck',
                    ],
                ],
            ];
        }

        return $navigation;
    }

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
                'path' => '/teacher/attendance',
                'icon' => 'ClipboardCheck',
                'section' => 'Main',
            ],
            [
                'label' => 'Wali Kelas',
                'path' => '/teacher/homeroom',
                'icon' => 'Users',
                'section' => 'Homeroom',
                'children' => [
                    [
                        'label' => 'Siswa Saya',
                        'path' => '/teacher/homeroom/students',
                        'icon' => 'Users',
                    ],
                    [
                        'label' => 'Absensi Kelas',
                        'path' => '/teacher/homeroom/attendance',
                        'icon' => 'ClipboardCheck',
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
                'label' => 'Absensi Saya',
                'path' => '/student/attendance',
                'icon' => 'ClipboardCheck',
                'section' => 'Main',
            ],
            [
                'label' => 'Jadwal',
                'path' => '/student/schedule',
                'icon' => 'CalendarDays',
                'section' => 'Main',
            ],
        ];
    }

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
                'label' => 'Anak Saya',
                'path' => '/parent/children',
                'icon' => 'Users',
                'section' => 'Main',
            ],
            [
                'label' => 'Absensi',
                'path' => '/parent/attendance',
                'icon' => 'ClipboardCheck',
                'section' => 'Main',
            ],
        ];
    }
}
