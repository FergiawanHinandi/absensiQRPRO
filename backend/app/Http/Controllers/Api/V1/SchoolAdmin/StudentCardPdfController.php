<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ClassModel;
use App\Models\StudentCard;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use ZipArchive;

class StudentCardPdfController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'role:school_admin']);
    }

    /**
     * Generate PDF for a single student
     */
    public function generateSingle(Request $request, User $student)
    {
        // Authorization
        $this->authorize('view', $student); // Ensure admin can view this student

        if ($student->school_id !== $request->user()->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check for duplicate photo flag
        if ($student->photo_duplicate_flag) {
            return response()->json([
                'message' => 'Cannot generate card. Student photo is flagged as a potential duplicate. Please resolve this in the Photo Review section.',
            ], 403);
        }

        $student->load(['profile', 'studentClass.class_model']);

        // Get Active Card
        $activeCard = StudentCard::where('student_id', $student->id)
            ->where('is_active', true)
            ->first();

        if (! $activeCard) {
            return response()->json(['message' => 'Siswa ini belum memiliki kartu aktif. Silakan generate kartu terlebih dahulu.'], 404);
        }

        // Generate QR Code
        // Generate QR Code using the decrypted plain token
        // Fallback to empty if not set (legacy card?), but this shouldn't happen for new cards
        $plainToken = $activeCard->qr_token_encrypted;

        if (! $plainToken) {
            return response()->json(['message' => 'Kartu ini format lama/rusak. Harap regenerasi kartu.'], 400);
        }

        // Format: card_id|plain_token
        $qrContent = $activeCard->id.'|'.$plainToken;

        $qrCodeSvg = $this->generateQrSvg($qrContent);

        // Get Academic Year
        $academicYear = AcademicYear::where('school_id', $student->school_id)
            ->where('is_active', true)
            ->first();

        // Generate PDF
        $pdf = Pdf::loadView('pdf.student-card', [
            'student' => $student,
            'card' => $activeCard,
            'qrCodeSvg' => $qrCodeSvg,
            'school' => $student->school,
            'academicYear' => $academicYear,
            'className' => $student->studentClass?->class_model?->name ?? '-',
        ]);

        // Set paper size to CR80 (85.6mm x 54mm) -> approx 242.64pt x 153.07pt
        // 1mm = 2.83465pt
        $pdf->setPaper([0, 0, 242.64, 153.07], 'landscape');

        // Audit Log
        AuditLog::create([
            'user_id' => $request->user()->id,
            'school_id' => $request->user()->school_id,
            'action' => 'student_card_pdf_generated',
            'description' => "Generated PDF for student: {$student->name} ({$student->id})",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $pdf->download("kartu-{$student->name}.pdf");
    }

    /**
     * Bulk Generate PDF for a class (ZIP)
     */
    public function generateBulk(Request $request, ClassModel $class)
    {
        if ($class->school_id !== $request->user()->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get students in class
        $students = User::whereHas('studentClass', function ($query) use ($class) {
            $query->where('class_id', $class->id)
                ->where('status', 'active');
        })
            ->where('role_type', 'student')
            ->with(['profile', 'studentClass.class_model'])
            ->get();

        if ($students->isEmpty()) {
            return response()->json(['message' => 'Tidak ada siswa aktif di kelas ini.'], 404);
        }

        $zipFileName = "kartu-kelas-{$class->name}.zip";
        $zipFilePath = storage_path("app/public/temp/{$zipFileName}");

        // Ensure temp directory exists
        if (! file_exists(dirname($zipFilePath))) {
            mkdir(dirname($zipFilePath), 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Could not create ZIP file'], 500);
        }

        $academicYear = AcademicYear::where('school_id', $class->school_id)
            ->where('is_active', true)
            ->first();

        $filesToAdd = [];

        foreach ($students as $student) {
            // Skip flagged students
            if ($student->photo_duplicate_flag) {
                continue;
            }

            $activeCard = StudentCard::where('student_id', $student->id)
                ->where('is_active', true)
                ->first();

            if (! $activeCard) {
                continue; // Skip students without active cards
            }

            $plainToken = $activeCard->qr_token_encrypted;
            if (! $plainToken) {
                // Should we skip or include a broken card? Skipping for now.
                continue;
            }
            $qrContent = $activeCard->id.'|'.$plainToken;

            $qrCodeSvg = $this->generateQrSvg($qrContent);

            $pdf = Pdf::loadView('pdf.student-card', [
                'student' => $student,
                'card' => $activeCard,
                'qrCodeSvg' => $qrCodeSvg,
                'school' => $student->school,
                'academicYear' => $academicYear,
                'className' => $class->name,
            ]);

            $pdf->setPaper([0, 0, 242.64, 153.07], 'landscape');

            $fileName = "kartu-{$student->name}.pdf";
            $zip->addFromString($fileName, $pdf->output());
        }

        $zip->close();

        if (! file_exists($zipFilePath)) {
            return response()->json(['message' => 'Failed to generate ZIP file (No valid cards?)'], 500);
        }

        // Audit Log
        AuditLog::create([
            'user_id' => $request->user()->id,
            'school_id' => $request->user()->school_id,
            'action' => 'student_card_bulk_pdf_generated',
            'description' => "Generated Bulk PDF for class: {$class->name} ({$class->id})",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->download($zipFilePath)->deleteFileAfterSend(true);
    }

    private function generateQrSvg($content)
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200, 0), // size, margin
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);

        return $writer->writeString($content);
    }
}
