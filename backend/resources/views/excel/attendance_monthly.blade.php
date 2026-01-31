@extends('excel.layout')
@section('content')
<table>
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
        @foreach($params['students'] ?? [] as $student)
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
@endsection
