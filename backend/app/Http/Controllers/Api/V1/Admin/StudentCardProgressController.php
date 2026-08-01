<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StudentCardProgressController extends Controller
{
    public function progress(Request $request)
    {
        $this->authorize('viewAny', User::class);
        $admin = $request->user();
        $schoolId = $admin->school_id;
        $cacheKey = 'student_card_progress_'.$schoolId;
        $result = Cache::remember($cacheKey, 60, function () use ($schoolId) {
            $students = User::with(['classStudents.class'])
                ->where('school_id', $schoolId)
                ->where('role_type', 'student')
                ->where('is_active', true)
                ->get();
            $studentIds = $students->pluck('id');
            $activeCards = StudentCard::whereIn('student_id', $studentIds)->where('is_active', true)->get();
            $distributedCards = StudentCard::whereIn('student_id', $studentIds)->whereNotNull('distributed_at')->get();
            $cardsActiveCount = $activeCards->count();
            $cardsDistributedCount = $distributedCards->count();
            $cardsActiveStudentIds = $activeCards->pluck('student_id')->all();
            $studentsMissingCards = $students->filter(fn ($s) => ! in_array($s->id, $cardsActiveStudentIds));
            $photosPending = $students->filter(fn ($s) => $s->photo_review_status === 'pending');
            $photosDuplicate = $students->filter(fn ($s) => $s->photo_duplicate_flag ?? false);

            // Assume cards_printed = cardsActiveCount (or add flag if exists)
            return [
                'total_students' => $students->count(),
                'cards_active' => $cardsActiveCount,
                'cards_missing' => $studentsMissingCards->count(),
                'photos_pending_review' => $photosPending->count(),
                'photos_duplicate_flagged' => $photosDuplicate->count(),
                'cards_printed' => $cardsActiveCount,
                'cards_distributed' => $cardsDistributedCount,
                'students_missing_cards' => $studentsMissingCards->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'class' => optional($s->classStudents->first()->class ?? null)->name,
                ])->values(),
                'students_photo_pending' => $photosPending->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'class' => optional($s->classStudents->first()->class ?? null)->name,
                ])->values(),
                'students_photo_duplicate' => $photosDuplicate->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'class' => optional($s->classStudents->first()->class ?? null)->name,
                ])->values(),
            ];
        });
        Log::channel('audit')->info('view_student_card_progress_dashboard', [
            'admin_id' => $admin->id,
            'timestamp' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $result]);
    }
}
