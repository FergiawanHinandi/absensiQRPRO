<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\StudentCardService;
use Illuminate\Http\Request;

class StudentCardController extends Controller
{
    protected $service;

    public function __construct(StudentCardService $service)
    {
        $this->service = $service;
    }

    public function generate(Request $request, $studentId)
    {
        $this->authorize('generate', StudentCard::class);
        $student = User::where('id', $studentId)->where('role_type', 'student')->firstOrFail();
        $admin = $request->user();
        $card = $this->service->generateCard($student, $admin);

        return response()->json(['success' => true, 'data' => $card]);
    }

    public function markDistributed(Request $request, $cardId)
    {
        $this->authorize('distribute', StudentCard::class);
        $card = StudentCard::findOrFail($cardId);
        $admin = $request->user();
        $card = $this->service->markDistributed($card, $admin);

        return response()->json(['success' => true, 'data' => $card]);
    }

    public function regenerate(Request $request, $studentId)
    {
        $this->authorize('regenerate', StudentCard::class);
        $request->validate(['reason' => 'required|string']);
        $student = User::where('id', $studentId)->where('role_type', 'student')->firstOrFail();
        $admin = $request->user();
        $card = $this->service->regenerateCard($student, $admin, $request->input('reason'));

        return response()->json(['success' => true, 'data' => $card]);
    }

    public function history(Request $request, $studentId)
    {
        $this->authorize('viewAny', StudentCard::class);
        $student = User::where('id', $studentId)->where('role_type', 'student')->firstOrFail();
        $history = $this->service->getCardHistory($student);

        return response()->json(['success' => true, 'data' => $history]);
    }

    public function bulkGenerate(Request $request)
    {
        $this->authorize('generate', \App\Models\StudentCard::class);
        $admin = $request->user();
        $filters = [
            'class_id' => $request->query('class_id'),
            'grade_level' => $request->query('grade_level'),
            'academic_year' => $request->query('academic_year'),
        ];
        $forceRegenerate = filter_var($request->query('force_regenerate'), FILTER_VALIDATE_BOOLEAN);
        $query = \App\Models\User::query()
            ->where('role_type', 'student')
            ->where('school_id', $admin->school_id);
        if ($filters['class_id']) {
            $query->whereHas('classStudents', function ($q) use ($filters) {
                $q->where('class_id', $filters['class_id']);
            });
        }
        if ($filters['grade_level']) {
            $query->whereHas('classStudents.class', function ($q) use ($filters) {
                $q->where('grade_level', $filters['grade_level']);
            });
        }
        if ($filters['academic_year']) {
            $query->whereHas('classStudents.class', function ($q) use ($filters) {
                $q->where('academic_year_id', $filters['academic_year']);
            });
        }
        // Only students with no active card or force regenerate
        $students = $query->get();
        $maxLimit = 1000;
        if ($students->count() > $maxLimit) {
            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak siswa, gunakan filter atau proses dengan queue.',
                'total_students' => $students->count(),
            ], 422);
        }
        if ($students->count() > 300) {
            // Dispatch queue job
            \App\Jobs\BulkGenerateStudentCards::dispatch($students->pluck('id')->all(), $admin->id, $filters, $forceRegenerate);

            return response()->json([
                'success' => true,
                'message' => 'Proses bulk card generation sedang dijalankan di background. Anda akan diberi notifikasi jika sudah selesai.',
                'total_students' => $students->count(),
            ]);
        }
        // Generate PDFs and Zip
        $summary = $this->service->bulkGenerateCards($students, $admin, $forceRegenerate, $filters);

        // Check if ZIP path exists in summary and download it
        if (isset($summary['zip_path'])) {
            $relativePath = 'temp/' . $summary['zip_path'];
            if (\Illuminate\Support\Facades\Storage::exists($relativePath)) {
                return response()->download(storage_path('app/' . $relativePath))->deleteFileAfterSend();
            }
        }

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'message' => 'Bulk card generation selesai.',
        ]);
    }

    public function download(Request $request)
    {
        $filename = $request->query('path');
        
        // Security: Validate filename format
        if (!$filename || !preg_match('/^student_cards_batch_.*\.zip$/', $filename)) {
            abort(403, 'Invalid filename');
        }

        $relativePath = 'temp/' . $filename;
        
        if (!\Illuminate\Support\Facades\Storage::exists($relativePath)) {
            abort(404, 'File expired or not found');
        }

        return response()->download(storage_path('app/' . $relativePath))->deleteFileAfterSend();
    }
}
