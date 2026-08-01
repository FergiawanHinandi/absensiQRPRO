<?php

namespace App\Filament\Widgets;

use App\Models\AcademicYear;
use App\Models\AttendanceDailyClassSummary;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class PrincipalAttendanceChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Tren Kehadiran';

    protected static ?int $sort = 2;

    public ?string $filter = '30d';

    protected function getListeners(): array
    {
        return [
            'academicYearUpdated' => '$refresh',
        ];
    }

    protected function getFilters(): ?array
    {
        return [
            '7d' => '7 Hari',
            '30d' => '30 Hari',
            '90d' => '90 Hari',
        ];
    }

    protected function getDateRange(): array
    {
        $academicYearId = session('filter_academic_year_id');
        $days = match ($this->filter) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        if ($academicYearId) {
            $year = AcademicYear::find($academicYearId);
            if ($year) {
                $start = Carbon::now()->subDays($days - 1)->max($year->start_date);
                $end = Carbon::today()->min($year->end_date);
                return [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                ];
            }
        }

        return [
            'start' => Carbon::now()->subDays($days - 1)->toDateString(),
            'end' => Carbon::today()->toDateString(),
        ];
    }

    protected function getData(): array
    {
        $user = auth()->user();
        $schoolId = $user?->school_id;

        if (! $schoolId) {
            return $this->emptyData();
        }

        $dateRange = $this->getDateRange();

        $dailyData = AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$dateRange['start'], $dateRange['end']])
            ->selectRaw('
                attendance_date,
                COALESCE(SUM(present_count), 0) as present,
                COALESCE(SUM(late_count), 0) as late,
                COALESCE(SUM(absent_count), 0) as absent,
                COALESCE(SUM(sick_count), 0) as sick,
                COALESCE(SUM(permit_count), 0) as permit,
                COALESCE(SUM(total_students), 0) as total
            ')
            ->groupBy('attendance_date')
            ->orderBy('attendance_date')
            ->get()
            ->keyBy('attendance_date');

        $labels = [];
        $presentData = [];
        $absentData = [];
        $lateData = [];

        $start = Carbon::parse($dateRange['start']);
        $end = Carbon::parse($dateRange['end']);
        $totalDays = $start->diffInDays($end) + 1;

        for ($i = 0; $i < $totalDays; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $labels[] = Carbon::parse($date)->isoFormat('D MMM');

            $dayStats = $dailyData->get($date);
            $total = $dayStats?->total ?? 0;

            if ($total > 0) {
                $presentData[] = round((($dayStats->present + $dayStats->late) / $total) * 100, 1);
                $absentData[] = round(($dayStats->absent / $total) * 100, 1);
                $lateData[] = round(($dayStats->late / $total) * 100, 1);
            } else {
                $presentData[] = 0;
                $absentData[] = 0;
                $lateData[] = 0;
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Hadir %',
                    'data' => $presentData,
                    'backgroundColor' => '#22c55e',
                    'borderColor' => '#22c55e',
                    'tension' => 0.3,
                    'fill' => false,
                ],
                [
                    'label' => 'Terlambat %',
                    'data' => $lateData,
                    'backgroundColor' => '#eab308',
                    'borderColor' => '#eab308',
                    'tension' => 0.3,
                    'fill' => false,
                ],
                [
                    'label' => 'Alpha %',
                    'data' => $absentData,
                    'backgroundColor' => '#ef4444',
                    'borderColor' => '#ef4444',
                    'tension' => 0.3,
                    'fill' => false,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): ?array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'max' => 100,
                    'title' => [
                        'display' => true,
                        'text' => 'Persentase (%)',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
            'responsive' => true,
            'maintainAspectRatio' => false,
        ];
    }

    protected function getHeight(): ?string
    {
        return '300px';
    }

    private function emptyData(): array
    {
        return [
            'datasets' => [],
            'labels' => [],
        ];
    }
}
