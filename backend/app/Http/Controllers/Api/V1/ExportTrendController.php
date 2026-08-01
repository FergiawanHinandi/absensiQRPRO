<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\TrendsExport;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\TrendService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExportTrendController extends Controller
{
    protected TrendService $trendService;

    public function __construct(TrendService $trendService)
    {
        $this->trendService = $trendService;
    }

    /**
     * POST /api/v1/attendance/trends/export
     *
     * Export attendance trend data as Excel or PDF.
     * Query logic delegated to TrendService (shared with AttendanceTrendController).
     *
     * @param Request $request
     * @return JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function export(Request $request)
    {
        $request->validate([
            'format' => 'required|in:excel,pdf',
            'period' => 'required|in:7d,30d,90d',
        ]);

        $user = $request->user();
        $schoolId = $user->school_id;
        $format = $request->input('format');
        $period = $request->input('period');

        $teacherId = null;
        if (in_array($user->role_type, ['teacher', 'homeroom_teacher'])) {
            $teacherId = $user->id;
        }

        // Fetch data once, shared by both Excel and PDF export
        $trendData = $this->trendService->getDailyTrend($schoolId, $period, $teacherId);
        $comparison = $this->trendService->getComparison($schoolId, $teacherId);

        $school = School::find($schoolId);
        $schoolName = $school ? $school->name : 'Sekolah';

        if ($format === 'excel') {
            return $this->exportExcel($trendData, $period, $schoolName);
        }

        return $this->exportPdf($trendData, $comparison, $period, $schoolName);
    }

    /**
     * Export as Excel file using TrendsExport with pre-computed data.
     */
    private function exportExcel(array $trendData, string $period, string $schoolName)
    {
        $export = new TrendsExport(
            $trendData['daily'],
            $trendData['summary'],
            $trendData['startDate']->toDateString(),
            $trendData['endDate']->toDateString(),
            $schoolName,
            $period
        );

        $filename = "tren_kehadiran_{$period}_" . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download($export, $filename);
    }

    /**
     * Export as PDF file using pre-computed data from TrendService.
     */
    private function exportPdf(array $trendData, array $comparison, string $period, string $schoolName)
    {
        $dailyData = $trendData['daily'];
        $summary = $trendData['summary'];
        $startDate = $trendData['startDate'];
        $endDate = $trendData['endDate'];

        $pdf = Pdf::loadView('reports.trends-pdf', [
            'schoolName' => $schoolName,
            'startDate' => $startDate->isoFormat('D MMMM YYYY'),
            'endDate' => $endDate->isoFormat('D MMMM YYYY'),
            'dailyData' => $dailyData,
            'summary' => $summary,
            'comparison' => $comparison,
            'generatedAt' => now()->isoFormat('D MMMM YYYY HH:mm:ss'),
        ]);

        $filename = "tren_kehadiran_{$period}_" . now()->format('Y-m-d_His') . '.pdf';

        return $pdf->download($filename);
    }
}
