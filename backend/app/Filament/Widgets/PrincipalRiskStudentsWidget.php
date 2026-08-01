<?php

namespace App\Filament\Widgets;

use App\Models\AcademicYear;
use App\Models\User;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PrincipalRiskStudentsWidget extends BaseWidget
{
    protected static ?string $heading = 'Siswa dengan Kehadiran Rendah';

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

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
        return [
            'start' => Carbon::now()->subDays(30)->toDateString(),
            'end' => Carbon::today()->toDateString(),
        ];
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $schoolId = $user?->school_id;
        $dateRange = $this->getDateRange();

        $students = collect();
        if ($schoolId) {
            $students = User::where('school_id', $schoolId)
                ->where('role_type', 'student')
                ->where('is_active', true)
                ->with(['classStudents' => function ($q) {
                    $q->where('status', 'active');
                }, 'classStudents.class:id,name'])
                ->withCount(['attendances as total_days' => function ($q) use ($dateRange) {
                    $q->whereBetween('attendance_date', [$dateRange['start'], $dateRange['end']]);
                }])
                ->withCount(['attendances as present_days' => function ($q) use ($dateRange) {
                    $q->whereBetween('attendance_date', [$dateRange['start'], $dateRange['end']])
                      ->whereIn('state', ['checked_in', 'checked_out', 'approved']);
                }])
                ->withCount(['attendances as late_days' => function ($q) use ($dateRange) {
                    $q->whereBetween('attendance_date', [$dateRange['start'], $dateRange['end']])
                      ->where('state', 'checked_in');
                }])
                ->get()
                ->filter(fn ($s) => $s->total_days > 0
                    && ($s->present_days / $s->total_days * 100) < 75)
                ->map(function ($s) {
                    $rate = round(($s->present_days / $s->total_days) * 100, 1);
                    return [
                        'student_name' => $s->name,
                        'class_name' => $s->classStudents->first()?->class?->name ?? '-',
                        'rate' => $rate,
                        'present_days' => $s->present_days,
                        'late_days' => $s->late_days,
                        'total_days' => $s->total_days,
                        'risk' => $rate < 60 ? 'Tinggi' : 'Sedang',
                    ];
                })
                ->sortBy('rate')
                ->take(15)
                ->values();
        }

        return $table
            ->query(
                User::where('school_id', $schoolId ?? 0)->whereRaw('1 = 0')
            )
            ->columns([
                Tables\Columns\TextColumn::make('student_name')
                    ->label('Nama Siswa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('class_name')
                    ->label('Kelas')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_days')
                    ->label('Hari')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('present_days')
                    ->label('Hadir')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate')
                    ->label('Rate %')
                    ->numeric(decimalPlaces: 1)
                    ->color(fn ($state) => $state < 60 ? 'danger' : 'warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('risk')
                    ->label('Risiko')
                    ->badge()
                    ->color(fn ($state) => $state === 'Tinggi' ? 'danger' : 'warning'),
            ])
            ->data($students)
            ->defaultSort('rate', 'asc')
            ->paginated(false);
    }
}
