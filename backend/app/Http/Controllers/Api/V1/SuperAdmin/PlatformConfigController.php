<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformConfigController extends Controller
{
    // --- FEATURE FLAGS ---

    public function getFeatureFlags()
    {
        $flags = DB::table('feature_flags')->orderBy('key')->get();

        return response()->json(['success' => true, 'data' => $flags]);
    }

    public function updateFeatureFlag(Request $request, $id)
    {
        $request->validate(['is_enabled' => 'required|boolean']);

        DB::table('feature_flags')->where('id', $id)->update([
            'is_enabled' => $request->is_enabled,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Feature flag updated']);
    }

    // --- SCHEDULE TEMPLATES ---

    public function getScheduleTemplates()
    {
        $templates = DB::table('schedule_templates')->where('is_active', true)->get()
            ->map(function ($t) {
                $t->schedule_structure = json_decode($t->schedule_structure);

                return $t;
            });

        return response()->json(['success' => true, 'data' => $templates]);
    }

    public function storeScheduleTemplate(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'schedule_structure' => 'required|array',
        ]);

        $id = DB::table('schedule_templates')->insertGetId([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'schedule_structure' => json_encode($validated['schedule_structure']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Template created', 'data' => ['id' => $id]]);
    }

    public function deleteScheduleTemplate($id)
    {
        DB::table('schedule_templates')->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => 'Template deleted']);
    }

    // --- ACADEMIC YEARS (GLOBAL DEPLOYMENT) ---

    public function getAcademicYearsStats()
    {
        // Get unique academic year names across schools and count adoption
        $stats = DB::table('academic_years')
            ->select('name', DB::raw('count(distinct school_id) as adoption_count'), DB::raw('count(id) as total_instances'))
            ->groupBy('name')
            ->orderBy('name', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function deployAcademicYear(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|regex:/^\d{4}\/\d{4}$/', // e.g. 2025/2026
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'semester' => 'required|in:1,2',
        ]);

        $schools = School::all();
        $count = 0;

        DB::beginTransaction();
        try {
            foreach ($schools as $school) {
                // Check if exists
                $exists = DB::table('academic_years')
                    ->where('school_id', $school->id)
                    ->where('name', $validated['name'])
                    ->where('semester', $validated['semester'])
                    ->exists();

                if (! $exists) {
                    DB::table('academic_years')->insert([
                        'school_id' => $school->id,
                        'name' => $validated['name'],
                        'start_date' => $validated['start_date'],
                        'end_date' => $validated['end_date'],
                        'semester' => $validated['semester'],
                        'is_active' => false, // Default inactive, let school admin activate
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $count++;
                }
            }
            DB::commit();

            return response()->json(['success' => true, 'message' => "Academic Year deployed to {$count} schools."]);
        } catch (\Exception $e) {
            DB::rollBack();
            // SECURITY FIX: Log error internally but don't expose to API response
            \Illuminate\Support\Facades\Log::error('Academic year deployment failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'message' => 'Gagal deploy tahun akademik. Silakan coba lagi.'], 500);
        }
    }
}
