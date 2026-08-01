<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Student Risk Analysis Service
 * Analyzes student attendance patterns to determine risk levels
 */
class StudentRiskAnalysisService
{
    // Risk level constants
    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    // Risk thresholds (percentage)
    public const THRESHOLD_CRITICAL = 50; // < 50% attendance

    public const THRESHOLD_HIGH = 70;     // 50-70% attendance

    public const THRESHOLD_MEDIUM = 85;   // 70-85% attendance
    // LOW = > 85% attendance

    /**
     * Get risk overview for school admin dashboard
     */
    public function getRiskOverview(int $schoolId): array
    {
        $tz = \App\Models\School::find($schoolId)?->timezone ?? config("app.timezone");
        $cacheKey = "risk_overview_{$schoolId}_".now()->timezone($tz)->format('Y-m-d');

        return Cache::remember($cacheKey, 3600, function () use ($schoolId) {
            return [
                'total_students_by_risk' => $this->getTotalStudentsByRisk($schoolId),
                'classes_with_high_risk' => $this->getClassesWithHighRisk($schoolId),
                'risk_trend_30_days' => $this->getRiskTrend30Days($schoolId),
                'critical_students' => $this->getCriticalStudents($schoolId),
                'summary_stats' => $this->getSummaryStats($schoolId),
                'generated_at' => now()->toISOString(),
            ];
        });
    }

