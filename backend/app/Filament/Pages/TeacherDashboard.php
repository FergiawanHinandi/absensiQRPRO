<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\TeacherMyClassAttendanceWidget;
use App\Filament\Widgets\TeacherPendingPermitsWidget;
use App\Filament\Widgets\TeacherPersonalStatusWidget;
use App\Filament\Widgets\TeacherRecentAttendanceWidget;
use App\Exports\ClassAttendancePerStudentExport;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\AttendanceDailyClassSummary;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

class TeacherDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Dashboard Guru';

    protected static ?string $title = 'Dashboard Guru';

    protected static ?string $slug = 'teacher-dashboard';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.teacher-dashboard';

    public ?string $academicYearId = null;

    public function mount(): void
    {
        $schoolId = auth()->user()?->school_id;
        if ($schoolId) {
            $activeYear = AcademicYear::where('school_id', $schoolId)
                ->where('is_active', true)
                ->first();
            $this->academicYearId = (string) ($activeYear?->id ?? '');
        }

        if ($this->academicYearId) {
            session(['filter_academic_year_id' => $this->academicYearId]);
        }
    }

    protected function getFormSchema(): array
    {
        return [
            Select::make('academicYearId')
                ->label('Tahun Ajaran')
                ->options(fn () => $this->getAcademicYearOptions())
                ->searchable()
                ->preload()
                ->reactive()
                ->afterStateUpdated(function () {
                    session(['filter_academic_year_id' => $this->academicYearId]);
                    $this->dispatch('academicYearUpdated');
                }),
        ];
    }

    protected function getAcademicYearOptions(): array
    {
        $schoolId = auth()->user()?->school_id;
        if (! $schoolId) {
            return [];
        }

        return AcademicYear::where('school_id', $schoolId)
            ->orderByDesc('start_date')
            ->get()
            ->mapWithKeys(fn ($year) => [
                $year->id => "{$year->name} (Semester {$year->semester})" . ($year->is_active ? ' ⭐' : ''),
            ])
            ->toArray();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TeacherPersonalStatusWidget::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            TeacherMyClassAttendanceWidget::class,
            TeacherPendingPermitsWidget::class,
            TeacherRecentAttendanceWidget::class,
        ];
    }

    public function getColumns(): int | array
    {
        return [
            'md' => 4,
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        return $user->hasRole('teacher')
            || $user->hasRole('homeroom_teacher')
            || $user->hasRole('super_admin');
    }

    // ── Quick Actions ──

    public function getHeaderActions(): array
    {
        $homeroom = $this->getHomeroomInfo();

        $actions = [
            Action::make('scanQr')
                ->label('Scan QR Siswa')
                ->icon('heroicon-o-camera')
                ->color('success')
                ->url('/admin/attendance-scanner', shouldOpenInNewTab: true),
            Action::make('myAttendance')
                ->label('Riwayat Absensi Saya')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('info')
                ->url(fn () => \App\Filament\Resources\AttendanceResource::getUrl('index')),
            Action::make('pendingActions')
                ->label('Pengajuan Izin')
                ->icon('heroicon-o-document-text')
                ->color('warning')
                ->url(fn () => \App\Filament\Resources\PermitResource::getUrl('index')),
        ];

        // Export hanya untuk wali kelas yang memiliki kelas
        if ($homeroom) {
            $actions[] = $this->exportPdfAction();
            $actions[] = $this->exportExcelAction();
        }

        return $actions;
    }

    public function getHomeroomInfo(): ?array
    {
        $user = auth()->user();
        if (! $user || ! $user->hasRole('homeroom_teacher')) {
            return null;
        }

        $teacherRole = \App\Models\TeacherRole::where('teacher_id', $user->id)
            ->where('is_homeroom_teacher', true)
            ->with('homeroomClass:id,name,grade_level')
            ->first();

        if (! $teacherRole || ! $teacherRole->homeroomClass) {
            return null;
        }

        $class = $teacherRole->homeroomClass;
        return [
            'class_name' => $class->name,
            'grade_level' => $class->grade_level,
            'student_count' => $class->students()->count(),
            'class_id' => $class->id,
        ];
    }

    // ── EKSPOR PDF ──

    public function getClassReportData(): array
    {
        $user = auth()->user();
        $schoolId = $user?->school_id;
        $academicYearId = $this->academicYearId;
        $homeroom = $this->getHomeroomInfo();

        if (! $schoolId || ! $academicYearId || ! $homeroom) {
            return [];
        }

        $school = \App\Models\School::find($schoolId);
        $academicYear = AcademicYear::find($academicYearId);
        $classId = $homeroom['class_id'];

        if (! $academicYear) {
            return [];
        }

        $startDate = $academicYear->start_date->toDateString();
        $endDate = $academicYear->end_date->toDateString();

        // Total hari efektif (dari data absensi)
        $totalDays = AttendanceDailyClassSummary::where('class_id', $classId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->distinct('attendance_date')
            ->count('attendance_date');

        // Statistik per siswa — query state + status (late/sick/permit)
        $studentStats = Attendance::where('class_id', $classId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->with('student:id,name')
            ->selectRaw('student_id')
            ->selectRaw("
                SUM(CASE WHEN state IN ('checked_in', 'checked_out', 'approved') THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN state = 'init' AND status NOT IN ('sick', 'permit') THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick_count,
                SUM(CASE WHEN status = 'permit' THEN 1 ELSE 0 END) as permit_count
            ")
            ->groupBy('student_id')
            ->get()
            ->map(function ($att) use ($totalDays) {
                $total = $totalDays > 0 ? $totalDays : 1;
                $present = (int) ($att->present_count ?? 0);
                $late = (int) ($att->late_count ?? 0);
                $absent = (int) ($att->absent_count ?? 0);
                $sick = (int) ($att->sick_count ?? 0);
                $permit = (int) ($att->permit_count ?? 0);
                $rate = $total > 0 ? round((($present + $late) / $total) * 100, 1) : 0;
                return [
                    'student_name' => $att->student?->name ?? 'Unknown',
                    'present_count' => $present,
                    'late_count' => $late,
                    'absent_count' => $absent,
                    'sick_count' => $sick,
                    'permit_count' => $permit,
                    'rate' => $rate,
                ];
            })
            ->sortByDesc('rate');

        // Tren 7 hari terakhir
        $trendEnd = min(now(), $academicYear->end_date);
        $trendStart = $trendEnd->copy()->subDays(6)->max($academicYear->start_date);

        $dailyTrend = AttendanceDailyClassSummary::where('class_id', $classId)
            ->whereBetween('attendance_date', [$trendStart->toDateString(), $trendEnd->toDateString()])
            ->orderBy('attendance_date')
            ->get()
            ->map(function ($s) {
                $attended = ($s->present_count ?? 0) + ($s->late_count ?? 0);
                $total = $s->total_students > 0 ? $s->total_students : 1;
                return [
                    'date' => $s->attendance_date->isoFormat('D MMM'),
                    'present' => $s->present_count ?? 0,
                    'late' => $s->late_count ?? 0,
                    'absent' => $s->absent_count ?? 0,
                    'sick' => $s->sick_count ?? 0,
                    'permit' => $s->permit_count ?? 0,
                    'rate' => round(($attended / $total) * 100, 1),
                ];
            });

        return [
            'schoolName' => $school->name ?? 'SD Negeri Unggulan Mongisidi 1',
            'academicYear' => $academicYear,
            'generated_at' => now()->isoFormat('D MMMM YYYY, HH:mm'),
            'teacherName' => $user->name,
            'className' => $homeroom['class_name'],
            'gradeLevel' => $homeroom['grade_level'],
            'totalStudents' => $homeroom['student_count'],
            'totalDays' => $totalDays,
            'studentStats' => $studentStats,
            'dailyTrend' => $dailyTrend,
        ];
    }

    public function exportPdfAction(): Action
    {
        return Action::make('exportPdf')
            ->label('Ekspor Laporan PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('success')
            ->action(function () {
                $data = $this->getClassReportData();
                if (empty($data)) {
                    return;
                }
                $pdf = Pdf::loadView('pdf.teacher-report', $data);
                $pdf->setPaper('a4', 'portrait');
                return response()->streamDownload(
                    fn () => print($pdf->output()),
                    'laporan-kehadiran-kelas-' . ($data['className'] ?? 'wali') . '.pdf'
                );
            });
    }

    public function exportExcelAction(): Action
    {
        return Action::make('exportExcel')
            ->label('Ekspor Excel')
            ->icon('heroicon-o-table-cells')
            ->color('warning')
            ->action(function () {
                $data = $this->getClassReportData();
                if (empty($data) || empty($data['studentStats'])) {
                    return;
                }
                $export = new ClassAttendancePerStudentExport($data['studentStats']);
                $className = $data['className'] ?? 'kelas';
                return Excel::download($export, 'rekap-absensi-' . strtolower(str_replace(' ', '-', $className)) . '.xlsx');
            });
    }
}
