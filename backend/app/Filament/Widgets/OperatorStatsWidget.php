<?php

namespace App\Filament\Widgets;

use App\Models\ClassModel;
use App\Models\Device;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperatorStatsWidget extends BaseWidget
{
    protected function getColumns(): int
    {
        return 4;
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
        $schoolId = $user?->school_id;

        if (! $schoolId) {
            return [
                Stat::make('Sekolah', 'N/A')
                    ->description('Pilih sekolah terlebih dahulu')
                    ->color('warning'),
            ];
        }

        $totalStudents = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->count();

        $totalTeachers = User::where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->where('is_active', true)
            ->count();

        $totalClasses = ClassModel::where('school_id', $schoolId)
            ->where('is_active', true)
            ->count();

        $onlineDevices = Device::where('school_id', $schoolId)
            ->where('is_active', true)
            ->where('is_online', true)
            ->count();

        $totalDevices = Device::where('school_id', $schoolId)
            ->where('is_active', true)
            ->count();

        return [
            Stat::make('Total Siswa', number_format($totalStudents))
                ->description('Aktif')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('info')
                ->chart([10, 15, 12, 18, 14, 20, $totalStudents]),

            Stat::make('Total Guru', number_format($totalTeachers))
                ->description('Termasuk wali kelas')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success')
                ->chart([5, 8, 6, 9, 7, 10, $totalTeachers]),

            Stat::make('Jumlah Kelas', number_format($totalClasses))
                ->description('Rombel aktif')
                ->descriptionIcon('heroicon-m-building-library')
                ->color('warning')
                ->chart([4, 4, 6, 6, 8, 8, $totalClasses]),

            Stat::make('Perangkat', "{$onlineDevices} / {$totalDevices} Online")
                ->description($onlineDevices === $totalDevices ? 'Semua perangkat aktif' : 'Ada perangkat offline')
                ->descriptionIcon('heroicon-m-computer-desktop')
                ->color($onlineDevices === $totalDevices ? 'success' : ($onlineDevices > 0 ? 'warning' : 'danger'))
                ->chart([1, 1, 2, 1, 1, 2, $onlineDevices]),
        ];
    }
}
