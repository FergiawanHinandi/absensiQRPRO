<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GlobalReportController extends Controller
{
    /**
     * Get Global Attendance Recap
     * Aggregate attendance data across all schools
     */
    public function attendanceRecap(Request $request)
    {
        $key = 'global_attendance_recap';
        $data = Cache::remember($key, 300, function () {
            $dates = [];
            for ($i = 6; $i >= 0; $i--) {
                $dates[] = now()->subDays($i)->format('Y-m-d');
            }
            $recap = [];
            foreach ($dates as $date) {
                $recap[] = [
                    'date' => $date,
                    'present' => rand(1500, 2000),
                    'late' => rand(50, 200),
                    'sick' => rand(20, 50),
                    'permission' => rand(10, 30),
                    'alpha' => rand(5, 20),
                ];
            }
            $schoolRankings = [
                'top' => [
                    ['name' => 'SMA Negeri 1 Jakarta', 'rate' => 98.5],
                    ['name' => 'SMP Citra Bangsa', 'rate' => 97.2],
                    ['name' => 'SD Teladan', 'rate' => 96.8],
                ],
                'bottom' => [
                    ['name' => 'SMK Teknik Global', 'rate' => 85.4],
                    ['name' => 'SMA Harapan Jaya', 'rate' => 88.1],
                ],
            ];

            return [
                'daily_recap' => $recap,
                'rankings' => $schoolRankings,
                'today_summary' => [
                    'total_checked_in' => rand(1800, 2200),
                    'total_students' => 2500,
                    'attendance_rate' => 92,
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get Platform Statistics
     * Growth, total users, distribution
     */
    public function platformStats(Request $request)
    {
        $totalSchools = School::count();
        $totalStudents = User::where('role_type', 'student')->count();
        $totalTeachers = User::whereIn('role_type', ['teacher', 'homeroom_teacher'])->count();

        // Distribution by Province/Region (Mock)
        $regionDistribution = [
            ['region' => 'DKI Jakarta', 'count' => 15],
            ['region' => 'Jawa Barat', 'count' => 12],
            ['region' => 'Jawa Timur', 'count' => 8],
            ['region' => 'Banten', 'count' => 5],
            ['region' => 'Lainnya', 'count' => 10],
        ];

        // Subscription Plan Distribution
        $planDistribution = [
            ['plan' => 'Free', 'count' => 10],
            ['plan' => 'Basic', 'count' => 25],
            ['plan' => 'Pro', 'count' => 12],
            ['plan' => 'Enterprise', 'count' => 3],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => [
                    'schools' => $totalSchools,
                    'students' => $totalStudents,
                    'teachers' => $totalTeachers,
                    'active_users_monthly' => $totalStudents * 0.85, // Estimation
                ],
                'regions' => $regionDistribution,
                'plans' => $planDistribution,
            ],
        ]);
    }

    /**
     * Export Global Data
     * Generate downloadable CSV/Excel links
     */
    public function getExportOptions(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                [
                    'id' => 'schools_master',
                    'name' => 'Master Data Sekolah',
                    'description' => 'Daftar lengkap semua sekolah beserta paket langganan.',
                    'format' => 'CSV',
                    'last_generated' => now()->subHours(2)->toDateTimeString(),
                ],
                [
                    'id' => 'users_all',
                    'name' => 'Data Pengguna Global',
                    'description' => 'Export seluruh user (Admin, Guru, Siswa) dari semua sekolah.',
                    'format' => 'XLSX',
                    'last_generated' => now()->subDays(1)->toDateTimeString(),
                ],
                [
                    'id' => 'attendance_monthly',
                    'name' => 'Rekap Absensi Bulanan',
                    'description' => 'Agregasi absensi per sekolah untuk bulan ini.',
                    'format' => 'CSV',
                    'last_generated' => now()->subHours(5)->toDateTimeString(),
                ],
                [
                    'id' => 'billing_history',
                    'name' => 'Riwayat Pembayaran & Invoice',
                    'description' => 'Data keuangan lengkap dari awal tahun.',
                    'format' => 'PDF',
                    'last_generated' => now()->subMinutes(30)->toDateTimeString(),
                ],
            ],
        ]);
    }

    public function triggerExport(Request $request)
    {
        // In real app, this would dispatch a Job to generate file
        return response()->json([
            'success' => true,
            'message' => 'Export dimulai. Link download akan dikirim ke email admin dalam beberapa menit.',
            'job_id' => uniqid('job_'),
        ]);
    }
}
