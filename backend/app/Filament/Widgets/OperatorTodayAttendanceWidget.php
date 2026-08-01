<?php

namespace App\Filament\Widgets;

use App\Models\AttendanceDailyClassSummary;
use App\Models\User;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class OperatorTodayAttendanceWidget extends BaseWidget
{
    protected static ?string $heading = 'Absensi Hari Ini';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected function getListeners(): array
    {
        return [
            'academicYearUpdated' => ['$refresh'],
        ];
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $schoolId = $user?->school_id;
        $today = Carbon::today()->toDateString();

        $summaries = collect();
        if ($schoolId) {
            $summaries = AttendanceDailyClassSummary::where('school_id', $schoolId)
                ->where('attendance_date', $today)
                ->with('class:id,name,grade_level')
                ->select('class_id')
                ->selectRaw('
                    SUM(present_count) as present,
                    SUM(late_count) as late,
                    SUM(absent_count) as absent,
                    SUM(sick_count) as sick,
                    SUM(permit_count) as permit,
                    SUM(total_students) as total
                ')
                ->groupBy('class_id')
                ->get()
                ->map(function ($s) {
                    $attended = $s->present + $s->late;
                    $rate = $s->total > 0 ? round(($attended / $s->total) * 100, 1) : 0;
                    return [
                        'class_name' => $s->class?->name ?? 'Unknown',
                        'grade_level' => $s->class?->grade_level ?? '-',
                        'total' => $s->total,
                        'present' => $s->present,
                        'late' => $s->late,
                        'absent' => $s->absent,
                        'sick' => $s->sick,
                        'permit' => $s->permit,
                        'rate' => $rate,
                    ];
                })
                ->sortByDesc('rate');
        }

        return $table
            ->query(
                \App\Models\ClassModel::where('school_id', $schoolId ?? 0)->whereRaw('1 = 0')
            )
            ->columns([
                Tables\Columns\TextColumn::make('class_name')
                    ->label('Kelas')
                    ->sortable(),
                Tables\Columns\TextColumn::make('grade_level')
                    ->label('Tingkat')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total')
                    ->label('Siswa')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('present')
                    ->label('Hadir')
                    ->numeric()
                    ->color('success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('late')
                    ->label('Telat')
                    ->numeric()
                    ->color('warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('absent')
                    ->label('Alpha')
                    ->numeric()
                    ->color('danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sick')
                    ->label('Sakit')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('permit')
                    ->label('Izin')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate')
                    ->label('Rate %')
                    ->numeric(decimalPlaces: 1)
                    ->color(fn ($state) => $state >= 90 ? 'success' : ($state >= 75 ? 'warning' : 'danger'))
                    ->sortable(),
            ])
            ->data($summaries)
            ->defaultSort('rate', 'desc')
            ->paginated(false);
    }
}
