<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FaceRecognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentPhotoDuplicateController extends Controller
{
    protected $faceService;

    public function __construct(FaceRecognitionService $faceService)
    {
        $this->faceService = $faceService;
    }

    /**
     * Get list of potential photo duplicates
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class); // Or a specific permission
        
        $schoolId = $request->user()->school_id;
        $duplicates = $this->faceService->findAllDuplicatesInSchool($schoolId);

        return response()->json([
            'success' => true,
            'data' => $duplicates
        ]);
    }

    /**
     * Resolve duplicate flag (False Positive)
     */
    public function resolve(Request $request, $studentId)
    {
        $this->authorize('update', User::class);
        
        $student = User::where('id', $studentId)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $action = $request->input('action'); // 'false_positive' or 'corrected'

        if ($action === 'false_positive') {
            $student->photo_duplicate_flag = false;
            $student->save();

            Log::channel('audit')->info('photo_duplicate_cleared', [
                'student_id' => $student->id,
                'admin_id' => $request->user()->id,
                'reason' => 'false_positive'
            ]);
        } elseif ($action === 'corrected') {
            // "Corrected" usually means they acknowledged it and will reupload.
            // But if they manually mark it, we might just clear the flag too?
            // "Corrected -> after reupload". If reupload happens, the flag is re-checked.
            // If they just want to clear it because they manually fixed it (maybe deleted the other student?), clear flag.
            $student->photo_duplicate_flag = false;
            $student->save();

            Log::channel('audit')->info('photo_duplicate_cleared', [
                'student_id' => $student->id,
                'admin_id' => $request->user()->id,
                'reason' => 'corrected'
            ]);
        } else {
            return response()->json(['message' => 'Invalid action'], 400);
        }

        return response()->json(['success' => true, 'message' => 'Duplicate flag resolved']);
    }
}
