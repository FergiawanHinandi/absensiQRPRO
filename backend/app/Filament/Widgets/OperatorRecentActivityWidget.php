<?php

namespace App\Filament\Widgets;

use App\Models\AuditLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class OperatorRecentActivityWidget extends BaseWidget
{
    protected static ?string $heading = 'Aktivitas Terbaru';

    protected static ?int $sort = 3;

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

        $logs = collect();
        if ($schoolId) {
            $logs = AuditLog::where('school_id', $schoolId)
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'user_name' => $log->user?->name ?? 'System',
                        'action' => $log->action,
                        'description' => $log->description,
                        'time' => $log->created_at->diffForHumans(),
                    ];
                });
        }

        return $table
            ->query(
                AuditLog::where('school_id', $schoolId ?? 0)->whereRaw('1 = 0')
            )
            ->columns([
                Tables\Columns\TextColumn::make('user_name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record['description'] ?? ''),
                Tables\Columns\TextColumn::make('time')
                    ->label('Waktu')
                    ->sortable(),
            ])
            ->data($logs)
            ->defaultSort('time', 'desc')
            ->paginated(false);
    }
}
