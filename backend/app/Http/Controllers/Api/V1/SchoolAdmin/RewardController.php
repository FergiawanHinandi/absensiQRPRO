<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    /**
     * List all rewards (certificates) for the school.
     * Filter by redeeming status, semester, etc.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $query = Certificate::with(['student:id,name,class_students', 'verifier:id,name'])
            ->whereHas('student', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            });

        // Filters
        if ($request->has('is_redeemed')) {
            $query->where('is_redeemed', $request->boolean('is_redeemed'));
        }

        if ($request->filled('semester')) {
            $query->where('semester', $request->semester);
        }

        if ($request->filled('year')) {
            $query->where('academic_year', $request->year);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $certificates = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $certificates,
        ]);
    }

    /**
     * Get specific student's full reward history (Certificates + Badges).
     */
    public function studentHistory(Request $request, $studentId)
    {
        $student = User::where('id', $studentId)
            ->where('role', 'student')
            ->firstOrFail();

        // Check if student belongs to admin's school
        if ($student->school_id !== $request->user()->school_id) {
            abort(403, 'Unauthorized access to student.');
        }

        $certificates = Certificate::where('student_id', $studentId)
            ->orderByDesc('created_at')
            ->get();

        $badges = $student->badges()
            ->orderByDesc('student_badges.awarded_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $student->only(['id', 'name', 'profile_photo_url', 'total_points']),
                'circles' => $certificates,
                'badges' => $badges,
            ],
        ]);
    }

    /**
     * Approve/Redeem a reward (Certificate).
     * Typically used when handing over physical copy.
     */
    public function redeem(Request $request, $id)
    {
        $certificate = Certificate::findOrFail($id);

        // Authorization check (ensure cert belongs to school via student)
        $student = $certificate->student;
        if (! $student || $student->school_id !== $request->user()->school_id) {
            abort(403);
        }

        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $certificate->update([
            'is_redeemed' => true,
            'redeemed_at' => now(),
            'redeemed_by' => $request->user()->id,
            'redemption_notes' => $request->input('notes'),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Reward marked as redeemed.',
            'data' => $certificate,
        ]);
    }

    /**
     * Revoke a reward.
     * Deletes the certificate record.
     */
    public function destroy(Request $request, $id)
    {
        $certificate = Certificate::findOrFail($id);

        $student = $certificate->student;
        if (! $student || $student->school_id !== $request->user()->school_id) {
            abort(403);
        }

        // Logic choice: Delete file too?
        // Ideally yes.
        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($certificate->file_path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($certificate->file_path);
        }

        $certificate->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Reward revoked successfully.',
        ]);
    }
}
