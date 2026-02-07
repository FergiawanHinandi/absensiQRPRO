<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FaceRecognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StudentPhotoReviewController extends Controller
{
    protected $faceService;

    public function __construct(FaceRecognitionService $faceService)
    {
        $this->faceService = $faceService;
    }

    public function pending(Request $request)
    {
        $this->authorize('update', User::class);
        $students = User::with(['classStudents.class'])
            ->where('role_type', 'student')
            ->whereIn('photo_review_status', ['pending', 'rejected'])
            ->get();
        $result = $students->map(function ($s) {
            return [
                'student_id' => $s->id,
                'name' => $s->name,
                'class' => optional($s->classStudents->first()->class ?? null)->name,
                'photo_url' => $s->photo_path ? Storage::disk('public')->url($s->photo_path) : null,
                'review_status' => $s->photo_review_status,
            ];
        });

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function approve(Request $request, $studentId)
    {
        $this->authorize('update', User::class);
        $student = User::where('id', $studentId)->where('role_type', 'student')->firstOrFail();
        $student->photo_review_status = 'approved';
        $student->photo_reviewed_at = now();
        $student->photo_reviewed_by = $request->user()->id;
        $student->save();
        Log::channel('audit')->info('photo_approved', [
            'admin_id' => $request->user()->id,
            'student_id' => $student->id,
            'timestamp' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function reject(Request $request, $studentId)
    {
        $this->authorize('update', User::class);
        $student = User::where('id', $studentId)->where('role_type', 'student')->firstOrFail();
        $student->photo_review_status = 'rejected';
        $student->photo_reviewed_at = now();
        $student->photo_reviewed_by = $request->user()->id;
        $student->save();
        Log::channel('audit')->info('photo_rejected', [
            'admin_id' => $request->user()->id,
            'student_id' => $student->id,
            'timestamp' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function reupload(Request $request, $studentId)
    {
        $this->authorize('update', User::class);
        $student = User::where('id', $studentId)->where('role_type', 'student')->firstOrFail();
        $request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png|max:2048']);
        $file = $request->file('photo');
        $path = 'student-photos/'.$student->id.'.jpg';
        $img = \Intervention\Image\ImageManagerStatic::make($file->getRealPath())
            ->resize(400, 533, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            })
            ->encode('jpg', 80);
        Storage::disk('public')->put($path, $img);
        $student->photo_path = $path;
        $student->photo_review_status = 'pending';
        $student->photo_reviewed_at = null;
        $student->photo_reviewed_by = null;
        $student->save();

        // Face Embedding & Duplicate Check
        try {
            $imageContent = (string) $img;
            $embedding = $this->faceService->getEmbedding($imageContent);
            if ($embedding) {
                $this->faceService->updateEmbedding($student, $embedding);
                $this->faceService->checkForDuplicates($student, $embedding);
            }
        } catch (\Exception $e) {
            Log::warning('Face embedding failed on reupload', ['student_id' => $student->id, 'error' => $e->getMessage()]);
        }

        Log::channel('audit')->info('photo_reuploaded', [
            'admin_id' => $request->user()->id,
            'student_id' => $student->id,
            'timestamp' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function bulkApprove(Request $request)
    {
        $this->authorize('update', User::class);
        $request->validate(['student_ids' => 'required|array']);
        $ids = $request->input('student_ids');
        $adminId = $request->user()->id;
        $approved = 0;
        $skipped = [];
        $now = now();
        
        // CRITICAL FIX: Add school_id filter to prevent cross-school access
        $students = User::whereIn('id', $ids)
            ->where('role_type', 'student')
            ->where('school_id', $request->user()->school_id)  // SECURITY: School isolation
            ->get();
            
        foreach ($students as $student) {
            if (
                $student->photo_review_status !== 'pending' ||
                ($student->photo_duplicate_flag ?? false) ||
                ($student->face_detected ?? false) !== true
            ) {
                $skipped[] = [
                    'student_id' => $student->id,
                    'reason' => $student->photo_review_status !== 'pending' ? 'not_pending' : (($student->photo_duplicate_flag ?? false) ? 'duplicate_face_detected' : 'no_face_detected'),
                ];

                continue;
            }
            $student->photo_review_status = 'approved';
            $student->photo_reviewed_at = $now;
            $student->photo_reviewed_by = $adminId;
            $student->save();
            $approved++;
        }
        \Log::channel('audit')->info('bulk_photo_review_action', [
            'admin_id' => $adminId,
            'action' => 'approve',
            'count' => $approved,
            'timestamp' => $now,
        ]);

        return response()->json([
            'approved_count' => $approved,
            'rejected_count' => 0,
            'skipped' => $skipped,
        ]);
    }

    public function bulkReject(Request $request)
    {
        $this->authorize('update', User::class);
        $request->validate(['student_ids' => 'required|array']);
        $ids = $request->input('student_ids');
        $adminId = $request->user()->id;
        $rejected = 0;
        $now = now();
        
        // CRITICAL FIX: Add school_id filter to prevent cross-school access
        $students = User::whereIn('id', $ids)
            ->where('role_type', 'student')
            ->where('school_id', $request->user()->school_id)  // SECURITY: School isolation
            ->get();
            
        foreach ($students as $student) {
            $student->photo_review_status = 'rejected';
            $student->photo_reviewed_at = $now;
            $student->photo_reviewed_by = $adminId;
            $student->save();
            $rejected++;
        }
        \Log::channel('audit')->info('bulk_photo_review_action', [
            'admin_id' => $adminId,
            'action' => 'reject',
            'count' => $rejected,
            'timestamp' => $now,
        ]);

        return response()->json([
            'approved_count' => 0,
            'rejected_count' => $rejected,
            'skipped' => [],
        ]);
    }
}
