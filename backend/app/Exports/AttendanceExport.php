<?php

namespace App\Exports;

use App\Models\Attendance;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AttendanceExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    private $schoolId;

    private $startDate;

    private $endDate;

    private $classId;

    public function __construct(int $schoolId, $startDate, $endDate, $classId = null)
    {
        $this->schoolId = $schoolId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->classId = $classId;
    }

    public function query()
    {
        $query = Attendance::query()
            ->with(['student', 'schedule.class', 'schedule.subject'])
            ->where('school_id', $this->schoolId)
            ->whereBetween('attendance_date', [$this->startDate, $this->endDate]);

        if ($this->classId) {
            $query->whereHas('schedule', function ($q) {
                $q->where('class_id', $this->classId);
            });
        }

        return $query->orderBy('attendance_date', 'asc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Tanggal',
            'Siswa',
            'Kelas',
            'Mata Pelajaran',
            'Status',
            'Waktu Scan',
            'Metode',
        ];
    }

    public function map($attendance): array
    {
        return [
            $attendance->id,
            $attendance->attendance_date,
            $attendance->student->name ?? 'Unknown',
            $attendance->schedule->class->name ?? '-',
            $attendance->schedule->subject->name ?? '-',
            strtoupper($attendance->status),
            $attendance->check_in_time,
            $attendance->is_manual ? 'Manual' : 'QR Scan',
        ];
    }
}
