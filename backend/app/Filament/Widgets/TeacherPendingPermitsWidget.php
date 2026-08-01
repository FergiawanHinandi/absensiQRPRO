<?php

namespace App\Filament\Widgets;

use App\Models\Permit;
use App\Models\StudentPermission;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TeacherPendingPermitsWidget extends BaseWidget
{
    protected static ?string $heading = 'Izin Siswa Menunggu Persetujuan';

    protected int | string | array $columnSpan = 'full';

    protected function getListeners(): array
    {
        return [
            'academicYearUpdated' => ['$refresh'],
        ];
    }

    public function table(Table $table): Table
    {
        $schoolId = auth()->user()?->school_id;

        $permits = collect();
        if ($schoolId) {
            // Cari izin yang masih pending — menggunakan Permit model atau StudentPermission
            $permits = Permit::where('school_id', $schoolId)
                ->where('status', 'Pending')
                ->with(['student:id,name', 'submitter:id,name'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(function ($permit) {
                    return [
                        'id' => $permit->id,
                        'student_name' => $permit->student?->name ?? 'Unknown',
                        'type' => $permit->type,
                        'date' => $permit->date?->isoFormat('D MMM YYYY'),
                        'end_date' => $permit->end_date?->isoFormat('D MMM YYYY'),
                        'notes' => $permit->notes ?? '-',
                        'submitted_by' => $permit->submitter?->name ?? '-',
                        'created_at' => $permit->created_at?->diffForHumans(),
                    ];
                });

            // Fallback ke StudentPermission jika tidak ada data di Permit
            if ($permits->isEmpty()) {
                $permits = StudentPermission::where('school_id', $schoolId)
                    ->where('status', 'pending')
                    ->with(['student:id,name'])
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get()
                    ->map(function ($p) {
                        return [
                            'id' => $p->id,
                            'student_name' => $p->student?->name ?? 'Unknown',
                            'type' => $p->type ?? 'Izin',
                            'date' => $p->start_date?->isoFormat('D MMM YYYY'),
                            'end_date' => $p->end_date?->isoFormat('D MMM YYYY'),
                            'notes' => $p->reason ?? '-',
                            'submitted_by' => '-',
                            'created_at' => $p->created_at?->diffForHumans(),
                        ];
                    });
            }
        }

        return $table
            ->query(
                Permit::where('school_id', $schoolId ?? 0)->whereRaw('1 = 0')
            )
            ->columns([
                Tables\Columns\TextColumn::make('student_name')
                    ->label('Siswa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn ($state) => $state === 'Sakit' ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->sortable(),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Alasan')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record['notes'] ?? ''),
                Tables\Columns\TextColumn::make('submitted_by')
                    ->label('Diajukan Oleh')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->sortable(),
            ])
            ->data($permits)
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->emptyStateHeading('Tidak ada izin menunggu')
            ->emptyStateDescription('Semua izin sudah diproses.')
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (array $data, $record) {
                        $permit = Permit::findOrFail($record['id']);
                        $permit->approve(auth()->id());
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        \Filament\Forms\Components\Textarea::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->required(),
                    ])
                    ->action(function (array $data, $record) {
                        $permit = Permit::findOrFail($record['id']);
                        $permit->reject(auth()->id(), $data['rejection_reason']);
                    }),
            ]);
    }
}
