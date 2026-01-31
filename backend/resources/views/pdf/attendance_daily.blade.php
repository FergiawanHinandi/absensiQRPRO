<h2>Laporan Kehadiran Harian Kelas</h2>
<p>Tanggal: {{ $data['date'] ?? '' }}</p>
<p>Kelas: {{ $data['class_name'] ?? '' }}</p>
<table border="1" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th>Nama Siswa</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($data['students'] ?? [] as $student)
        <tr>
            <td>{{ $student['name'] }}</td>
            <td>{{ $student['status'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
<p>Total Siswa: {{ $data['total_students'] ?? 0 }}</p>
<p>Hadir: {{ $data['present'] ?? 0 }}</p>
<p>Terlambat: {{ $data['late'] ?? 0 }}</p>
<p>Absen: {{ $data['absent'] ?? 0 }}</p>
<p>Persentase Kehadiran: {{ $data['attendance_rate'] ?? 0 }}%</p>
