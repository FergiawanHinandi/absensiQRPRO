<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Certificate of Achievement</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            text-align: center;
            border: 10px solid #D4AF37; /* Gold Border */
            padding: 40px;
            height: 90vh;
            position: relative;
        }
        .header {
            font-size: 50px;
            font-weight: bold;
            color: #333;
            margin-bottom: 20px;
            text-transform: uppercase;
        }
        .sub-header {
            font-size: 24px;
            color: #666;
            margin-bottom: 40px;
        }
        .student-name {
            font-size: 40px;
            color: #D4AF37; /* Gold */
            border-bottom: 2px solid #ddd;
            display: inline-block;
            padding-bottom: 10px;
            margin-bottom: 30px;
            width: 80%;
        }
        .details {
            font-size: 18px;
            color: #555;
            line-height: 1.6;
            margin-bottom: 50px;
        }
        .details strong {
            color: #000;
        }
        .footer {
            margin-top: 80px;
            display: flex;
            justify-content: space-between;
        }
        .signature {
            width: 40%;
            border-top: 1px solid #333;
            padding-top: 10px;
            margin: 0 auto;
        }
        .logo {
            position: absolute;
            top: 40px;
            left: 40px;
            width: 80px;
        }
        .badge-icon {
            position: absolute;
            top: 40px;
            right: 40px;
            width: 100px;
        }
    </style>
</head>
<body>
    <div class="header">Certificate of Achievement</div>
    <div class="sub-header">Gold Attendance Award</div>

    <p>This certificate is proudly presented to</p>

    <div class="student-name">{{ $student_name }}</div>

    <div class="details">
        For demonstrating exceptional discipline and commitment with excellent attendance<br>
        during the <strong>{{ $semester }} Semester, {{ $year }}</strong>.
        <br><br>
        <strong>Attendance Rate: {{ $attendance_rate }}%</strong><br>
        Class: {{ $class_name }}
    </div>

    <div class="footer">
        <div class="signature">
            <p><strong>Principal Signature</strong></p>
            <p>{{ $school_name }}</p>
        </div>
        <div class="signature">
            <p><strong>Date Issued</strong></p>
            <p>{{ $date_issued }}</p>
        </div>
    </div>
</body>
</html>
