<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ClassAttendancePerStudentExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    protected $studentStats;

    public function __construct($studentStats)
    {
        $this->studentStats = $studentStats;
    }

    public function collection()
    {
        return collect($this->studentStats)->map(function ($row, $idx) {
            return [
                'No' => $idx + 1,
                'Nama Siswa' => $row['student_name'],
                'Hadir' => $row['present_count'],
                'Telat' => $row['late_count'],
                'Alpha' => $row['absent_count'],
                'Sakit' => $row['sick_count'],
                'Izin' => $row['permit_count'],
                'Rate %' => $row['rate'] . '%',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'No',
            'Nama Siswa',
            'Hadir',
            'Telat',
            'Alpha',
            'Sakit',
            'Izin',
            'Rate %',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('1')->getFont()->setBold(true);
        $sheet->getStyle('1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('7C3AED');
        $sheet->getStyle('1')->getFont()->getColor()->setRGB('FFFFFF');
        return [];
    }
}
