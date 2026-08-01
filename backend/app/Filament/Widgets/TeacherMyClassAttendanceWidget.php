<?php

namespace App\Filament\Widgets;

use App\Models\Attendance;
use App\Models\AttendanceDailyClassSummary;
use App\Models\TeacherRole;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TeacherMyClassAttendanceWidget extends BaseWidget
{
    protected static ?string $heading = 'Absensi Siswa (Kelas Wali)';

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

        // Cari kelas wali
        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('is_homeroom_teacher', true)
            ->with('homeroomClass:id,name,grade_level')
            ->first();

        $classId = $teacherRole?->homeroomClass?->id;
        $className = $teacherRole?->homeroomClass?->name ?? '-';

        $today = Carbon::today()->toDateString();

        $students = collect();
        if ($classId) {
            // Ambil data summary hari ini untuk kelas tersebut
            $summary = AttendanceDailyClassSummary::where('class_id', $classId)
                ->where('attendance_date', $today)
                ->first();

            if ($summary) {
                // Detail siswa
                $students = Attendance::where('class_id', $classId)
                    ->where('attendance_date', $today)
                    ->with('student:id,name,photo_path')
                    ->get()
                    ->map(function ($att) {
                        $state = $att->state ?? 'init';
                        return [
                            'student_name' => $att->student?->name ?? 'Unknown',
                            'status' => match ((string) $state) {
                                'checked_in' => 'Hadir',
                                'checked_out' => 'Hadir (Pulang)',
                                'pending_approval' => 'Pending',
                                'approved' => 'Disetujui',
                                'rejected' => 'Ditolak',
                                'init' => 'Alpha',
                                default => 'Alpha',
                            },
                            'status_color' => match ((string) $state) {
                                'checked_in', 'checked_out', 'approved' => 'success',
                                'pending_approval' => 'warning',
                                'rejected' => 'danger',
                                default => 'gray',
                            },
                            'check_in' => $att->check_in_time
                                ? Carbon::parse($att->check_in_time)->format('H:i')
                                : '-',
                            'check_out' => $att->check_out_time
                                ? Carbon::parse($att->check_out_time)->format('H:i')
                                : '-',
                            'source' => $att->source ?? ($att->is_manual ? 'manual' : 'qr'),
                            'notes' => $att->notes ?? '-',
                        ];
                    });
            }
        }

        if (! $classId) {
            // Non-homeroom teacher: tampilkan pesan
            return $table
                ->query(
                    \App\Models\User::where('id', $user->id)->whereRaw('1 = 0')
                )
                ->columns([
                    Tables\Columns\TextColumn::make('empty')
                        ->label('Informasi')
                        ->getStateUsing(fn () => 'Anda bukan wali kelas. Absensi siswa tidak tersedia.'),
                ]);
        }

        return $table
            ->query(
                \App\Models\Attendance::where('class_id', $classId)->whereRaw('1 = 0')
            )
            ->columns([
                Tables\Columns\TextColumn::make('student_name')
                    ->label('Nama Siswa')
                    ->searchable()
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
                Tables\Columns\TextColumn::make('source')
                    ->label('Metode')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'qr' => 'QR',
                        'rfid' => 'RFID',
                        'manual' => 'Manual',
                        'adms' => 'Fingerprint',
                        default => ucfirst($state ?? '-'),
                    })
                    ->color(fn ($state) => match ($state) {
                        'qr' => 'success',
                        'rfid', 'adms' => 'info',
                        'manual' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->data($students)
            ->defaultSort('student_name')
            ->paginated(false)
            ->heading("Absensi Kelas {$className}");
    }
}
