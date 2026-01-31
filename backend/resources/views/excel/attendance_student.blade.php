@extends('excel.layout')
@section('content')
<table>
    <thead>
        <tr>
            <th>Tanggal</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($params['calendar'] ?? [] as $row)
        <tr>
            <td>{{ $row['date'] }}</td>
            <td>{{ $row['status'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
