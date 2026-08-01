<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SchoolController extends Controller
{
    /**
     * Show school details (apiResource 'show' method)
     * Aliases to profile()
     */
    public function show(Request $request, $id = null)
    {
        return $this->profile($request);
    }

    /**
     * Update school details (apiResource 'update' method)
     * Aliases to updateProfile()
     */
    public function update(Request $request, $id = null)
    {
        return $this->updateProfile($request);
    }

    /**
     * Get School Profile
     */
    public function profile(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $school = Cache::remember("school_profile_{$schoolId}", 300, function () use ($schoolId) {
            return School::find($schoolId);
        });

        if (! $school) {
            return response()->json(['success' => false, 'message' => 'School not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $school,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $school = School::find($user->school_id);
        if (! $school) {
            return response()->json(['success' => false, 'message' => 'School not found'], 404);
        }
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'npsn' => 'nullable|string|max:50',
            'school_level' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'timezone' => 'nullable|string|max:50',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:0|max:1000',
            'settings' => 'nullable|array',
        ]);
        $payload = $validated;
        if (isset($validated['start_time'])) {
            $payload['start_time'] = \Carbon\Carbon::createFromFormat('H:i', $validated['start_time'])->format('H:i:s');
        }
        if (isset($validated['end_time'])) {
            $payload['end_time'] = \Carbon\Carbon::createFromFormat('H:i', $validated['end_time'])->format('H:i:s');
        }
        $school->update($payload);
        if (array_key_exists('settings', $payload)) {
            \App\Helpers\SchoolSettingsHelper::clearCache($school->id);
        }

        return response()->json([
            'success' => true,
            'data' => $school->refresh(),
        ]);
    }

    /**
     * Get Academic Years
     */
    public function academicYears(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $years = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->orderBy('start_date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $years->count(),
                'years' => $years,
            ],
        ]);
    }

    public function storeAcademicYear(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'semester' => 'required|in:1,2',
            'is_active' => 'nullable|boolean',
        ]);
        $exists = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('name', $validated['name'])
            ->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Nama tahun ajaran sudah digunakan'], 422);
        }

        // Check Overlap
        if ($this->checkAcademicYearOverlap($schoolId, $validated['start_date'], $validated['end_date'])) {
            return response()->json(['success' => false, 'message' => 'Rentang tanggal bertabrakan dengan tahun ajaran lain'], 422);
        }

        DB::beginTransaction();
        try {
            if (! empty($validated['is_active'])) {
                DB::table('academic_years')->where('school_id', $schoolId)->update(['is_active' => false]);
            }
            $id = DB::table('academic_years')->insertGetId([
                'school_id' => $schoolId,
                'name' => $validated['name'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'is_active' => (bool) ($validated['is_active'] ?? false),
                'semester' => $validated['semester'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::commit();
            $year = DB::table('academic_years')->where('id', $id)->first();

            return response()->json(['success' => true, 'data' => $year], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => 'Gagal menyimpan tahun ajaran'], 500);
        }
    }

    public function updateAcademicYear(Request $request, int $id)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $year = DB::table('academic_years')->where('school_id', $schoolId)->where('id', $id)->first();
        if (! $year) {
            return response()->json(['success' => false, 'message' => 'Tahun ajaran tidak ditemukan'], 404);
        }
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'semester' => 'required|in:1,2',
            'is_active' => 'nullable|boolean',
        ]);
        $exists = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('name', $validated['name'])
            ->where('id', '<>', $id)
            ->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Nama tahun ajaran sudah digunakan'], 422);
        }

        // Check Overlap
        if ($this->checkAcademicYearOverlap($schoolId, $validated['start_date'], $validated['end_date'], $id)) {
            return response()->json(['success' => false, 'message' => 'Rentang tanggal bertabrakan dengan tahun ajaran lain'], 422);
        }

        DB::beginTransaction();
        try {
            if (! empty($validated['is_active'])) {
                DB::table('academic_years')->where('school_id', $schoolId)->update(['is_active' => false]);
            }
            DB::table('academic_years')->where('id', $id)->update([
                'name' => $validated['name'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'is_active' => (bool) ($validated['is_active'] ?? $year->is_active),
                'semester' => $validated['semester'],
                'updated_at' => now(),
            ]);
            DB::commit();
            $updated = DB::table('academic_years')->where('id', $id)->first();

            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => 'Gagal memperbarui tahun ajaran'], 500);
        }
    }

    public function activateAcademicYear(Request $request, int $id)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // First check existence without lock
        $year = DB::table('academic_years')->where('school_id', $schoolId)->where('id', $id)->first();
        if (! $year) {
            return response()->json(['success' => false, 'message' => 'Tahun ajaran tidak ditemukan'], 404);
        }

        DB::beginTransaction();
        try {
            // SECURITY FIX: Use row-level locking to prevent race conditions
            // Lock all academic years for this school to prevent concurrent activation
            DB::table('academic_years')
                ->where('school_id', $schoolId)
                ->lockForUpdate()
                ->get();

            // Now safely deactivate all and activate the selected one
            DB::table('academic_years')->where('school_id', $schoolId)->update(['is_active' => false]);
            DB::table('academic_years')->where('id', $id)->update(['is_active' => true, 'updated_at' => now()]);
            DB::commit();

            // Dispatch Event to Clear Cache
            \App\Events\AcademicYearActivated::dispatch($schoolId, $id);

            $updated = DB::table('academic_years')->where('id', $id)->first();

            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => 'Gagal mengaktifkan tahun ajaran'], 500);
        }
    }

    public function deleteAcademicYear(Request $request, int $id)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $year = DB::table('academic_years')->where('school_id', $schoolId)->where('id', $id)->first();
        if (! $year) {
            return response()->json(['success' => false, 'message' => 'Tahun ajaran tidak ditemukan'], 404);
        }
        DB::table('academic_years')->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => 'Tahun ajaran dihapus']);
    }

    /**
     * Get Subjects
     */
    public function subjects(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $subjects = \App\Models\Subject::where('school_id', $schoolId)->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $subjects->count(),
                'subjects' => $subjects,
            ],
        ]);
    }

    public function getSettings(Request $request)
    {
        $user = $request->user();

        // Use Cached Settings (Reduces DB Query)
        $settings = \App\Helpers\SchoolSettingsHelper::getSettingsWithCache($user->school_id);

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $user = $request->user();
        $school = School::find($user->school_id);

        if (! $school) {
            return response()->json(['success' => false, 'message' => 'School not found'], 404);
        }

        $rules = \App\Helpers\SchoolSettingsHelper::getValidationRules();
        $validated = $request->validate($rules);

        // Get current settings
        $current = $school->settings ?? [];

        // Cast incoming inputs
        $incoming = \App\Helpers\SchoolSettingsHelper::castSettings($validated);

        // Merge: Current DB + Incoming Changes
        $merged = array_merge($current, $incoming);

        // Clean up and ensure types (removes obsolete keys)
        $finalSettings = \App\Helpers\SchoolSettingsHelper::castSettings($merged);

        $school->settings = $finalSettings;
        $school->save();

        // Clear Cache to ensure next read gets fresh data
        \App\Helpers\SchoolSettingsHelper::clearCache($school->id);

        return response()->json([
            'success' => true,
            'data' => \App\Helpers\SchoolSettingsHelper::mergeWithDefaults($school->settings),
        ]);
    }

    /**
     * Check if academic year overlaps with existing ones
     */
    private function checkAcademicYearOverlap($schoolId, $startDate, $endDate, $excludeId = null)
    {
        $query = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            });

        if ($excludeId) {
            $query->where('id', '<>', $excludeId);
        }

        return $query->exists();
    }
}
