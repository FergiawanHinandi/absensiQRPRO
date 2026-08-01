<?php

namespace App\Filament\Widgets;

use App\Models\StudentPermission;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class PrincipalPendingApprovalsWidget extends BaseWidget
{
    protected static ?string $heading = 'Izin Menunggu Persetujuan';

    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    protected function getListeners(): array
    {
        return [
            'academicYearUpdated' => '$refresh',
        ];
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $schoolId = $user?->school_id;

        $permissions = collect();
        if ($schoolId) {
            $permissions = StudentPermission::where('school_id', $schoolId)
                ->where('status', 'pending')
                ->with(['student:id,name', 'class:id,name'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'student_name' => $p->student?->name ?? 'Unknown',
                        'class_name' => $p->class?->name ?? '-',
                        'type' => ucfirst($p->type),
                        'reason' => $p->reason,
                        'start_date' => $p->start_date?->isoFormat('D MMM YYYY'),
                        'end_date' => $p->end_date?->isoFormat('D MMM YYYY'),
                        'created_at' => $p->created_at?->diffForHumans(),
                    ];
                });
        }

        return $table
            ->query(
                StudentPermission::where('school_id', $schoolId ?? 0)->whereRaw('1 = 0')
            )
            ->columns([
                Tables\Columns\TextColumn::make('student_name')
                    ->label('Siswa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('class_name')
                    ->label('Kelas')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn ($state) => $state === 'Sakit' ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record['reason']),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->sortable(),
            ])
            ->data($permissions)
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (array $data, $record) {
                        StudentPermission::findOrFail($record['id'])
                            ->update([
                                'status' => 'approved',
                                'approved_by' => auth()->id(),
                                'approved_at' => now(),
                            ]);
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        \Filament\Forms\Components\Textarea::make('reason')
                            ->label('Alasan Penolakan')
                            ->required(),
                    ])
                    ->action(function (array $data, $record) {
                        StudentPermission::findOrFail($record['id'])
                            ->update([
                                'status' => 'rejected',
                                'approved_by' => auth()->id(),
                                'approved_at' => now(),
                                'description' => $data['reason'],
                            ]);
                    }),
            ]);
    }
}
