<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlowQuery;
use App\Services\QueryProfilingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueryProfilingController extends Controller
{
    public function __construct(
        private QueryProfilingService $profilingService
    ) {}

    /**
     * Get query profiling dashboard data
     */
    public function dashboard(Request $request): JsonResponse
    {
        $days = $request->input('days', 7);
        $schoolId = $request->user()->school_id;

        // Get top slow queries
        $topSlowQueries = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->forSchool($schoolId))
            ->recent($days)
            ->selectRaw('
                sql,
                AVG(execution_time) as avg_time,
                MAX(execution_time) as max_time,
                MIN(execution_time) as min_time,
                SUM(query_count) as total_count,
                MAX(last_seen_at) as last_seen,
                route,
                connection
            ')
            ->groupBy('sql', 'route', 'connection')
            ->orderByDesc('avg_time')
            ->limit(20)
            ->get();

        // Get query frequency over time
        $queryFrequency = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->forSchool($schoolId))
            ->recent($days)
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as query_count,
                AVG(execution_time) as avg_time,
                MAX(execution_time) as max_time
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Get statistics
        $stats = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->forSchool($schoolId))
            ->recent($days)
            ->selectRaw('
                COUNT(*) as total_queries,
                AVG(execution_time) as avg_time,
                MAX(execution_time) as max_time,
                MIN(execution_time) as min_time,
                SUM(query_count) as total_executions
            ')
            ->first();

        // Get queries by route
        $queriesByRoute = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->forSchool($schoolId))
            ->recent($days)
            ->selectRaw('
                route,
                COUNT(*) as query_count,
                AVG(execution_time) as avg_time
            ')
            ->whereNotNull('route')
            ->groupBy('route')
            ->orderByDesc('avg_time')
            ->limit(10)
            ->get();

        // Get index usage statistics (queries without indexes)
        $queriesWithoutIndexes = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->forSchool($schoolId))
            ->recent($days)
            ->where('execution_plan', 'like', '%Full Table Scan%')
            ->orWhere('execution_plan', 'like', '%Seq Scan%')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'top_slow_queries' => $topSlowQueries,
                'query_frequency' => $queryFrequency,
                'statistics' => $stats,
                'queries_by_route' => $queriesByRoute,
                'queries_without_indexes' => $queriesWithoutIndexes,
                'period_days' => $days,
            ],
        ]);
    }

    /**
     * Get detailed information about a specific query
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $schoolId = $request->user()->school_id;

        $query = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->findOrFail($id);

        // Get similar queries (same SQL pattern)
        $similarQueries = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->where('sql', $query->sql)
            ->where('id', '!=', $id)
            ->orderByDesc('execution_time')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'query' => $query,
                'similar_queries' => $similarQueries,
                'execution_plan' => $query->execution_plan ? json_decode($query->execution_plan) : null,
            ],
        ]);
    }

    /**
     * Get real-time query statistics from memory
     */
    public function realtime(): JsonResponse
    {
        $queries = $this->profilingService->getQueries();
        $statistics = $this->profilingService->getStatistics();

        return response()->json([
            'success' => true,
            'data' => [
                'queries' => array_slice($queries, -50), // Last 50 queries
                'statistics' => $statistics,
            ],
        ]);
    }

    /**
     * Get query optimization suggestions
     */
    public function suggestions(Request $request): JsonResponse
    {
        $days = $request->input('days', 7);
        $schoolId = $request->user()->school_id;

        $suggestions = [];

        // Find queries without indexes
        $queriesWithoutIndexes = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->forSchool($schoolId))
            ->recent($days)
            ->where(function ($q) {
                $q->where('execution_plan', 'like', '%Full Table Scan%')
                  ->orWhere('execution_plan', 'like', '%Seq Scan%');
            })
            ->orderByDesc('execution_time')
            ->limit(10)
            ->get();

        foreach ($queriesWithoutIndexes as $query) {
            $suggestions[] = [
                'type' => 'missing_index',
                'severity' => 'high',
                'query_id' => $query->id,
                'sql' => $query->sql,
                'avg_time' => $query->execution_time,
                'suggestion' => 'Consider adding an index to improve query performance',
                'tables' => $this->extractTables($query->sql),
            ];
        }

        // Find queries with high execution time
        $slowQueries = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->forSchool($schoolId))
            ->recent($days)
            ->slowerThan(500)
            ->orderByDesc('execution_time')
            ->limit(10)
            ->get();

        foreach ($slowQueries as $query) {
            $suggestions[] = [
                'type' => 'slow_query',
                'severity' => $query->execution_time > 1000 ? 'critical' : 'medium',
                'query_id' => $query->id,
                'sql' => $query->sql,
                'avg_time' => $query->execution_time,
                'suggestion' => 'Query execution time exceeds threshold. Consider optimization or caching.',
            ];
        }

        // Find frequently executed slow queries
        $frequentSlowQueries = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->forSchool($schoolId))
            ->recent($days)
            ->where('query_count', '>', 100)
            ->slowerThan(100)
            ->orderByDesc('query_count')
            ->limit(10)
            ->get();

        foreach ($frequentSlowQueries as $query) {
            $suggestions[] = [
                'type' => 'frequent_slow_query',
                'severity' => 'high',
                'query_id' => $query->id,
                'sql' => $query->sql,
                'avg_time' => $query->execution_time,
                'execution_count' => $query->query_count,
                'suggestion' => 'Frequently executed slow query. High impact optimization opportunity.',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'suggestions' => $suggestions,
                'total_suggestions' => count($suggestions),
            ],
        ]);
    }

    /**
     * Export slow queries report
     */
    public function export(Request $request): JsonResponse
    {
        $days = $request->input('days', 7);
        $schoolId = $request->user()->school_id;

        $queries = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->forSchool($schoolId))
            ->recent($days)
            ->orderByDesc('execution_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'queries' => $queries,
                'exported_at' => now(),
                'period_days' => $days,
            ],
        ]);
    }

    /**
     * Clear query profiling data
     */
    public function clear(Request $request): JsonResponse
    {
        $schoolId = $request->user()->school_id;

        // Clear from database
        $deletedCount = SlowQuery::query()
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->delete();

        // Clear from memory
        $this->profilingService->clear();

        return response()->json([
            'success' => true,
            'message' => "Cleared {$deletedCount} query records",
        ]);
    }

    /**
     * Extract table names from SQL query
     */
    private function extractTables(string $sql): array
    {
        preg_match_all('/(?:from|join)\s+`?(\w+)`?/i', $sql, $matches);
        return array_unique($matches[1] ?? []);
    }
}
