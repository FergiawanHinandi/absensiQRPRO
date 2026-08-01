<?php

namespace App\Filament\Widgets;

use App\Models\TeacherAttendance;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TeacherPersonalStatusWidget extends BaseWidget
{
    protected function getColumns(): int
    {
        return 3;
    }

    protected function getListeners(): array
    {
        return [
            'academicYearUpdated' => ['$refresh'],
        ];
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $today = Carbon::today()->toDateString();

        if (! $user) {
            return [
                Stat::make('Status', 'Belum Login')->color('warning'),
            ];
        }

        // Cek absensi hari ini
        $todayAttendance = TeacherAttendance::where('teacher_id', $user->id)
            ->whereDate('attendance_date', $today)
            ->first();

        // Statistik 30 hari terakhir
        $stats = TeacherAttendance::where('teacher_id', $user->id)
            ->whereBetween('attendance_date', [Carbon::now()->subDays(30)->toDateString(), $today])
            ->selectRaw('
                COUNT(*) as total_days,
                SUM(CASE WHEN status IN (\'present\', \'late\') THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = \'late\' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status IN (\'absent\', \'sick\', \'permit\') THEN 1 ELSE 0 END) as absent_days
            ')
            ->first();

        $rate = $stats && $stats->total_days > 0
            ? round(($stats->present_days / $stats->total_days) * 100, 1)
            : 0;

        // Status card
        if ($todayAttendance) {
            $checkIn = $todayAttendance->check_in_time
                ? Carbon::parse($todayAttendance->check_in_time)->format('H:i')
                : '-';
            $checkOut = $todayAttendance->check_out_time
                ? Carbon::parse($todayAttendance->check_out_time)->format('H:i')
                : 'Belum check-out';

            $statusLabel = match ($todayAttendance->status) {
                'present' => '✅ Sudah Check-In',
                'late' => '⚠️ Terlambat',
                'sick' => '🩺 Sakit',
                'permit' => '📋 Izin',
                default => '❌ Belum Check-In',
            };
            $statusColor = match ($todayAttendance->status) {
                'present' => 'success',
                'late' => 'warning',
                'sick', 'permit' => 'info',
                default => 'danger',
            };

            $attendStat = Stat::make('Status Hari Ini', $statusLabel)
                ->description("Check-in: {$checkIn} | Check-out: {$checkOut}")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($statusColor);
        } else {
            $attendStat = Stat::make('Status Hari Ini', '❌ Belum Absen')
                ->description('Silakan lakukan check-in')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger');
        }

        return [
            $attendStat,

            Stat::make('Kehadiran 30 Hari', "{$rate}%")
                ->description("{$stats->present_days} hadir dari {$stats->total_days} hari")
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($rate >= 90 ? 'success' : ($rate >= 75 ? 'warning' : 'danger'))
                ->chart([70, 75, 80, 85, 82, 88, $rate]),

            Stat::make('Total Kehadiran', "{$stats->present_days} Hari")
                ->description("{$stats->late_days}x terlambat | {$stats->absent_days}x tidak hadir")
                ->descriptionIcon('heroicon-m-clock')
                ->color($stats->late_days > 3 ? 'warning' : 'success'),
        ];
    }
}
