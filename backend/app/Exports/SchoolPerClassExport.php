<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SchoolPerClassExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    protected $classSummaries;

    public function __construct($classSummaries)
    {
        $this->classSummaries = $classSummaries;
    }

    public function collection()
    {
        return collect($this->classSummaries)->map(function ($row) {
            return [
                'Kelas' => $row['class_name'],
                'Total Siswa' => $row['total'],
                'Hadir' => $row['present'],
                'Telat' => $row['late'],
                'Alpha' => $row['absent'],
                'Sakit' => $row['sick'],
                'Izin' => $row['permit'],
                'Rate %' => $row['rate'] . '%',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Kelas',
            'Total Siswa',
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
            ->getStartColor()->setRGB('059669');
        $sheet->getStyle('1')->getFont()->getColor()->setRGB('FFFFFF');
        return [];
    }
}
