<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Services\GroupTruancyDetectionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GroupTruancyController extends Controller
{
    protected GroupTruancyDetectionService $truancyService;

    public function __construct(GroupTruancyDetectionService $truancyService)
    {
        $this->truancyService = $truancyService;
    }

    /**
     * Detect possible group truancy for classes in a given month.
     * @param Request $request
     * @return JsonResponse
     */
    public function detect(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $month = $request->input('month'); // optional, format: YYYY-MM

        $result = $this->truancyService->detect($schoolId, $month);

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Group truancy detection completed.'
        ]);
    }
}
