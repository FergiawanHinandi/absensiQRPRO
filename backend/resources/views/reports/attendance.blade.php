<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Absensi</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .header h2 {
            margin: 5px 0;
        }
        .info {
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table, th, td {
            border: 1px solid #333;
        }
        th {
            background-color: #f0f0f0;
            padding: 8px;
            text-align: left;
        }
        td {
            padding: 6px;
        }
        .footer {
            margin-top: 30px;
            text-align: right;
            font-size: 10px;
        }
        .status-present { color: green; font-weight: bold; }
        .status-late { color: orange; font-weight: bold; }
        .status-alpha { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <h2>{{ $school->name ?? 'Nama Sekolah' }}</h2>
        <p>{{ $school->address ?? '' }}</p>
        <h3>LAPORAN ABSENSI SISWA</h3>
    </div>

    <div class="info">
        <p><strong>Periode:</strong> {{ \Carbon\Carbon::parse($start_date)->format('d-m-Y') }} s/d {{ \Carbon\Carbon::parse($end_date)->format('d-m-Y') }}</p>
        <p><strong>Total Data:</strong> {{ $attendances->count() }} records</p>
    

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 12%;">Tanggal</th>
                <th style="width: 25%;">Nama Siswa</th>
                <th style="width: 18%;">Kelas</th>
                <th style="width: 18%;">Mata Pelajaran</th>
                <th style="width: 12%;">Waktu</th>
                <th style="width: 10%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($attendances as $index => $attendance)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $attendance->attendance_date }}</td>
                <td>{{ $attendance->student->name ?? '-' }}</td>
                <td>{{ $attendance->schedule->class->name ?? '-' }}</td>
                <td>{{ $attendance->schedule->subject->name ?? '-' }}</td>
                <td>{{ $attendance->check_in_time }}</td>
                <td class="status-{{ $attendance->status }}">{{ strtoupper($attendance->status) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="7" style="text-align: center;">Tidak ada data</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p>Dicetak pada: {{ $generated_at }}</p>
        <p>Dokumen ini digenerate otomatis oleh Sistem AbsensiQR Pro</p>
    </div>
</body>
</html>
