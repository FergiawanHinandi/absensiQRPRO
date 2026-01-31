<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CertificateService
{
    /**
     * Generate Gold Attendance Certificate
     */
    public function generateGoldCertificate(User $student, string $semester, int $year, float $attendanceRate)
    {
        // 1. Prepare Data
        $school = $student->school;
        $className = 'Unknown Class';
        
        $classMembership = $student->classStudents()->where('status', 'active')->first();
        if ($classMembership && $classMembership->class) {
            $className = $classMembership->class->name;
        }

        $data = [
            'student_name' => $student->name,
            'semester' => $semester,
            'year' => $year,
            'attendance_rate' => number_format($attendanceRate, 1),
            'class_name' => $className,
            'school_name' => $school ? $school->name : 'School Principal',
            'date_issued' => now()->format('d F Y'),
        ];

        // 2. Generate PDF
        $pdf = Pdf::loadView('pdfs.certificate', $data);
        $pdf->setPaper('a4', 'landscape');
        
        // 3. Define Filename
        $code = 'CERT-' . $year . '-' . Str::upper(Str::slug($semester)) . '-' . $student->id . '-' . Str::upper(Str::random(4));
        $filename = "certificates/{$year}/{$code}.pdf";

        // 4. Save to Storage (Public Disk to be accessible via URL)
        Storage::disk('public')->put($filename, $pdf->output());

        // 5. Create DB Record
        $certificate = Certificate::create([
            'student_id' => $student->id,
            'certificate_code' => $code,
            'type' => 'gold_attendance',
            'file_path' => $filename,
            'semester' => $semester,
            'academic_year' => $year,
            'attendance_rate' => $attendanceRate,
            'metadata' => ['generated_by' => 'system'],
            'issued_at' => now(),
        ]);

        return $certificate;
    }
}
