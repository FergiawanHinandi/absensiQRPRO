<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Kartu Pelajar</title>
    <style>
        @page {
            margin: 0;
            size: 85.6mm 54mm;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: sans-serif;
            width: 85.6mm;
            height: 54mm;
            overflow: hidden;
            background-color: #fff;
        }
        .card {
            width: 100%;
            height: 100%;
            position: relative;
            border: 0.5px solid #ddd;
        }
        .header {
            height: 12mm;
            background-color: #f8f9fa;
            border-bottom: 2px solid #3b82f6;
            display: block;
            position: relative;
        }
        .logo {
            width: 8mm;
            height: 8mm;
            position: absolute;
            top: 2mm;
            left: 4mm;
            object-fit: contain;
        }
        .school-name {
            width: 100%;
            text-align: center;
            font-weight: bold;
            font-size: 10pt;
            line-height: 12mm;
            text-transform: uppercase;
            color: #1f2937;
            padding-left: 5mm;
            padding-right: 5mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .content {
            padding: 0;
            position: relative;
            height: 42mm;
        }
        .photo-container {
            position: absolute;
            left: 4mm;
            top: 4mm;
            width: 22mm;
            height: 28mm;
            border: 1px solid #e5e7eb;
            background-color: #f3f4f6;
            overflow: hidden;
            border-radius: 4px;
        }
        .photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .details {
            position: absolute;
            left: 30mm;
            top: 4mm;
            width: 50mm;
            font-size: 9pt;
        }
        .student-name {
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 2mm;
            color: #111827;
            line-height: 1.2;
        }
        .info-row {
            margin-bottom: 2px;
            line-height: 1.2;
        }
        .label {
            font-size: 6pt;
            color: #6b7280;
            text-transform: uppercase;
            display: block;
        }
        .value {
            font-weight: 600;
            font-size: 8pt;
            color: #374151;
        }
        .qr-container {
            position: absolute;
            right: 4mm;
            bottom: 4mm;
            width: 18mm;
            height: 18mm;
        }
        .qr-container svg {
            width: 100%;
            height: 100%;
        }
        .academic-year {
            position: absolute;
            left: 4mm;
            bottom: 4mm;
            font-size: 7pt;
            font-weight: bold;
            color: #6b7280;
            background-color: #f3f4f6;
            padding: 2px 6px;
            border-radius: 99px;
        }
        .footer-deco {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2mm;
            background-color: #3b82f6;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            @if(isset($school->logo_url))
                <img src="{{ $school->logo_url }}" class="logo">
            @elseif(isset($school->settings['logo_url']))
                <img src="{{ $school->settings['logo_url'] }}" class="logo">
            @else
                <!-- Placeholder Logo -->
                <div class="logo" style="background-color: #ccc; border-radius: 50%;"></div>
            @endif
            <div class="school-name">{{ $school->name }}</div>
        </div>

        <div class="content">
            <div class="photo-container">
                @if($student->profile_photo_url)
                    <img src="{{ $student->profile_photo_url }}" class="photo">
                @elseif($student->profile && $student->profile->photo_url)
                    <img src="{{ $student->profile->photo_url }}" class="photo">
                @else
                    <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:8pt;">
                        FOTO
                    </div>
                @endif
            </div>

            <div class="details">
                <div class="student-name">{{ $student->name }}</div>
                
                <div class="info-row">
                    <span class="label">NIS / NISN</span>
                    <span class="value">
                        {{ $student->username }} / {{ $student->profile->nisn ?? '-' }}
                    </span>
                </div>
                
                <div class="info-row">
                    <span class="label">Kelas</span>
                    <span class="value">{{ $className }}</span>
                </div>
            </div>

            <div class="academic-year">
                TA: {{ $academicYear ? $academicYear->name : date('Y') }}
            </div>

            <div class="qr-container">
                {!! $qrCodeSvg !!}
            </div>
            
            <div class="footer-deco"></div>
        </div>
    </div>
</body>
</html>
