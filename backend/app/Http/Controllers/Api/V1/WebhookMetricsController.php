<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WebhookMonitoringService;
use Illuminate\Http\Request;

class WebhookMetricsController extends Controller
{
    /**
     * Get current webhook metrics (last hour)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function current()
    {
        $metrics = WebhookMonitoringService::getCurrentMetrics();

        return response()->json([
            'success' => true,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Get webhook metrics for a specific hour
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function byHour(Request $request)
    {
        $request->validate([
            'hour' => 'required|date_format:Y-m-d-H',
        ]);

        $metrics = WebhookMonitoringService::getMetrics($request->input('hour'));

        return response()->json([
            'success' => true,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Get webhook metrics for the last 24 hours
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function last24Hours()
    {
        $metrics = [];
        $now = now();

        for ($i = 0; $i < 24; $i++) {
            $hour = $now->copy()->subHours($i)->format('Y-m-d-H');
            $metrics[] = WebhookMonitoringService::getMetrics($hour);
        }

        return response()->json([
            'success' => true,
            'metrics' => array_reverse($metrics),
        ]);
    }
}
