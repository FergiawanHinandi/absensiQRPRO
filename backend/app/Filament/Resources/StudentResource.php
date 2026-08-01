<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StudentResource\Pages;
use App\Models\ClassModel;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StudentResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Manajemen Akademik';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && ($user->hasRole('school_admin') || $user->hasRole('super_admin'));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role_type', 'student')
            ->with('school', 'profile', 'class');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Data Siswa')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Lengkap')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('username')
                            ->label('Username / NISN Login')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->helperText('Digunakan untuk login siswa. Bisa diisi NISN.'),
                        Forms\Components\TextInput::make('nisn')
                            ->label('NISN (Nomor Induk Siswa Nasional)')
                            ->maxLength(20)
                            ->helperText('NISN resmi 10 digit dari Dapodik. Disimpan terenkripsi.')
                            ->afterStateHydrated(function ($component, $record) {
                                // Load NISN dari relasi profile saat edit
                                if ($record && $record->profile) {
                                    $component->state($record->profile->nisn);
                                }
                            }),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->nullable()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            // Password di-hash otomatis oleh model casting 'hashed'
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create'),
                        Forms\Components\Select::make('school_id')
                            ->label('Sekolah')
                            ->relationship('school', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn (callable $set) => $set('class_id', null)),
                        Forms\Components\Select::make('class_id')
                            ->label('Kelas')
                            ->options(function (callable $get) {
                                $schoolId = $get('school_id');
                                if (! $schoolId) {
                                    return [];
                                }
                                return ClassModel::where('school_id', $schoolId)
                                    ->where('is_active', true)
                                    ->orderBy('grade_level')
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn ($class) => [
                                        $class->id => "Kelas {$class->name} (Tingkat {$class->grade_level})",
                                    ]);
                            })
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Pilih kelas untuk menempatkan siswa.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ])->columns(2),

                Forms\Components\Section::make('Informasi Orang Tua')
                    ->schema([
                        Forms\Components\TextInput::make('parent_phone')
                            ->label('No. Telepon Orang Tua')
                            ->tel()
                            ->maxLength(20),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('profile.nisn')
                    ->label('NISN')
                    ->tooltip('NISN tidak bisa difilter karena terenkripsi')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('class.name')
                    ->label('Kelas')
                    ->getStateUsing(function ($record) {
                        $class = $record->class->first();
                        return $class?->name ?? '-';
                    }),
                Tables\Columns\TextColumn::make('school.name')
                    ->label('Sekolah')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_points')
                    ->label('Poin')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('current_streak')
                    ->label('Streak')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('school_id')
                    ->label('Sekolah')
                    ->relationship('school', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
                Tables\Filters\SelectFilter::make('kelas')
                    ->label('Kelas')
                    ->options(fn () => \App\Models\ClassModel::where('is_active', true)
                        ->when(auth()->user()?->school_id, fn ($q, $id) => $q->where('school_id', $id))
                        ->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => 
                        $query->when($data['value'], fn ($q, $classId) => 
                            $q->whereHas('class', fn ($q) => $q->where('classes.id', $classId))
                        )
                    ),
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
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'create' => Pages\CreateStudent::route('/create'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('role_type', 'student')->count();
    }
}
