<?php

namespace App\Filament\Widgets;

use App\Models\AcademicYear;
use App\Models\AttendanceDailyClassSummary;
use App\Models\ClassModel;
use App\Models\StudentPermission;
use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class PrincipalStatsOverviewWidget extends BaseWidget
{
    protected function getColumns(): int
    {
        return 4;
    }

    protected function getListeners(): array
    {
        return [
            'academicYearUpdated' => '$refresh',
        ];
    }

    protected function getDateRange(): array
    {
        $academicYearId = session('filter_academic_year_id');
        if ($academicYearId) {
            $year = AcademicYear::find($academicYearId);
            if ($year) {
                return [
                    'start' => $year->start_date->toDateString(),
                    'end' => min(now(), $year->end_date)->toDateString(),
                ];
            }
        }
        // Fallback: 30 hari terakhir
        return [
            'start' => Carbon::now()->subDays(30)->toDateString(),
            'end' => Carbon::today()->toDateString(),
        ];
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $schoolId = $user?->school_id;

        if (! $schoolId) {
            return [
                Stat::make('Sekolah', 'N/A')
                    ->description('Pilih sekolah terlebih dahulu')
                    ->color('warning'),
            ];
        }

        $dateRange = $this->getDateRange();
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        // ── Total Siswa & Kelas ──
        $totalStudents = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->count();

        $totalClasses = ClassModel::where('school_id', $schoolId)
            ->where('is_active', true)
            ->count();

        // ── Ringkasan Absensi dalam rentang filter ──
        $periodSummary = AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->selectRaw('
                COALESCE(SUM(total_students), 0) as total,
                COALESCE(SUM(present_count), 0) as present,
                COALESCE(SUM(late_count), 0) as late,
                COALESCE(SUM(absent_count), 0) as absent
            ')
            ->first();

        $attended = ($periodSummary->present ?? 0) + ($periodSummary->late ?? 0);
        $totalRecords = $periodSummary->total ?? 0;
        $attendanceRate = $totalRecords > 0
            ? round(($attended / $totalRecords) * 100, 1)
            : 0;

        // ── Izin Pending ──
        $pendingPermissions = StudentPermission::where('school_id', $schoolId)
            ->where('status', 'pending')
            ->count();

        // ── Siswa Berisiko Tinggi ──
        $studentsWithAttendance = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->whereHas('attendances', function ($q) use ($startDate) {
                $q->where('attendance_date', '>=', $startDate);
            })
            ->withCount(['attendances as total_att' => function ($q) use ($startDate) {
                $q->where('attendance_date', '>=', $startDate);
            }])
            ->withCount(['attendances as present_att' => function ($q) use ($startDate) {
                $q->where('attendance_date', '>=', $startDate)
                  ->whereIn('state', ['checked_in', 'checked_out', 'approved']);
            }])
            ->get();

        $highRiskCount = $studentsWithAttendance
            ->filter(fn ($s) => $s->total_att > 0
                && ($s->present_att / $s->total_att * 100) < 60)
            ->count();

        // ── Tingkat Kehadiran per Tingkat ──
        $bestGrade = DB::table('attendance_daily_class_summaries')
            ->join('classes', 'attendance_daily_class_summaries.class_id', '=', 'classes.id')
            ->where('attendance_daily_class_summaries.school_id', $schoolId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->select('classes.grade_level')
            ->selectRaw('
                COALESCE(SUM(present_count + late_count), 0) as attended,
                COALESCE(SUM(total_students), 0) as total
            ')
            ->groupBy('classes.grade_level')
            ->get()
            ->map(fn ($item) => [
                'grade' => "Kelas {$item->grade_level}",
                'rate' => $item->total > 0
                    ? round(($item->attended / $item->total) * 100, 1)
                    : 0,
            ])
            ->sortByDesc('rate')
            ->first();

        $bestGradeLabel = $bestGrade
            ? "Terbaik: {$bestGrade['grade']} ({$bestGrade['rate']}%)"
            : 'Belum ada data';

        return [
            Stat::make('Total Siswa', number_format($totalStudents))
                ->description("{$totalClasses} Kelas Aktif")
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('info')
                ->chart([7, 3, 10, 5, 15, 7, $totalStudents]),

            Stat::make("Kehadiran ({$startDate} s/d {$endDate})", "{$attendanceRate}%")
                ->description("{$attended} hadir dari {$totalRecords} catatan")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($attendanceRate >= 90 ? 'success' : ($attendanceRate >= 75 ? 'warning' : 'danger'))
                ->chart([65, 70, 80, 75, 85, 82, $attendanceRate]),

            Stat::make('Izin Menunggu', "{$pendingPermissions}")
                ->description('Perlu persetujuan')
                ->descriptionIcon('heroicon-m-document-text')
                ->color($pendingPermissions > 0 ? 'warning' : 'success')
                ->chart([3, 5, 2, 4, 1, 3, $pendingPermissions]),

            Stat::make('Siswa Berisiko', "{$highRiskCount}")
                ->description($bestGradeLabel)
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($highRiskCount > 10 ? 'danger' : ($highRiskCount > 0 ? 'warning' : 'success'))
                ->chart([8, 12, 6, 10, 5, 7, $highRiskCount]),
        ];
    }
}
