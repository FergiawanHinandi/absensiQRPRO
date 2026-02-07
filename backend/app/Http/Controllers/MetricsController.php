<?php

namespace App\Http\Controllers;

use App\Services\PrometheusMetricsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __construct(
        protected PrometheusMetricsService $metricsService
    ) {}

    /**
     * Prometheus metrics endpoint
     * 
     * @return Response
     */
    public function prometheus(): Response
    {
        $metrics = $this->metricsService->collect();

        return response($metrics, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /**
     * JSON metrics endpoint (for debugging/dashboards)
     */
    public function json(): \Illuminate\Http\JsonResponse
    {
        $metrics = $this->collectMetricsAsArray();

        return response()->json([
            'timestamp' => now()->toISOString(),
            'metrics' => $metrics,
        ]);
    }

    /**
     * Collect metrics as structured array
     */
    protected function collectMetricsAsArray(): array
    {
        // This would parse the Prometheus output or collect directly
        // Simplified version for JSON output
        return [
            'api' => [
                'requests_total' => cache('prometheus:requests')['requests'] ?? [],
                'errors_total' => cache('prometheus:requests')['errors'] ?? 0,
            ],
            'database' => cache('prometheus:db_queries') ?? [],
            'queue' => [
                'jobs' => cache('prometheus:job_metrics') ?? [],
            ],
        ];
    }
}
