<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Tren Kehadiran</title>
    <style>
        @page {
            margin: 20mm 15mm;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            color: #1e293b;
            line-height: 1.5;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #1e40af;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #1e40af;
            font-size: 18pt;
            margin: 0 0 4px 0;
        }
        .header .school-name {
            font-size: 12pt;
            color: #64748b;
            margin: 0 0 2px 0;
        }
        .header .period {
            font-size: 9pt;
            color: #94a3b8;
            margin: 0;
        }
        .summary-cards {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            gap: 8px;
        }
        .summary-card {
            flex: 1;
            padding: 10px 8px;
            border-radius: 6px;
            text-align: center;
        }
        .summary-card .value {
            font-size: 18pt;
            font-weight: bold;
            margin: 2px 0;
        }
        .summary-card .label {
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .card-green { background: #ecfdf5; border: 1px solid #a7f3d0; }
        .card-green .value { color: #059669; }
        .card-amber { background: #fffbeb; border: 1px solid #fde68a; }
        .card-amber .value { color: #d97706; }
        .card-red { background: #fef2f2; border: 1px solid #fecaca; }
        .card-red .value { color: #dc2626; }
        .card-blue { background: #eff6ff; border: 1px solid #bfdbfe; }
        .card-blue .value { color: #2563eb; }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        table thead th {
            background: #1e40af;
            color: white;
            padding: 8px 6px;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
            border: 1px solid #1e3a8a;
        }
        table tbody td {
            padding: 5px 6px;
            text-align: center;
            border: 1px solid #e2e8f0;
            font-size: 9pt;
        }
        table tbody tr:nth-child(even) {
            background: #f8fafc;
        }
        table tbody tr.summary-row {
            background: #eef2ff;
            font-weight: bold;
        }
        table tbody tr.summary-row td {
            border-top: 2px solid #1e40af;
        }
        .rate-high { color: #059669; font-weight: bold; }
        .rate-mid { color: #d97706; font-weight: bold; }
        .rate-low { color: #dc2626; font-weight: bold; }

        .footer {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            font-size: 8pt;
            color: #94a3b8;
            text-align: center;
        }
        .comparison-section {
            margin-top: 20px;
            padding: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        .comparison-section h3 {
            margin: 0 0 8px 0;
            font-size: 10pt;
            color: #334155;
        }
        .comparison-grid {
            display: flex;
            gap: 16px;
        }
        .comparison-item {
            flex: 1;
            text-align: center;
        }
        .comparison-item .val {
            font-size: 14pt;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>LAPORAN TREN KEHADIRAN</h1>
        <p class="school-name">{{ $schoolName }}</p>
        <p class="period">Periode: {{ $startDate }} s/d {{ $endDate }}</p>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card card-green">
            <div class="value">{{ $summary['total_present'] }}</div>
            <div class="label">Hadir</div>
        </div>
        <div class="summary-card card-amber">
            <div class="value">{{ $summary['total_late'] }}</div>
            <div class="label">Terlambat</div>
        </div>
        <div class="summary-card card-red">
            <div class="value">{{ $summary['total_absent'] }}</div>
            <div class="label">Alpha</div>
        </div>
        <div class="summary-card card-blue">
            <div class="value">{{ $summary['avg_attendance_rate'] }}%</div>
            <div class="label">Rata-rata Hadir</div>
        </div>
    </div>

    <!-- Trend Data Table -->
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Hari</th>
                <th>Hadir</th>
                <th>Terlambat</th>
                <th>Alpha</th>
                <th>Sakit/Izin</th>
                <th>Total</th>
                <th>Rate (%)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($dailyData as $day)
            <tr>
                <td>{{ $day['date'] }}</td>
                <td>{{ $day['label'] }}</td>
                <td>{{ $day['present'] }}</td>
                <td>{{ $day['late'] }}</td>
                <td>{{ $day['absent'] }}</td>
                <td>{{ $day['excused'] }}</td>
                <td>{{ $day['total'] }}</td>
                <td class="{{ $day['rate'] >= 90 ? 'rate-high' : ($day['rate'] >= 75 ? 'rate-mid' : 'rate-low') }}">
                    {{ $day['rate'] }}%
                </td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="summary-row">
                <td colspan="2"><strong>TOTAL</strong></td>
                <td><strong>{{ $summary['total_present'] }}</strong></td>
                <td><strong>{{ $summary['total_late'] }}</strong></td>
                <td><strong>{{ $summary['total_absent'] }}</strong></td>
                <td><strong>{{ $summary['total_excused'] }}</strong></td>
                <td><strong>{{ $summary['total_records'] }}</strong></td>
                <td class="{{ $summary['avg_attendance_rate'] >= 90 ? 'rate-high' : ($summary['avg_attendance_rate'] >= 75 ? 'rate-mid' : 'rate-low') }}">
                    <strong>{{ $summary['avg_attendance_rate'] }}%</strong>
                </td>
            </tr>
        </tfoot>
    </table>

    <!-- Comparison Section -->
    @if (isset($comparison))
    <div class="comparison-section">
        <h3>📊 Perbandingan Minggu Ini vs Minggu Lalu</h3>
        <div class="comparison-grid">
            <div class="comparison-item">
                <div class="val" style="color: #2563eb;">{{ $comparison['this_week']['rate'] }}%</div>
                <div style="font-size: 8pt; color: #64748b;">Minggu Ini</div>
            </div>
            <div class="comparison-item">
                <div class="val" style="color: #94a3b8;">{{ $comparison['last_week']['rate'] }}%</div>
                <div style="font-size: 8pt; color: #64748b;">Minggu Lalu</div>
            </div>
            <div class="comparison-item">
                <div class="val" style="color: {{ $comparison['trend_direction'] === 'up' ? '#059669' : ($comparison['trend_direction'] === 'down' ? '#dc2626' : '#94a3b8') }};">
                    {{ $comparison['change_percent'] > 0 ? '+' : '' }}{{ $comparison['change_percent'] }}%
                </div>
                <div style="font-size: 8pt; color: #64748b;">
                    {{ $comparison['trend_direction'] === 'up' ? 'Meningkat' : ($comparison['trend_direction'] === 'down' ? 'Menurun' : 'Stabil') }}
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="footer">
        <p>Dicetak: {{ $generatedAt }} | Sistem AbsensiQR Pro</p>
        <p>Dokumen ini digenerate secara otomatis.</p>
    </div>
</body>
</html>
