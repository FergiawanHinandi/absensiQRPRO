<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PrincipalAttendanceChartWidget;
use App\Filament\Widgets\PrincipalClassBreakdownWidget;
use App\Filament\Widgets\PrincipalPendingApprovalsWidget;
use App\Filament\Widgets\PrincipalRiskStudentsWidget;
use App\Filament\Widgets\PrincipalStatsOverviewWidget;
use App\Models\AcademicYear;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;

class PrincipalDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Dashboard Kepsek';

    protected static ?string $title = 'Dashboard Kepala Sekolah';

    protected static ?string $slug = 'principal-dashboard';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.principal-dashboard';

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

        // Init session filter agar widget bisa membaca
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
                    // Simpan ke session agar semua widget bisa akses
                    session(['filter_academic_year_id' => $this->academicYearId]);
                    // Dispatch event ke widget agar merespon filter
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
            PrincipalStatsOverviewWidget::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            PrincipalAttendanceChartWidget::class,
            PrincipalClassBreakdownWidget::class,
            PrincipalRiskStudentsWidget::class,
            PrincipalPendingApprovalsWidget::class,
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
        return $user->hasRole('principal') || $user->hasRole('super_admin');
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
        $totalClasses = \App\Models\ClassModel::where('school_id', $schoolId)
            ->where('is_active', true)->count();

        // Rekap per kelas selama tahun ajaran
        $classSummaries = \App\Models\AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->with('class:id,name,grade_level')
            ->select('class_id')
            ->selectRaw('
                SUM(present_count) as present,
                SUM(late_count) as late,
                SUM(absent_count) as absent,
                SUM(sick_count) as sick,
                SUM(permit_count) as permit,
                AVG(total_students) as total
            ')
            ->groupBy('class_id')
            ->get()
            ->map(fn ($s) => [
                'class_name' => $s->class?->name ?? 'Unknown',
                'total' => round($s->total),
                'present' => $s->present,
                'late' => $s->late,
                'absent' => $s->absent,
                'rate' => $s->total > 0 ? round((($s->present + $s->late) / ($s->total * 1)) * 100, 1) : 0,
                'sick' => $s->sick,
                'permit' => $s->permit,
            ]);

        // Tren 14 hari terakhir dari tahun ajaran
        $trendEnd = min(now(), $academicYear->end_date);
        $trendStart = $trendEnd->copy()->subDays(13)->max($academicYear->start_date);

        $dailyTrend = \App\Models\AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$trendStart->toDateString(), $trendEnd->toDateString()])
            ->selectRaw('
                attendance_date,
                SUM(present_count + late_count) as attended,
                SUM(total_students) as total
            ')
            ->groupBy('attendance_date')
            ->orderBy('attendance_date')
            ->get()
            ->map(fn ($d) => [
                'date' => $d->attendance_date->isoFormat('D MMM'),
                'rate' => $d->total > 0 ? round(($d->attended / $d->total) * 100, 1) : 0,
            ]);

        return [
            'school' => $school,
            'academicYear' => $academicYear,
            'generated_at' => now()->isoFormat('D MMMM YYYY, HH:mm'),
            'totalStudents' => $totalStudents,
            'totalClasses' => $totalClasses,
            'classSummaries' => $classSummaries,
            'dailyTrend' => $dailyTrend,
            'principalName' => auth()->user()->name,
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

                $pdf = Pdf::loadView('pdf.principal-report', $data);
                $pdf->setPaper('a4', 'portrait');

                return response()->streamDownload(
                    fn () => print($pdf->output()),
                    'laporan-kehadiran-sekolah.pdf'
                );
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->exportPdfAction(),
        ];
    }
}
