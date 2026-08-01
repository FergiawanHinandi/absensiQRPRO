<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Kehadiran Kelas - {{ $className ?? '' }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #1f2937;
            line-height: 1.5;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #7c3aed;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header h1 {
            font-size: 16px;
            color: #7c3aed;
            margin: 0 0 4px 0;
        }
        .header h2 {
            font-size: 13px;
            color: #374151;
            margin: 0 0 4px 0;
            font-weight: normal;
        }
        .header .meta {
            font-size: 9px;
            color: #6b7280;
            margin-top: 4px;
        }
        .info-box {
            background: #f5f3ff;
            border: 1px solid #ddd6fe;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 14px;
        }
        .info-grid {
            display: flex;
            justify-content: space-around;
        }
        .info-item {
            text-align: center;
        }
        .info-item .number {
            font-size: 18px;
            font-weight: bold;
            color: #7c3aed;
        }
        .info-item .label {
            font-size: 8px;
            color: #6b7280;
            margin-top: 2px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        table th {
            background: #7c3aed;
            color: white;
            padding: 6px 8px;
            font-size: 9px;
            text-align: left;
            font-weight: 600;
        }
        table td {
            padding: 5px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9px;
        }
        table tr:nth-child(even) td {
            background: #f9fafb;
        }
        .status-present { color: #059669; font-weight: bold; }
        .status-late { color: #d97706; font-weight: bold; }
        .status-absent { color: #dc2626; font-weight: bold; }
        .status-sick { color: #2563eb; }
        .status-permit { color: #8b5cf6; }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #7c3aed;
            margin: 16px 0 6px 0;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 3px;
        }
        .footer {
            text-align: center;
            font-size: 8px;
            color: #9ca3af;
            margin-top: 20px;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
        }
        .ttd {
            margin-top: 24px;
            text-align: right;
        }
        .ttd .name {
            margin-top: 50px;
            font-weight: bold;
        }
        .ttd .line {
            width: 200px;
            border-top: 1px solid #374151;
            margin-left: auto;
            margin-bottom: 4px;
        }
        .summary-row td {
            font-weight: bold;
            background: #f5f3ff !important;
            border-top: 2px solid #7c3aed;
        }
    </style>
</head>
<body>
    {{-- HEADER --}}
    <div class="header">
        <h1>LAPORAN KEHADIRAN SISWA PER KELAS</h1>
        <h2>Kelas {{ $className ?? '-' }} (Tingkat {{ $gradeLevel ?? '-' }})</h2>
        <div class="meta">
            Wali Kelas: {{ $teacherName ?? '-' }}<br>
            Tahun Ajaran: {{ $academicYear->name ?? '-' }} (Semester {{ $academicYear->semester ?? '-' }})<br>
            Periode: {{ $academicYear->start_date?->isoFormat('D MMM YYYY') ?? '-' }} — {{ $academicYear->end_date?->isoFormat('D MMM YYYY') ?? '-' }}<br>
            Dicetak: {{ $generated_at ?? now()->isoFormat('D MMMM YYYY, HH:mm') }}
        </div>
    </div>

    {{-- RINGKASAN KELAS --}}
    <div class="info-box">
        <div style="font-size: 10px; font-weight: bold; margin-bottom: 6px; text-align: center;">RINGKASAN KELAS</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="number">{{ $totalStudents ?? 0 }}</div>
                <div class="label">Jumlah Siswa</div>
            </div>
            <div class="info-item">
                <div class="number">{{ $totalDays ?? 0 }}</div>
                <div class="label">Hari Efektif</div>
            </div>
            <div class="info-item">
                <div class="number">
                    @php
                        $overallRate = 0;
                        if(isset($studentStats) && $studentStats->count() > 0) {
                            $totalPresent = $studentStats->sum('present_count') + $studentStats->sum('late_count');
                            $totalDays = $studentStats->first()?->total_days ?? 1;
                            $totalSiswa = $studentStats->count();
                            $overallRate = ($totalDays * $totalSiswa) > 0 ? round(($totalPresent / ($totalDays * $totalSiswa)) * 100, 1) : 0;
                        }
                    @endphp
                    {{ $overallRate }}%
                </div>
                <div class="label">Rata-rata Kehadiran</div>
            </div>
        </div>
    </div>

    {{-- REKAP ABSENSI SISWA --}}
    <div class="section-title">REKAP ABSENSI SISWA</div>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Siswa</th>
                <th>Hadir</th>
                <th>Telat</th>
                <th>Alpha</th>
                <th>Sakit</th>
                <th>Izin</th>
                <th>Rate</th>
            </tr>
        </thead>
        <tbody>
            @forelse($studentStats ?? [] as $idx => $row)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>{{ $row['student_name'] }}</td>
                    <td>{{ $row['present_count'] }}</td>
                    <td>{{ $row['late_count'] }}</td>
                    <td>{{ $row['absent_count'] }}</td>
                    <td>{{ $row['sick_count'] ?? 0 }}</td>
                    <td>{{ $row['permit_count'] ?? 0 }}</td>
                    <td class="{{ $row['rate'] >= 90 ? 'status-present' : ($row['rate'] >= 75 ? 'status-late' : 'status-absent') }}">
                        {{ $row['rate'] }}%
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align: center; color: #9ca3af;">Belum ada data absensi untuk periode ini</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- TREN 7 HARI TERAKHIR --}}
    @if(isset($dailyTrend) && $dailyTrend->count() > 0)
        <div class="section-title">TREN KEHADIRAN (7 HARI TERAKHIR)</div>
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Hadir</th>
                    <th>Telat</th>
                    <th>Alpha</th>
                    <th>Sakit</th>
                    <th>Izin</th>
                    <th>Rate</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dailyTrend as $day)
                    <tr>
                        <td>{{ $day['date'] }}</td>
                        <td>{{ $day['present'] }}</td>
                        <td>{{ $day['late'] }}</td>
                        <td>{{ $day['absent'] }}</td>
                        <td>{{ $day['sick'] }}</td>
                        <td>{{ $day['permit'] }}</td>
                        <td class="{{ $day['rate'] >= 90 ? 'status-present' : ($day['rate'] >= 75 ? 'status-late' : 'status-absent') }}">
                            {{ $day['rate'] }}%
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- TANDA TANGAN --}}
    <div class="ttd">
        <div>Makassar, {{ now()->isoFormat('D MMMM YYYY') }}</div>
        <div>Wali Kelas,</div>
        <div class="name">{{ $teacherName ?? '______________________' }}</div>
        <div class="line"></div>
        <div style="font-size: 8px; color: #6b7280;">
            Kelas {{ $className ?? '-' }}
        </div>
    </div>

    <div class="footer">
        Laporan ini digenerate secara otomatis dari Sistem Informasi Absensi Terpadu<br>
        {{ $schoolName ?? '' }} &bull; {{ now()->isoFormat('D MMMM YYYY HH:mm') }} WITA
    </div>
</body>
</html>
