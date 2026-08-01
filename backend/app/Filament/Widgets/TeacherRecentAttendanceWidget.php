<?php

namespace App\Filament\Widgets;

use App\Models\TeacherAttendance;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TeacherRecentAttendanceWidget extends BaseWidget
{
    protected static ?string $heading = 'Riwayat Absensi Pribadi (5 Hari Terakhir)';

    protected int | string | array $columnSpan = 'full';

    protected function getListeners(): array
    {
        return [
            'academicYearUpdated' => ['$refresh'],
        ];
    }

    public function table(Table $table): Table
    {
        $userId = auth()->id();

        $attendances = collect();
        if ($userId) {
            $attendances = TeacherAttendance::where('teacher_id', $userId)
                ->orderByDesc('attendance_date')
                ->limit(5)
                ->get()
                ->map(function ($att) {
                    return [
                        'date' => $att->attendance_date->isoFormat('D MMM YYYY'),
                        'day_name' => $att->attendance_date->isoFormat('dddd'),
                        'check_in' => $att->check_in_time
                            ? Carbon::parse($att->check_in_time)->format('H:i')
                            : '-',
                        'check_out' => $att->check_out_time
                            ? Carbon::parse($att->check_out_time)->format('H:i')
                            : '-',
                        'status' => match ($att->status) {
                            'present' => 'Hadir',
                            'late' => 'Terlambat',
                            'sick' => 'Sakit',
                            'permit' => 'Izin',
                            'absent' => 'Alpha',
                            default => ucfirst($att->status ?? '-'),
                        },
                        'status_color' => match ($att->status) {
                            'present' => 'success',
                            'late' => 'warning',
                            'sick', 'permit' => 'info',
                            'absent' => 'danger',
                            default => 'gray',
                        },
                        'method' => $att->is_manual ? 'Manual' : 'QR/Fingerprint',
                        'notes' => $att->notes ?? '',
                    ];
                });
        }

        return $table
            ->query(
                TeacherAttendance::where('teacher_id', $userId ?? 0)->whereRaw('1 = 0')
            )
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->sortable(),
                Tables\Columns\TextColumn::make('day_name')
                    ->label('Hari')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($record) => $record['status_color']),
                Tables\Columns\TextColumn::make('check_in')
                    ->label('Masuk')
                    ->sortable(),
                Tables\Columns\TextColumn::make('check_out')
                    ->label('Pulang')
                    ->sortable(),
                Tables\Columns\TextColumn::make('method')
                    ->label('Metode')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(20)
                    ->tooltip(fn ($record) => $record['notes'] ?? '')
                    ->toggleable(),
            ])
            ->data($attendances)
            ->defaultSort('date', 'desc')
            ->paginated(false);
    }
}
