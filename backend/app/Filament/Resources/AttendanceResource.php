<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceResource\Pages;
use App\Models\Attendance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Manajemen Akademik';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && ($user->hasRole('school_admin') || $user->hasRole('super_admin'));
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Data Absensi')
                    ->schema([
                        Forms\Components\Select::make('school_id')
                            ->label('Sekolah')
                            ->relationship('school', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('student_id')
                            ->label('Siswa')
                            ->relationship('student', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('schedule_id')
                            ->label('Jadwal')
                            ->relationship('schedule', 'id')
                            ->searchable()
                            ->preload(),
                        Forms\Components\DatePicker::make('attendance_date')
                            ->label('Tanggal')
                            ->required(),
                        Forms\Components\Select::make('state')
                            ->label('Status')
                            ->options([
                                'init' => 'Init (Absent)',
                                'checked_in' => 'Checked In',
                                'checked_out' => 'Checked Out',
                                'pending_approval' => 'Pending Approval',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->required(),
                        Forms\Components\TimePicker::make('check_in_time')
                            ->label('Jam Masuk'),
                        Forms\Components\TimePicker::make('check_out_time')
                            ->label('Jam Pulang'),
                        Forms\Components\Select::make('recorded_by')
                            ->label('Dicatat Oleh')
                            ->relationship('recorder', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->maxLength(65535),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.name')
                    ->label('Siswa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('attendance_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'init' => 'Alpha',
                        'checked_in' => 'Hadir',
                        'checked_out' => 'Hadir (Pulang)',
                        'pending_approval' => 'Pending',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'init' => 'danger',
                        'checked_in', 'checked_out', 'approved' => 'success',
                        'pending_approval' => 'warning',
                        'rejected' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('check_in_time')
                    ->label('Masuk')
                    ->time('H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('check_out_time')
                    ->label('Pulang')
                    ->time('H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('schedule.subject.name')
                    ->label('Mapel')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('school.name')
                    ->label('Sekolah')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')
                    ->label('Status')
                    ->options([
                        'init' => 'Alpha',
                        'checked_in' => 'Hadir',
                        'checked_out' => 'Hadir (Pulang)',
                        'pending_approval' => 'Pending',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                    ]),
                Tables\Filters\SelectFilter::make('school_id')
                    ->label('Sekolah')
                    ->relationship('school', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('attendance_date')
                    ->label('Tanggal')
                    ->form([
                        Forms\Components\DatePicker::make('date_from'),
                        Forms\Components\DatePicker::make('date_until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['date_from'], fn ($q, $d) => $q->whereDate('attendance_date', '>=', $d))
                            ->when($data['date_until'], fn ($q, $d) => $q->whereDate('attendance_date', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('attendance_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendances::route('/'),
            'create' => Pages\CreateAttendance::route('/create'),
            'edit' => Pages\EditAttendance::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
}
