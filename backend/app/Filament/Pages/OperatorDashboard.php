<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\OperatorDeviceStatusWidget;
use App\Filament\Widgets\OperatorRecentActivityWidget;
use App\Filament\Widgets\OperatorStatsWidget;
use App\Filament\Widgets\OperatorTodayAttendanceWidget;
use App\Exports\SchoolPerClassExport;
use App\Models\AcademicYear;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

class OperatorDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Dashboard Operator';

    protected static ?string $title = 'Dashboard Operator Sekolah';

    protected static ?string $slug = 'operator-dashboard';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.operator-dashboard';

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
            OperatorStatsWidget::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            OperatorRecentActivityWidget::class,
            OperatorDeviceStatusWidget::class,
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
        return $user->hasRole('school_admin') || $user->hasRole('super_admin');
    }

    // ── Quick Actions ──

    public function getHeaderActions(): array
    {
        return [
            Action::make('manageStudents')
                ->label('Manajemen Siswa')
                ->icon('heroicon-o-academic-cap')
                ->color('info')
                ->url(fn () => \App\Filament\Resources\StudentResource::getUrl('index')),
            Action::make('manageTeachers')
                ->label('Manajemen Guru')
                ->icon('heroicon-o-user-group')
                ->color('success')
                ->url(fn () => \App\Filament\Resources\TeacherResource::getUrl('index')),
            Action::make('manageClasses')
                ->label('Manajemen Kelas')
                ->icon('heroicon-o-building-library')
                ->color('warning')
                ->url(fn () => \App\Filament\Resources\ClassResource::getUrl('index')),
            Action::make('manageDevices')
                ->label('Perangkat')
                ->icon('heroicon-o-computer-desktop')
                ->color('gray')
                ->url(fn () => \App\Filament\Resources\DeviceResource::getUrl('index')),
            $this->exportPdfAction(),
            $this->exportExcelAction(),
        ];
    }

    // ── Quick Stats ──

    public function getQuickStats(): array
    {
        $schoolId = auth()->user()?->school_id;
        if (! $schoolId) {
            return [];
        }

        return [
            'totalStudents' => \App\Models\User::where('school_id', $schoolId)
                ->where('role_type', 'student')->where('is_active', true)->count(),
            'totalTeachers' => \App\Models\User::where('school_id', $schoolId)
                ->whereIn('role_type', ['teacher', 'homeroom_teacher'])->where('is_active', true)->count(),
            'totalClasses' => \App\Models\ClassModel::where('school_id', $schoolId)
                ->where('is_active', true)->count(),
            'totalDevices' => \App\Models\Device::where('school_id', $schoolId)
                ->where('is_active', true)->count(),
            'pendingPermits' => \App\Models\StudentPermission::where('school_id', $schoolId)
                ->where('status', 'pending')->count(),
        ];
    }

    // ── EKSPOR PDF ──

    public function getReportData(): array
    {
        $schoolId = auth()->user()?->school_id;
        $academicYearId = $this->academicYearId;

        if (! $schoolId || ! $academicYearId) {
            return [];
        }

        $school = \App\Models\School::find($schoolId);
        $academicYear = AcademicYear::find($academicYearId);

        if (! $academicYear) {
            return [];
        }

        $startDate = $academicYear->start_date->toDateString();
        $endDate = $academicYear->end_date->toDateString();

        $totalStudents = \App\Models\User::where('school_id', $schoolId)
            ->where('role_type', 'student')->where('is_active', true)->count();
        $totalTeachers = \App\Models\User::where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])->where('is_active', true)->count();
        $totalClasses = \App\Models\ClassModel::where('school_id', $schoolId)
            ->where('is_active', true)->count();
        $totalDevices = \App\Models\Device::where('school_id', $schoolId)
            ->where('is_active', true)->count();

        // Rekap per kelas
        $classSummaries = \App\Models\AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->with('class:id,name,grade_level')
            ->select('class_id')
            ->selectRaw('SUM(present_count) as present, SUM(late_count) as late, SUM(absent_count) as absent, SUM(sick_count) as sick, SUM(permit_count) as permit, AVG(total_students) as total')
            ->groupBy('class_id')
            ->get()
            ->map(fn ($s) => [
                'class_name' => $s->class?->name ?? 'Unknown',
                'total' => round($s->total),
                'present' => $s->present,
                'late' => $s->late,
                'absent' => $s->absent,
                'sick' => $s->sick ?? 0,
                'permit' => $s->permit ?? 0,
                'rate' => $s->total > 0 ? round((($s->present + $s->late) / ($s->total * 1)) * 100, 1) : 0,
            ]);

        // Tren 14 hari
        $trendEnd = min(now(), $academicYear->end_date);
        $trendStart = $trendEnd->copy()->subDays(13)->max($academicYear->start_date);

        $dailyTrend = \App\Models\AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$trendStart->toDateString(), $trendEnd->toDateString()])
            ->selectRaw('attendance_date, SUM(present_count + late_count) as attended, SUM(total_students) as total')
            ->groupBy('attendance_date')
            ->orderBy('attendance_date')
            ->get()
            ->map(fn ($d) => [
                'date' => $d->attendance_date->isoFormat('D MMM'),
                'attended' => $d->attended,
                'total' => $d->total,
                'rate' => $d->total > 0 ? round(($d->attended / $d->total) * 100, 1) : 0,
            ]);

        // Status perangkat
        $devices = \App\Models\Device::where('school_id', $schoolId)
            ->where('is_active', true)
            ->get()
            ->map(fn ($d) => [
                'name' => $d->name,
                'sn' => $d->sn,
                'location' => $d->location ?? '-',
                'is_online' => $d->is_online,
                'last_ping' => $d->last_ping_at ? $d->last_ping_at->diffForHumans() : 'Tidak pernah',
            ]);

        return [
            'school' => $school,
            'academicYear' => $academicYear,
            'generated_at' => now()->isoFormat('D MMMM YYYY, HH:mm'),
            'totalStudents' => $totalStudents,
            'totalTeachers' => $totalTeachers,
            'totalClasses' => $totalClasses,
            'totalDevices' => $totalDevices,
            'classSummaries' => $classSummaries,
            'dailyTrend' => $dailyTrend,
            'devices' => $devices,
            'operatorName' => auth()->user()->name,
        ];
    }

    public function exportPdfAction(): Action
    {
        return Action::make('exportPdf')
            ->label('Ekspor Laporan PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('success')
            ->action(function () {
                $data = $this->getReportData();
                $pdf = Pdf::loadView('pdf.operator-report', $data);
                $pdf->setPaper('a4', 'portrait');
                return response()->streamDownload(
                    fn () => print($pdf->output()),
                    'laporan-rekap-kehadiran-sekolah.pdf'
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
                $data = $this->getReportData();
                $export = new SchoolPerClassExport($data['classSummaries'] ?? collect());
                return Excel::download($export, 'rekap-kehadiran-per-kelas.xlsx');
            });
    }
}
