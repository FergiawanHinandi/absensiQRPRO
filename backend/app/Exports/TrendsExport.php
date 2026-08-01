<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * TrendsExport
 *
 * Generates Excel spreadsheet for attendance trend reports.
 * Data is pre-computed by TrendService (not queried here)
 * to eliminate duplication with AttendanceTrendController.
 */
class TrendsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    private array $dailyTrend;
    private array $summary;
    private string $startDate;
    private string $endDate;
    private string $schoolName;
    private string $period;

    public function __construct(
        array $dailyTrend,
        array $summary,
        string $startDate,
        string $endDate,
        string $schoolName = 'Sekolah',
        string $period = '7d',
    ) {
        $this->dailyTrend = $dailyTrend;
        $this->summary = $summary;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->schoolName = $schoolName;
        $this->period = $period;
    }

    public function title(): string
    {
        return match ($this->period) {
            '7d' => 'Tren 7 Hari',
            '30d' => 'Tren 30 Hari',
            '90d' => 'Tren 90 Hari',
            default => 'Tren Kehadiran',
        };
    }

    public function headings(): array
    {
        return [
            ['LAPORAN TREN KEHADIRAN'],
            [$this->schoolName],
            ["Periode: {$this->startDate} s/d {$this->endDate}"],
            [],
            [
                'Tanggal',
                'Hari',
                'Hadir',
                'Terlambat',
                'Alpha',
                'Sakit/Izin',
                'Total Records',
                'Tingkat Kehadiran (%)',
            ],
        ];
    }

    public function collection(): Collection
    {
        $rows = collect();
        $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu'];

        foreach ($this->dailyTrend as $day) {
            $carbon = Carbon::parse($day['date']);
            $dayName = $dayNames[$carbon->dayOfWeek] ?? '-';
            $rows->push([
                $day['date'],
                $dayName,
                $day['present'],
                $day['late'],
                $day['absent'],
                $day['excused'],
                $day['total'],
                $day['rate'],
            ]);
        }

        // Summary row
        $summary = $this->summary;
        $totalAttended = $summary['total_present'] + $summary['total_late'];
        $avgRate = $summary['total_records'] > 0
            ? round(($totalAttended / $summary['total_records']) * 100, 1)
            : 0;

        $rows->push(['']);
        $rows->push([
            'TOTAL',
            '',
            $summary['total_present'],
            $summary['total_late'],
            $summary['total_absent'],
            $summary['total_excused'],
            $summary['total_records'],
            $avgRate,
        ]);

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 16,
                    'color' => ['argb' => 'FF1E40AF'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            2 => [
                'font' => ['size' => 12, 'color' => ['argb' => 'FF64748B']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            3 => [
                'font' => ['size' => 10, 'color' => ['argb' => 'FF94A3B8']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            5 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF1E40AF'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
            ],
        ];
    }
}
