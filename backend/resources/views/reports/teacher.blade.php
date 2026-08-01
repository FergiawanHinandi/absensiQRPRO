<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Laporan Kehadiran Guru</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header h2 { margin: 0; font-size: 18px; text-transform: uppercase; }
        .info { margin-bottom: 15px; }
        .info table { width: 100%; border: none; }
        .info td { padding: 3px 0; }
        .stats { margin-bottom: 20px; background-color: #f8f9fa; padding: 10px; border-radius: 4px; border: 1px solid #ddd; }
        .stats-table { width: 100%; text-align: center; border-collapse: collapse; }
        .stats-table td { padding: 5px; border-right: 1px solid #ddd; }
        .stats-table td:last-child { border-right: none; }
        .stats-table .label { font-size: 10px; color: #555; text-transform: uppercase; }
        .stats-table .value { font-size: 14px; font-weight: bold; margin-top: 3px; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .data-table th, .data-table td { border: 1px solid #000; padding: 6px; text-align: left; }
        .data-table th { background-color: #e9ecef; font-weight: bold; text-align: center; }
        .text-center { text-align: center; }
        .status-badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; color: #fff; }
        .bg-success { background-color: #28a745; }
        .bg-warning { background-color: #ffc107; color: #212529; }
        .bg-info { background-color: #17a2b8; }
        .bg-primary { background-color: #007bff; }
        .bg-danger { background-color: #dc3545; }
        .bg-secondary { background-color: #6c757d; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Laporan Kehadiran Siswa</h2>
    </div>

    <div class="info">
        <table>
            <tr>
                <td width="120"><strong>Nama Guru</strong></td>
                <td width="10">:</td>
                <td>{{ $teacher->name }}</td>
            </tr>
            <tr>
                <td><strong>Periode</strong></td>
                <td>:</td>
                <td>{{ $startDate->translatedFormat('d F Y') }} - {{ $endDate->translatedFormat('d F Y') }}</td>
            </tr>
        </table>
    </div>

    <div class="stats">
        <table class="stats-table">
            <tr>
                <td>
                    <div class="label">Total Siswa Hadir</div>
                    <div class="value">{{ $stats['total_hadir'] }}</div>
                </td>
                <td>
                    <div class="label">Terlambat</div>
                    <div class="value" style="color: #ffc107;">{{ $stats['total_terlambat'] }}</div>
                </td>
                <td>
                    <div class="label">Sakit</div>
                    <div class="value" style="color: #17a2b8;">{{ $stats['total_sakit'] }}</div>
                </td>
                <td>
                    <div class="label">Izin</div>
                    <div class="value" style="color: #007bff;">{{ $stats['total_izin'] }}</div>
                </td>
                <td>
                    <div class="label">Alpha</div>
                    <div class="value" style="color: #dc3545;">{{ $stats['total_alpha'] }}</div>
                </td>
                <td>
                    <div class="label">Kehadiran</div>
                    <div class="value">{{ number_format($stats['persentase_kehadiran'], 1) }}%</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th width="30">No</th>
                <th width="80">Tanggal</th>
                <th width="70">NIS</th>
                <th width="150">Nama Siswa</th>
                <th width="60">Kelas</th>
                <th width="100">Mapel</th>
                <th width="60">Waktu</th>
                <th width="60">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($attendances as $index => $attendance)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-center">{{ \Carbon\Carbon::parse($attendance->attendance_date)->format('d M y') }}</td>
                <td class="text-center">{{ $attendance->student->nis ?? '-' }}</td>
                <td>{{ $attendance->student->name ?? 'Unknown' }}</td>
                <td class="text-center">{{ $attendance->schedule->class->name ?? '-' }}</td>
                <td>{{ $attendance->schedule->subject->name ?? '-' }}</td>
                <td class="text-center">{{ $attendance->check_in_time ? \Carbon\Carbon::parse($attendance->check_in_time)->format('H:i') : '-' }}</td>
                <td class="text-center">
                    @if($attendance->status === 'present')
                        <span class="status-badge bg-success">Hadir</span>
                    @elseif($attendance->status === 'late')
                        <span class="status-badge bg-warning">Terlambat</span>
                    @elseif($attendance->status === 'sick')
                        <span class="status-badge bg-info">Sakit</span>
                    @elseif($attendance->status === 'permit')
                        <span class="status-badge bg-primary">Izin</span>
                    @elseif($attendance->status === 'alpha')
                        <span class="status-badge bg-danger">Alpha</span>
                    @else
                        <span class="status-badge bg-secondary">{{ ucfirst($attendance->status) }}</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
