<?php

namespace App\Services;

use App\Models\SlowQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QueryProfilingService
{
    private array $queries = [];
    private int $maxStoredQueries;
    private float $slowQueryThreshold;
    private bool $logExecutionPlans;
    private bool $storeInDatabase;
    private array $excludePatterns;
    private float $sampleRate;

    public function __construct()
    {
        $this->maxStoredQueries = config('query_profiling.max_stored_queries', 1000);
        $this->slowQueryThreshold = config('query_profiling.slow_query_threshold', 100);
        $this->logExecutionPlans = config('query_profiling.log_execution_plans', true);
        $this->storeInDatabase = config('query_profiling.store_in_database', true);
        $this->excludePatterns = config('query_profiling.exclude_patterns', []);
        $this->sampleRate = config('query_profiling.sample_rate', 1.0);
    }

    /**
     * Start query profiling
     */
    public function start(): void
    {
        if (!config('query_profiling.enabled', false)) {
            return;
        }

        DB::listen(function ($query) {
            $this->logQuery($query);
        });
    }

    /**
     * Log a query if it exceeds the slow query threshold
     */
    private function logQuery($query): void
    {
        // Sample rate check
        if ($this->sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $this->sampleRate) {
            return;
        }

        $executionTime = $query->time; // milliseconds

        // Skip if below threshold
        if ($executionTime < $this->slowQueryThreshold) {
            return;
        }

        // Skip excluded patterns
        if ($this->shouldExclude($query->sql)) {
            return;
        }

        $queryData = [
            'sql' => $query->sql,
            'bindings' => $query->bindings,
            'execution_time' => $executionTime,
            'connection' => $query->connectionName,
            'request_id' => request()->header('X-Request-ID') ?? Str::uuid()->toString(),
            'user_id' => auth()->id(),
            'school_id' => auth()->user()->school_id ?? null,
            'route' => request()->route()?->getName(),
            'method' => request()->method(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];

        // Get execution plan if enabled
        if ($this->logExecutionPlans) {
            $queryData['execution_plan'] = $this->getExecutionPlan($query->sql, $query->bindings, $query->connectionName);
        }

        // Store in memory
        $this->storeInMemory($queryData);

        // Store in database
        if ($this->storeInDatabase) {
            $this->storeInDb($queryData);
        }

        // Log to file
        $this->logToFile($queryData);

        // Check alert thresholds
        $this->checkAlertThresholds($queryData);
    }

    /**
     * Check if query should be excluded
     */
    private function shouldExclude(string $sql): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if (stripos($sql, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get query execution plan
     */
    private function getExecutionPlan(string $sql, array $bindings, string $connection): ?string
    {
        try {
            $driver = DB::connection($connection)->getDriverName();
            
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $plan = DB::connection($connection)
                    ->select("EXPLAIN $sql", $bindings);
            } elseif ($driver === 'pgsql') {
                $plan = DB::connection($connection)
                    ->select("EXPLAIN (FORMAT JSON) $sql", $bindings);
            } else {
                return null;
            }

            return json_encode($plan);
        } catch (\Exception $e) {
            Log::warning('Failed to get execution plan', [
                'error' => $e->getMessage(),
                'sql' => $sql,
            ]);
            return null;
        }
    }

    /**
     * Store query in memory
     */
    private function storeInMemory(array $queryData): void
    {
        if (count($this->queries) >= $this->maxStoredQueries) {
            array_shift($this->queries);
        }

        $this->queries[] = $queryData;
    }

    /**
     * Store query in database
     */
    private function storeInDb(array $queryData): void
    {
        try {
            // Check if similar query exists (same SQL)
            $existing = SlowQuery::where('sql', $queryData['sql'])
                ->where('school_id', $queryData['school_id'])
                ->whereDate('created_at', today())
                ->first();

            if ($existing) {
                // Update existing record
                $existing->update([
                    'query_count' => $existing->query_count + 1,
                    'execution_time' => ($existing->execution_time * $existing->query_count + $queryData['execution_time']) / ($existing->query_count + 1),
                    'last_seen_at' => now(),
                ]);
            } else {
                // Create new record
                SlowQuery::create($queryData);
            }
        } catch (\Exception $e) {
            Log::error('Failed to store slow query in database', [
                'error' => $e->getMessage(),
                'sql' => $queryData['sql'],
            ]);
        }
    }

    /**
     * Log query to file
     */
    private function logToFile(array $queryData): void
    {
        Log::channel(config('query_profiling.log_channel', 'daily'))
            ->warning('Slow query detected', [
                'sql' => $queryData['sql'],
                'execution_time' => $queryData['execution_time'] . 'ms',
                'route' => $queryData['route'],
                'school_id' => $queryData['school_id'],
                'request_id' => $queryData['request_id'],
            ]);
    }

    /**
     * Check alert thresholds
     */
    private function checkAlertThresholds(array $queryData): void
    {
        $criticalThreshold = config('query_profiling.alerts.critical_threshold', 1000);
        $warningThreshold = config('query_profiling.alerts.warning_threshold', 500);

        if ($queryData['execution_time'] >= $criticalThreshold) {
            Log::channel(config('query_profiling.alerts.alert_channel', 'slack'))
                ->critical('Critical slow query detected', [
                    'sql' => Str::limit($queryData['sql'], 200),
                    'execution_time' => $queryData['execution_time'] . 'ms',
                    'route' => $queryData['route'],
                    'school_id' => $queryData['school_id'],
                ]);
        } elseif ($queryData['execution_time'] >= $warningThreshold) {
            Log::channel(config('query_profiling.alerts.alert_channel', 'slack'))
                ->warning('Warning: slow query detected', [
                    'sql' => Str::limit($queryData['sql'], 200),
                    'execution_time' => $queryData['execution_time'] . 'ms',
                    'route' => $queryData['route'],
                ]);
        }
    }

    /**
     * Get stored queries from memory
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Get query statistics
     */
    public function getStatistics(): array
    {
        $queries = $this->queries;

        if (empty($queries)) {
            return [
                'total_queries' => 0,
                'avg_time' => 0,
                'max_time' => 0,
                'min_time' => 0,
            ];
        }

        $times = array_column($queries, 'execution_time');

        return [
            'total_queries' => count($queries),
            'avg_time' => round(array_sum($times) / count($times), 2),
            'max_time' => max($times),
            'min_time' => min($times),
            'slowest_queries' => array_slice(
                array_reverse(array_sort($queries, fn($q) => $q['execution_time'])),
                0,
                10
            ),
        ];
    }

    /**
     * Clear stored queries from memory
     */
    public function clear(): void
    {
        $this->queries = [];
    }
}
