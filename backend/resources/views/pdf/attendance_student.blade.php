<h2>Laporan Kehadiran Siswa Bulanan</h2>
<p>Nama: {{ $data['student_name'] ?? '' }}</p>
<p>Bulan: {{ $data['month'] ?? '' }}/{{ $data['year'] ?? '' }}</p>
<p>Persentase Kehadiran: {{ $data['attendance_rate'] ?? 0 }}%</p>
<p>Terlambat: {{ $data['late_count'] ?? 0 }}</p>
<p>Absen: {{ $data['absent_count'] ?? 0 }}</p>
<table border="1" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th>Tanggal</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($data['calendar'] ?? [] as $row)
        <tr>
            <td>{{ $row['date'] }}</td>
            <td>{{ $row['status'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
