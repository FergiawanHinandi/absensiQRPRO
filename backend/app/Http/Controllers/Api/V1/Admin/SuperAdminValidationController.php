<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminValidationController extends Controller
{
    /**
     * Bulk delete schools with protection.
     */
    public function bulkDeleteSchools(Request $request)
    {
        $request->validate([
            'school_ids' => 'required|array',
            'school_ids.*' => 'exists:schools,id',
            'confirmation' => 'required|string',
        ]);

        $ids = $request->input('school_ids');
        $confirmation = $request->input('confirmation');

        // Logic to verify confirmation string matches requirements
        // "Require text confirmation: type the school name"
        // If single school, match name. If multiple, maybe "DELETE [count] SCHOOLS"?
        // Prompt says "type the school name". Assuming strict check for single, generic for bulk.
        
        if (count($ids) === 1) {
            $school = School::find($ids[0]);
            if ($confirmation !== $school->name) {
                return response()->json([
                    'message' => 'Confirmation failed. Please type the exact school name to delete.',
                    'expected' => $school->name
                ], 400);
            }
        } else {
            if ($confirmation !== 'DELETE SCHOOLS') {
                 return response()->json([
                    'message' => 'Confirmation failed. Please type "DELETE SCHOOLS" to confirm bulk deletion.',
                ], 400);
            }
        }

        // Soft Delete
        try {
            School::whereIn('id', $ids)->delete(); // Soft delete via Trait
            
            return response()->json([
                'success' => true,
                'message' => count($ids) . ' schools moved to trash (Soft Deleted).',
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Deletion failed: ' . $e->getMessage()], 500);
        }
    }
}
