@extends('excel.layout')
@section('content')
<table>
    <thead>
        <tr>
            <th>Nama Siswa</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($params['students'] ?? [] as $student)
        <tr>
            <td>{{ $student['name'] }}</td>
            <td>{{ $student['status'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
