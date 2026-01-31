<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboardService;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    use \App\Traits\UsesCacheTags;

    public function __construct(
        private AdminDashboardService $dashboardService
    ) {}

    public function classAttendance(Request $request)
    {
        $date = $request->query('date');
        $schoolId = $request->user()->school_id;
        $key = "school_stats_class_attendance_{$schoolId}_".($date ?? 'today');
        $payload = $this->cacheWithTags(['dashboard', "school_{$schoolId}"], $key, 300, function () use ($request, $date) {
            $data = $this->dashboardService->getClassAttendanceSummary($request->user(), $date);
            $data['classes'] = collect($data['classes'] ?? [])->map(function ($item) {
                return array_merge($item, [
                    'permission' => $item['permit'] ?? 0,
                ]);
            })->values();

            return $data;
        });

        return response()->success($payload);
    }

    public function teacherAbsent(Request $request)
    {
        $date = $request->query('date');
        $schoolId = $request->user()->school_id;
        $key = "school_stats_teacher_absent_{$schoolId}_".($date ?? 'today');
        $payload = $this->cacheWithTags(['dashboard', "school_{$schoolId}"], $key, 300, function () use ($request, $date) {
            return $this->dashboardService->getTeacherAbsence($request->user(), $date);
        });

        return response()->success($payload);
    }

    public function lateAlpha(Request $request)
    {
        $date = $request->query('date');
        $schoolId = $request->user()->school_id;
        $key = "school_stats_late_alpha_{$schoolId}_".($date ?? 'today');
        $payload = $this->cacheWithTags(['dashboard', "school_{$schoolId}"], $key, 300, function () use ($request, $date) {
            return $this->dashboardService->getLateAlpha($request->user(), $date);
        });

        return response()->success($payload);
    }

    public function anomalies(Request $request)
    {
        $date = $request->query('date');
        $schoolId = $request->user()->school_id;
        $key = "school_stats_anomalies_{$schoolId}_".($date ?? 'today');
        $payload = $this->cacheWithTags(['dashboard', "school_{$schoolId}"], $key, 300, function () use ($request, $date) {
            return $this->dashboardService->getAttendanceAnomalies($request->user(), $date);
        });

        return response()->success($payload);
    }
}
