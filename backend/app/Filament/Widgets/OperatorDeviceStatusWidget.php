<?php

namespace App\Filament\Widgets;

use App\Models\Device;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class OperatorDeviceStatusWidget extends BaseWidget
{
    protected static ?string $heading = 'Status Perangkat';

    protected static ?int $sort = 4;

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

        $devices = collect();
        if ($schoolId) {
            $devices = Device::where('school_id', $schoolId)
                ->where('is_active', true)
                ->orderBy('last_ping_at', 'desc')
                ->get()
                ->map(function ($device) {
                    // Auto-update status online
                    $device->updateOnlineStatus();

                    $lastPing = $device->last_ping_at
                        ? $device->last_ping_at->diffForHumans()
                        : 'Tidak pernah';

                    return [
                        'name' => $device->name,
                        'sn' => $device->sn,
                        'type' => $device->type,
                        'location' => $device->location ?? '-',
                        'is_online' => $device->is_online,
                        'last_ping' => $lastPing,
                        'model' => $device->model ?? '-',
                    ];
                });
        }

        return $table
            ->query(
                Device::where('school_id', $schoolId ?? 0)->whereRaw('1 = 0')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Perangkat')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sn')
                    ->label('Serial Number')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('location')
                    ->label('Lokasi')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_online')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-wifi')
                    ->falseIcon('heroicon-o-wifi-slash')
                    ->trueColor('success')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('last_ping')
                    ->label('Ping Terakhir')
                    ->sortable(),
                Tables\Columns\TextColumn::make('model')
                    ->label('Model')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->data($devices)
            ->defaultSort('is_online', 'desc')
            ->paginated(false);
    }
}
