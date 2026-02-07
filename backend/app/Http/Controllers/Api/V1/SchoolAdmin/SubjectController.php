<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $subjects = Subject::where('school_id', $user->school_id)
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate($request->get('per_page', 15));

        return response()->success($subjects);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('subjects')->where(function ($query) use ($user) {
                    return $query->where('school_id', $user->school_id);
                }),
            ],
            'grade_level' => 'nullable|string',
            'school_level' => 'nullable|string',
            'description' => 'nullable|string',
        ]);

        $subject = DB::transaction(function () use ($validated, $user) {
            $subject = Subject::create([
                'school_id' => $user->school_id,
                'name' => $validated['name'],
                'code' => $validated['code'],
                'grade_level' => $validated['grade_level'] ?? null,
                'school_level' => $validated['school_level'] ?? null,
                'description' => $validated['description'] ?? null,
                'is_active' => true,
            ]);

            AuditLog::create([
                'school_id' => $user->school_id,
                'user_id' => $user->id,
                'action' => 'subject_created',
                'module' => 'subject',
                'severity' => 'info',
                'description' => "Created subject: {$subject->name} ({$subject->code})",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $subject;
        });

        return response()->success($subject, 'Subject created successfully', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Subject $subject)
    {
        $this->authorizeSubject($request, $subject);

        return response()->success($subject);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Subject $subject)
    {
        $user = $request->user();
        $this->authorizeSubject($request, $subject);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('subjects')->where(function ($query) use ($user) {
                    return $query->where('school_id', $user->school_id);
                })->ignore($subject->id),
            ],
            'grade_level' => 'nullable|string',
            'school_level' => 'nullable|string',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $subject->update($validated);

        return response()->success($subject, 'Subject updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Subject $subject)
    {
        $this->authorizeSubject($request, $subject);

        // Check for dependencies
        if ($subject->schedules()->exists()) {
            return response()->error('Cannot delete subject with existing schedules', 409);
        }

        $subject->delete();

        return response()->success(null, 'Subject deleted successfully');
    }

    private function authorizeSubject(Request $request, Subject $subject)
    {
        if ($subject->school_id !== $request->user()->school_id) {
            abort(403, 'Unauthorized access to this subject');
        }
    }
}