    /**
     * Get total students in each risk level
     */
    public function getTotalStudentsByRisk(int $schoolId): array
    {
        $tz = \App\Models\School::find($schoolId)?->timezone ?? config("app.timezone");
        // Calculate attendance percentage for each student in last 30 days
        $thirtyDaysAgo = now()->timezone($tz)->subDays(30);

        $studentRisks = DB::select("
            WITH student_attendance AS (
                SELECT 
                    u.id as student_id,
                    u.name as student_name,
                    cs.class_id,
                    c.name as class_name,
                    COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) as present_days,
                    COUNT(a.id) as total_days,
                    CASE 
                        WHEN COUNT(a.id) = 0 THEN 0
                        ELSE ROUND((COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)), 2)
                    END as attendance_percentage
                FROM users u
                INNER JOIN class_students cs ON u.id = cs.student_id
                INNER JOIN classes c ON cs.class_id = c.id
                LEFT JOIN attendances a ON u.id = a.student_id 
                    AND a.attendance_date >= ? 
                    AND a.school_id = ?
                WHERE u.school_id = ? 
                    AND u.role_type = 'student' 
                    AND u.is_active = true
                    AND cs.status = 'active'
                GROUP BY u.id, u.name, cs.class_id, c.name
            )
            SELECT 
                student_id,
                student_name,
                class_id,
                class_name,
                present_days,
                total_days,
                attendance_percentage,
                CASE 
                    WHEN attendance_percentage < ? THEN 'critical'
                    WHEN attendance_percentage < ? THEN 'high'
                    WHEN attendance_percentage < ? THEN 'medium'
                    ELSE 'low'
                END as risk_level
            FROM student_attendance
            ORDER BY attendance_percentage ASC
        ", [
            $thirtyDaysAgo->format('Y-m-d'),
            $schoolId,
            $schoolId,
            self::THRESHOLD_CRITICAL,
            self::THRESHOLD_HIGH,
            self::THRESHOLD_MEDIUM,
        ]);

        // Group by risk level
        $riskCounts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        foreach ($studentRisks as $student) {
            $riskCounts[$student->risk_level]++;
        }

        return [
            'risk_counts' => $riskCounts,
            'total_students' => array_sum($riskCounts),
            'risk_percentages' => [
                'critical' => $this->calculatePercentage($riskCounts['critical'], array_sum($riskCounts)),
                'high' => $this->calculatePercentage($riskCounts['high'], array_sum($riskCounts)),
                'medium' => $this->calculatePercentage($riskCounts['medium'], array_sum($riskCounts)),
                'low' => $this->calculatePercentage($riskCounts['low'], array_sum($riskCounts)),
            ],
        ];
    }

    /**
     * Get classes with most high-risk students
     */
    public function getClassesWithHighRisk(int $schoolId): array
    {
        $tz = \App\Models\School::find($schoolId)?->timezone ?? config("app.timezone");
        $thirtyDaysAgo = now()->timezone($tz)->subDays(30);

        $classRisks = DB::select("
            WITH student_risk_by_class AS (
                SELECT 
                    c.id as class_id,
                    c.name as class_name,
                    c.grade_level,
                    u.id as student_id,
                    u.name as student_name,
                    COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) as present_days,
                    COUNT(a.id) as total_days,
                    CASE 
                        WHEN COUNT(a.id) = 0 THEN 0
                        ELSE ROUND((COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)), 2)
                    END as attendance_percentage,
                    CASE 
                        WHEN COUNT(a.id) = 0 OR (COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)) < ? THEN 'critical'
                        WHEN (COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)) < ? THEN 'high'
                        WHEN (COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)) < ? THEN 'medium'
                        ELSE 'low'
                    END as risk_level
                FROM classes c
                INNER JOIN class_students cs ON c.id = cs.class_id
                INNER JOIN users u ON cs.student_id = u.id
                LEFT JOIN attendances a ON u.id = a.student_id 
                    AND a.attendance_date >= ?
                    AND a.school_id = ?
                WHERE c.school_id = ? 
                    AND u.role_type = 'student' 
                    AND u.is_active = true
                    AND cs.status = 'active'
                GROUP BY c.id, c.name, c.grade_level, u.id, u.name
            )
            SELECT 
                class_id,
                class_name,
                grade_level,
                COUNT(*) as total_students,
                COUNT(CASE WHEN risk_level = 'critical' THEN 1 END) as critical_students,
                COUNT(CASE WHEN risk_level = 'high' THEN 1 END) as high_students,
                COUNT(CASE WHEN risk_level = 'medium' THEN 1 END) as medium_students,
                COUNT(CASE WHEN risk_level = 'low' THEN 1 END) as low_students,
                ROUND((COUNT(CASE WHEN risk_level IN ('critical', 'high') THEN 1 END) * 100.0 / COUNT(*)), 2) as high_risk_percentage
            FROM student_risk_by_class
            GROUP BY class_id, class_name, grade_level
            ORDER BY high_risk_percentage DESC, critical_students DESC
            LIMIT 10
        ", [
            self::THRESHOLD_CRITICAL,
            self::THRESHOLD_HIGH,
            self::THRESHOLD_MEDIUM,
            $thirtyDaysAgo->format('Y-m-d'),
            $schoolId,
            $schoolId,
        ]);

        return array_map(function ($class) {
            return [
                'class_id' => $class->class_id,
                'class_name' => $class->class_name,
                'grade_level' => $class->grade_level,
                'total_students' => (int) $class->total_students,
                'critical_students' => (int) $class->critical_students,
                'high_students' => (int) $class->high_students,
                'medium_students' => (int) $class->medium_students,
                'low_students' => (int) $class->low_students,
                'high_risk_percentage' => (float) $class->high_risk_percentage,
                'risk_distribution' => [
                    'critical' => $this->calculatePercentage($class->critical_students, $class->total_students),
                    'high' => $this->calculatePercentage($class->high_students, $class->total_students),
                    'medium' => $this->calculatePercentage($class->medium_students, $class->total_students),
                    'low' => $this->calculatePercentage($class->low_students, $class->total_students),
                ],
            ];
        }, $classRisks);
    }

    /**
     * Get risk level trend over last 30 days
     */
    public function getRiskTrend30Days(int $schoolId): array
    {
        $tz = \App\Models\School::find($schoolId)?->timezone ?? config("app.timezone");
        $trends = [];

        // Calculate risk for each of the last 30 days
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->timezone($tz)->subDays($i);
            $weekStart = $date->copy()->subDays(6); // 7-day rolling window

            $dailyRisk = DB::select("
                WITH daily_student_risk AS (
                    SELECT 
                        u.id as student_id,
                        COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) as present_days,
                        COUNT(a.id) as total_days,
                        CASE 
                            WHEN COUNT(a.id) = 0 THEN 0
                            ELSE ROUND((COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)), 2)
                        END as attendance_percentage
                    FROM users u
                    LEFT JOIN attendances a ON u.id = a.student_id 
                        AND a.attendance_date BETWEEN ? AND ?
                        AND a.school_id = ?
                    WHERE u.school_id = ? 
                        AND u.role_type = 'student' 
                        AND u.is_active = true
                    GROUP BY u.id
                )
                SELECT 
                    COUNT(CASE WHEN attendance_percentage < ? THEN 1 END) as critical_count,
                    COUNT(CASE WHEN attendance_percentage >= ? AND attendance_percentage < ? THEN 1 END) as high_count,
                    COUNT(CASE WHEN attendance_percentage >= ? AND attendance_percentage < ? THEN 1 END) as medium_count,
                    COUNT(CASE WHEN attendance_percentage >= ? THEN 1 END) as low_count,
                    COUNT(*) as total_count
                FROM daily_student_risk
            ", [
                $weekStart->format('Y-m-d'),
                $date->format('Y-m-d'),
                $schoolId,
                $schoolId,
                self::THRESHOLD_CRITICAL,
                self::THRESHOLD_CRITICAL,
                self::THRESHOLD_HIGH,
                self::THRESHOLD_HIGH,
                self::THRESHOLD_MEDIUM,
                self::THRESHOLD_MEDIUM,
            ]);

            $risk = $dailyRisk[0] ?? null;

            $trends[] = [
                'date' => $date->format('Y-m-d'),
                'day_name' => $date->format('l'),
                'critical' => (int) ($risk->critical_count ?? 0),
                'high' => (int) ($risk->high_count ?? 0),
                'medium' => (int) ($risk->medium_count ?? 0),
                'low' => (int) ($risk->low_count ?? 0),
                'total' => (int) ($risk->total_count ?? 0),
            ];
        }

        return $trends;
    }

    /**
     * Get critical students that need immediate attention
     */
    public function getCriticalStudents(int $schoolId): array
    {
        $tz = \App\Models\School::find($schoolId)?->timezone ?? config("app.timezone");
        $thirtyDaysAgo = now()->timezone($tz)->subDays(30);

        $criticalStudents = DB::select("
            SELECT 
                u.id as student_id,
                u.name as student_name,
                u.email,
                c.name as class_name,
                c.grade_level,
                COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) as present_days,
                COUNT(a.id) as total_days,
                COUNT(CASE WHEN a.status = 'alpha' THEN 1 END) as alpha_days,
                COUNT(CASE WHEN a.status = 'sick' THEN 1 END) as sick_days,
                COUNT(CASE WHEN a.status = 'permit' THEN 1 END) as permit_days,
                CASE 
                    WHEN COUNT(a.id) = 0 THEN 0
                    ELSE ROUND((COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)), 2)
                END as attendance_percentage,
                MAX(a.attendance_date) as last_attendance_date
            FROM users u
            INNER JOIN class_students cs ON u.id = cs.student_id
            INNER JOIN classes c ON cs.class_id = c.id
            LEFT JOIN attendances a ON u.id = a.student_id 
                AND a.attendance_date >= ?
                AND a.school_id = ?
            WHERE u.school_id = ? 
                AND u.role_type = 'student' 
                AND u.is_active = true
                AND cs.status = 'active'
            GROUP BY u.id, u.name, u.email, c.name, c.grade_level
            HAVING attendance_percentage < ?
            ORDER BY attendance_percentage ASC, alpha_days DESC
            LIMIT 20
        ", [
            $thirtyDaysAgo->format('Y-m-d'),
            $schoolId,
            $schoolId,
            self::THRESHOLD_CRITICAL,
        ]);

        return array_map(function ($student) {
            return [
                'student_id' => $student->student_id,
                'student_name' => $student->student_name,
                'email' => $student->email,
                'class_name' => $student->class_name,
                'grade_level' => $student->grade_level,
                'attendance_stats' => [
                    'present_days' => (int) $student->present_days,
                    'total_days' => (int) $student->total_days,
                    'alpha_days' => (int) $student->alpha_days,
                    'sick_days' => (int) $student->sick_days,
                    'permit_days' => (int) $student->permit_days,
                    'attendance_percentage' => (float) $student->attendance_percentage,
                ],
                'last_attendance_date' => $student->last_attendance_date,
                'days_since_last_attendance' => $student->last_attendance_date
                    ? Carbon::parse($student->last_attendance_date)->diffInDays(now()->timezone($tz))
                    : null,
                'risk_level' => 'critical',
                'action_required' => $this->getActionRequired($student->attendance_percentage, $student->alpha_days),
            ];
        }, $criticalStudents);
    }

    /**
     * Get summary statistics
     */
    public function getSummaryStats(int $schoolId): array
    {
        $tz = \App\Models\School::find($schoolId)?->timezone ?? config("app.timezone");
        $thirtyDaysAgo = now()->timezone($tz)->subDays(30);

        $stats = DB::select("
            WITH school_stats AS (
                SELECT 
                    COUNT(DISTINCT u.id) as total_active_students,
                    COUNT(DISTINCT c.id) as total_classes,
                    COUNT(DISTINCT a.attendance_date) as total_school_days,
                    AVG(CASE 
                        WHEN COUNT(a.id) = 0 THEN 0
                        ELSE (COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id))
                    END) as avg_attendance_rate
                FROM users u
                INNER JOIN class_students cs ON u.id = cs.student_id
                INNER JOIN classes c ON cs.class_id = c.id
                LEFT JOIN attendances a ON u.id = a.student_id 
                    AND a.attendance_date >= ?
                    AND a.school_id = ?
                WHERE u.school_id = ? 
                    AND u.role_type = 'student' 
                    AND u.is_active = true
                    AND cs.status = 'active'
                GROUP BY u.id
            )
            SELECT 
                MAX(total_active_students) as total_active_students,
                MAX(total_classes) as total_classes,
                MAX(total_school_days) as total_school_days,
                ROUND(AVG(avg_attendance_rate), 2) as school_avg_attendance_rate
            FROM school_stats
        ", [
            $thirtyDaysAgo->format('Y-m-d'),
            $schoolId,
            $schoolId,
        ]);

        $stat = $stats[0] ?? null;

        return [
            'total_active_students' => (int) ($stat->total_active_students ?? 0),
            'total_classes' => (int) ($stat->total_classes ?? 0),
            'total_school_days' => (int) ($stat->total_school_days ?? 0),
            'school_avg_attendance_rate' => (float) ($stat->school_avg_attendance_rate ?? 0),
            'analysis_period' => '30 days',
            'analysis_start_date' => $thirtyDaysAgo->format('Y-m-d'),
            'analysis_end_date' => now()->timezone($tz)->format('Y-m-d'),
        ];
    }

    /**
     * Calculate percentage with null safety
     */
    private function calculatePercentage($value, $total): float
    {
        if ($total == 0) {
            return 0.0;
        }

        return round(($value / $total) * 100, 2);
    }

    /**
     * Get students by specific risk level
     */
    public function getStudentsByRiskLevel(int $schoolId, string $riskLevel, ?int $classId = null, int $limit = 20): array
    {
        $tz = \App\Models\School::find($schoolId)?->timezone ?? config("app.timezone");
        $thirtyDaysAgo = now()->timezone($tz)->subDays(30);
        $parentWarningService = app(ParentEarlyWarningService::class);
        // Build threshold conditions based on risk level
        $thresholdCondition = match ($riskLevel) {
            'critical' => 'attendance_percentage < '.self::THRESHOLD_CRITICAL,
            'high' => 'attendance_percentage >= '.self::THRESHOLD_CRITICAL.' AND attendance_percentage < '.self::THRESHOLD_HIGH,
            'medium' => 'attendance_percentage >= '.self::THRESHOLD_HIGH.' AND attendance_percentage < '.self::THRESHOLD_MEDIUM,
            'low' => 'attendance_percentage >= '.self::THRESHOLD_MEDIUM,
            default => 'attendance_percentage >= 0'
        };
        $classFilter = $classId ? "AND cs.class_id = {$classId}" : '';
        $students = DB::select("
            SELECT 
                u.id as student_id,
                u.name as student_name,
                u.email,
                c.name as class_name,
                c.grade_level,
                COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) as present_days,
                COUNT(a.id) as total_days,
                COUNT(CASE WHEN a.status = 'alpha' THEN 1 END) as alpha_days,
                COUNT(CASE WHEN a.status = 'sick' THEN 1 END) as sick_days,
                COUNT(CASE WHEN a.status = 'permit' THEN 1 END) as permit_days,
                CASE 
                    WHEN COUNT(a.id) = 0 THEN 0
                    ELSE ROUND((COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)), 2)
                END as attendance_percentage,
                MAX(a.attendance_date) as last_attendance_date
            FROM users u
            INNER JOIN class_students cs ON u.id = cs.student_id
            INNER JOIN classes c ON cs.class_id = c.id
            LEFT JOIN attendances a ON u.id = a.student_id 
                AND a.attendance_date >= ?
                AND a.school_id = ?
            WHERE u.school_id = ? 
                AND u.role_type = 'student' 
                AND u.is_active = true
                AND cs.status = 'active'
                {$classFilter}
            GROUP BY u.id, u.name, u.email, c.name, c.grade_level
            HAVING {$thresholdCondition}
            ORDER BY attendance_percentage ASC
            LIMIT ?
        ", [
            $thirtyDaysAgo->format('Y-m-d'),
            $schoolId,
            $schoolId,
            $limit,
        ]);
        // Integrasi notifikasi ke orang tua untuk risk level medium/high
        if (in_array($riskLevel, ['medium', 'high'])) {
            foreach ($students as $student) {
                $studentModel = User::find($student->student_id);
                if ($studentModel) {
                    $parentWarningService->triggerEarlyWarning($studentModel, $riskLevel);
                }
            }
        }

        return array_map(function ($student) use ($riskLevel) {
            return [
                'student_id' => $student->student_id,
                'student_name' => $student->student_name,
                'email' => $student->email,
                'class_name' => $student->class_name,
                'grade_level' => $student->grade_level,
                'attendance_stats' => [
                    'present_days' => (int) $student->present_days,
                    'total_days' => (int) $student->total_days,
                    'alpha_days' => (int) $student->alpha_days,
                    'sick_days' => (int) $student->sick_days,
                    'permit_days' => (int) $student->permit_days,
                    'attendance_percentage' => (float) $student->attendance_percentage,
                ],
                'last_attendance_date' => $student->last_attendance_date,
                'days_since_last_attendance' => $student->last_attendance_date
                    ? Carbon::parse($student->last_attendance_date)->diffInDays(now()->timezone($tz))
                    : null,
                'risk_level' => $riskLevel,
                'action_required' => $this->getActionRequired($student->attendance_percentage, $student->alpha_days),
            ];
        }, $students);
    }

    /**
     * Get risk trend data for specific period
     */
    public function getRiskTrendData(int $schoolId, int $days = 30): array
    {
        $tz = \App\Models\School::find($schoolId)?->timezone ?? config("app.timezone");
        return $this->getRiskTrend30Days($schoolId); // Reuse existing method
    }

    /**
     * Get export data for risk overview
     */
    public function getExportData(int $schoolId, bool $includeDetails = false): array
    {
        $tz = \App\Models\School::find($schoolId)?->timezone ?? config("app.timezone");
        $overview = $this->getRiskOverview($schoolId);

        $exportData = [
            'school_id' => $schoolId,
            'generated_at' => now()->toISOString(),
            'summary' => $overview['summary_stats'],
            'risk_distribution' => $overview['total_students_by_risk'],
            'high_risk_classes' => $overview['classes_with_high_risk'],
        ];

        if ($includeDetails) {
            $exportData['critical_students'] = $overview['critical_students'];
            $exportData['trend_data'] = $overview['risk_trend_30_days'];
        }

        return $exportData;
    }

    /**
     * Get recommended action based on attendance
     */
    private function getActionRequired(float $attendancePercentage, int $alphaDays): string
    {
        if ($attendancePercentage < 30) {
            return 'Panggil orang tua segera - Risiko putus sekolah';
        } elseif ($attendancePercentage < 50) {
            return 'Konseling intensif - Buat rencana perbaikan';
        } elseif ($alphaDays > 10) {
            return 'Investigasi penyebab - Koordinasi dengan wali kelas';
        } else {
            return 'Monitoring ketat - Motivasi siswa';
        }
    }
}
