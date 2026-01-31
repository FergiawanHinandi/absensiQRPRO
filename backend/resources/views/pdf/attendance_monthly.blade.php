<h2>Laporan Kehadiran Bulanan Kelas</h2>
<p>Bulan: {{ $data['month'] ?? '' }}/{{ $data['year'] ?? '' }}</p>
<p>Kelas: {{ $data['class_name'] ?? '' }}</p>
<table border="1" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th>Nama Siswa</th>
            <th>Hadir</th>
            <th>Terlambat</th>
            <th>Absen</th>
            <th>Persentase</th>
        </tr>
    </thead>
    <tbody>
        @foreach($data['students'] ?? [] as $student)
        <tr>
            <td>{{ $student['name'] }}</td>
            <td>{{ $student['present'] }}</td>
            <td>{{ $student['late'] }}</td>
            <td>{{ $student['absent'] }}</td>
            <td>{{ $student['attendance_rate'] }}%</td>
        </tr>
        @endforeach
    </tbody>
</table>
<p>Total Hari Sekolah: {{ $data['total_school_days'] ?? 0 }}</p>
<p>Rata-rata Kehadiran: {{ $data['avg_attendance_rate'] ?? 0 }}%</p>
<p>Total Terlambat: {{ $data['total_late'] ?? 0 }}</p>
<p>Total Absen: {{ $data['total_absent'] ?? 0 }}</p>
