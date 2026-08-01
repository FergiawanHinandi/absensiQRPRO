<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Kehadiran - {{ $school->name ?? 'Sekolah' }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #1f2937;
            line-height: 1.5;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #059669;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header h1 {
            font-size: 16px;
            color: #059669;
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
        .summary-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 14px;
        }
        .summary-grid {
            display: flex;
            justify-content: space-around;
        }
        .summary-item {
            text-align: center;
        }
        .summary-item .number {
            font-size: 18px;
            font-weight: bold;
            color: #059669;
        }
        .summary-item .label {
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
            background: #059669;
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
        .rate-high { color: #059669; font-weight: bold; }
        .rate-mid { color: #d97706; font-weight: bold; }
        .rate-low { color: #dc2626; font-weight: bold; }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #059669;
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
        .badge-online {
            color: #059669;
            font-weight: bold;
        }
        .badge-offline {
            color: #dc2626;
            font-weight: bold;
        }
    </style>
</head>
<body>
    {{-- HEADER --}}
    <div class="header">
        <h1>LAPORAN REKAP KEHADIRAN SISWA</h1>
        <h2>{{ $school->name ?? 'SD Negeri Unggulan Mongisidi 1' }}</h2>
        <div class="meta">
            Tahun Ajaran: {{ $academicYear->name ?? '-' }} (Semester {{ $academicYear->semester ?? '-' }})<br>
            Periode: {{ $academicYear->start_date?->isoFormat('D MMM YYYY') ?? '-' }} — {{ $academicYear->end_date?->isoFormat('D MMM YYYY') ?? '-' }}<br>
            Dicetak: {{ $generated_at ?? now()->isoFormat('D MMMM YYYY, HH:mm') }}
        </div>
    </div>

    {{-- RINGKASAN SEKOLAH --}}
    <div class="section-title">RINGKASAN SEKOLAH</div>
    <div class="summary-box">
        <div class="summary-grid">
            <div class="summary-item">
                <div class="number">{{ $totalStudents ?? 0 }}</div>
                <div class="label">Total Siswa Aktif</div>
            </div>
            <div class="summary-item">
                <div class="number">{{ $totalTeachers ?? 0 }}</div>
                <div class="label">Total Guru</div>
            </div>
            <div class="summary-item">
                <div class="number">{{ $totalClasses ?? 0 }}</div>
                <div class="label">Total Kelas</div>
            </div>
            <div class="summary-item">
                <div class="number">{{ $totalDevices ?? 0 }}</div>
                <div class="label">Total Perangkat</div>
            </div>
            <div class="summary-item">
                <div class="number">
                    @php
                        $overallRate = 0;
                        if(isset($classSummaries) && $classSummaries->count() > 0) {
                            $totalAttended = $classSummaries->sum('present') + $classSummaries->sum('late');
                            $totalAll = $classSummaries->sum('total');
                            $overallRate = $totalAll > 0 ? round(($totalAttended / $totalAll) * 100, 1) : 0;
                        }
                    @endphp
                    {{ $overallRate }}%
                </div>
                <div class="label">Rata-rata Kehadiran</div>
            </div>
        </div>
    </div>

    {{-- REKAP PER KELAS --}}
    <div class="section-title">REKAP KEHADIRAN PER KELAS</div>
    <table>
        <thead>
            <tr>
                <th>Kelas</th>
                <th>Siswa</th>
                <th>Hadir</th>
                <th>Telat</th>
                <th>Alpha</th>
                <th>Sakit</th>
                <th>Izin</th>
                <th>Rate</th>
            </tr>
        </thead>
        <tbody>
            @forelse($classSummaries ?? [] as $row)
                <tr>
                    <td>{{ $row['class_name'] }}</td>
                    <td>{{ $row['total'] }}</td>
                    <td>{{ $row['present'] }}</td>
                    <td>{{ $row['late'] }}</td>
                    <td>{{ $row['absent'] }}</td>
                    <td>{{ $row['sick'] ?? 0 }}</td>
                    <td>{{ $row['permit'] ?? 0 }}</td>
                    <td class="{{ $row['rate'] >= 90 ? 'rate-high' : ($row['rate'] >= 75 ? 'rate-mid' : 'rate-low') }}">
                        {{ $row['rate'] }}%
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align: center; color: #9ca3af;">Belum ada data kehadiran untuk periode ini</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- TREN HARIAN --}}
    <div class="section-title">TREN KEHADIRAN (14 HARI TERAKHIR)</div>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Hadir</th>
                <th>Rate</th>
                <th>Indikator</th>
            </tr>
        </thead>
        <tbody>
            @forelse($dailyTrend ?? [] as $day)
                <tr>
                    <td>{{ $day['date'] }}</td>
                    <td>{{ $day['attended'] ?? 0 }} / {{ $day['total'] ?? 0 }}</td>
                    <td class="{{ $day['rate'] >= 90 ? 'rate-high' : ($day['rate'] >= 75 ? 'rate-mid' : 'rate-low') }}">
                        {{ $day['rate'] }}%
                    </td>
                    <td>
                        @php
                            $barWidth = min($day['rate'], 100);
                            $barColor = $day['rate'] >= 90 ? '#059669' : ($day['rate'] >= 75 ? '#d97706' : '#dc2626');
                        @endphp
                        <div style="background: #e5e7eb; height: 8px; width: 100px; border-radius: 4px;">
                            <div style="background: {{ $barColor }}; height: 8px; width: {{ $barWidth }}px; border-radius: 4px;"></div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align: center; color: #9ca3af;">Belum ada data tren untuk periode ini</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- STATUS PERANGKAT --}}
    @if(isset($devices) && $devices->count() > 0)
        <div class="section-title">STATUS PERANGKAT</div>
        <table>
            <thead>
                <tr>
                    <th>Nama Perangkat</th>
                    <th>SN</th>
                    <th>Lokasi</th>
                    <th>Status</th>
                    <th>Ping Terakhir</th>
                </tr>
            </thead>
            <tbody>
                @foreach($devices as $dvc)
                    <tr>
                        <td>{{ $dvc['name'] }}</td>
                        <td>{{ $dvc['sn'] }}</td>
                        <td>{{ $dvc['location'] }}</td>
                        <td class="{{ $dvc['is_online'] ? 'badge-online' : 'badge-offline' }}">
                            {{ $dvc['is_online'] ? 'ONLINE' : 'OFFLINE' }}
                        </td>
                        <td>{{ $dvc['last_ping'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- TANDA TANGAN --}}
    <div class="ttd">
        <div>Makassar, {{ now()->isoFormat('D MMMM YYYY') }}</div>
        <div>Operator Sekolah,</div>
        <div class="name">{{ $operatorName ?? '______________________' }}</div>
        <div class="line"></div>
        <div style="font-size: 8px; color: #6b7280;">
            {{ $school->email ?? '' }} &bull; {{ $school->phone ?? '' }}
        </div>
    </div>

    <div class="footer">
        Laporan ini digenerate secara otomatis dari Sistem Informasi Absensi Terpadu<br>
        {{ $school->name ?? 'SD Negeri Unggulan Mongisidi 1' }} &bull; {{ now()->isoFormat('D MMMM YYYY HH:mm') }} WITA
    </div>
</body>
</html>
