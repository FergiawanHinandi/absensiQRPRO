<?php

namespace App\Console\Commands;

use App\Models\SlowQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeSlowQueriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queries:analyze {--days=7 : Number of days to analyze} {--limit=20 : Number of queries to show}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze slow query patterns and provide optimization recommendations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');

        $this->info("Analyzing slow queries from the last {$days} days...\n");

        // 1. Top slow queries by average execution time
        $this->analyzeTopSlowQueries($days, $limit);

        // 2. Most frequent slow queries
        $this->analyzeMostFrequentQueries($days, $limit);

        // 3. Queries without indexes
        $this->analyzeQueriesWithoutIndexes($days, $limit);

        // 4. Queries by route
        $this->analyzeQueriesByRoute($days, $limit);

        // 5. Query patterns
        $this->analyzeQueryPatterns($days);

        // 6. Optimization opportunities
        $this->provideOptimizationRecommendations($days);

        return Command::SUCCESS;
    }

    /**
     * Analyze top slow queries by average execution time
     */
    private function analyzeTopSlowQueries(int $days, int $limit): void
    {
        $this->info("📊 Top {$limit} Slow Queries by Average Execution Time:");
        $this->newLine();

        $queries = SlowQuery::query()
            ->recent($days)
            ->selectRaw('
                sql,
                AVG(execution_time) as avg_time,
                MAX(execution_time) as max_time,
                MIN(execution_time) as min_time,
                SUM(query_count) as total_count,
                connection
            ')
            ->groupBy('sql', 'connection')
            ->orderByDesc('avg_time')
            ->limit($limit)
            ->get();

        if ($queries->isEmpty()) {
            $this->warn("No slow queries found in the last {$days} days.");
            $this->newLine();
            return;
        }

        $tableData = [];
        foreach ($queries as $index => $query) {
            $tableData[] = [
                '#' => $index + 1,
                'Avg Time' => round($query->avg_time, 2) . 'ms',
                'Max Time' => round($query->max_time, 2) . 'ms',
                'Count' => $query->total_count,
                'SQL' => $this->truncateQuery($query->sql, 80),
            ];
        }

        $this->table(
            ['#', 'Avg Time', 'Max Time', 'Count', 'SQL'],
            $tableData
        );

        $this->newLine();
    }

    /**
     * Analyze most frequent slow queries
     */
    private function analyzeMostFrequentQueries(int $days, int $limit): void
    {
        $this->info("🔄 Top {$limit} Most Frequent Slow Queries:");
        $this->newLine();

        $queries = SlowQuery::query()
            ->recent($days)
            ->selectRaw('
                sql,
                AVG(execution_time) as avg_time,
                SUM(query_count) as total_count,
                route
            ')
            ->groupBy('sql', 'route')
            ->orderByDesc('total_count')
            ->limit($limit)
            ->get();

        if ($queries->isEmpty()) {
            $this->warn("No frequent slow queries found.");
            $this->newLine();
            return;
        }

        $tableData = [];
        foreach ($queries as $index => $query) {
            $tableData[] = [
                '#' => $index + 1,
                'Count' => $query->total_count,
                'Avg Time' => round($query->avg_time, 2) . 'ms',
                'Route' => $query->route ?? 'N/A',
                'SQL' => $this->truncateQuery($query->sql, 60),
            ];
        }

        $this->table(
            ['#', 'Count', 'Avg Time', 'Route', 'SQL'],
            $tableData
        );

        $this->newLine();
    }

    /**
     * Analyze queries without indexes
     */
    private function analyzeQueriesWithoutIndexes(int $days, int $limit): void
    {
        $this->info("⚠️  Queries Without Proper Indexes:");
        $this->newLine();

        $queries = SlowQuery::query()
            ->recent($days)
            ->where(function ($q) {
                $q->where('execution_plan', 'like', '%Full Table Scan%')
                  ->orWhere('execution_plan', 'like', '%Seq Scan%')
                  ->orWhere('execution_plan', 'like', '%ALL%');
            })
            ->selectRaw('
                sql,
                AVG(execution_time) as avg_time,
                SUM(query_count) as total_count
            ')
            ->groupBy('sql')
            ->orderByDesc('avg_time')
            ->limit($limit)
            ->get();

        if ($queries->isEmpty()) {
            $this->info("✅ All queries are using indexes properly!");
            $this->newLine();
            return;
        }

        $tableData = [];
        foreach ($queries as $index => $query) {
            $tables = $this->extractTables($query->sql);
            $tableData[] = [
                '#' => $index + 1,
                'Avg Time' => round($query->avg_time, 2) . 'ms',
                'Count' => $query->total_count,
                'Tables' => implode(', ', $tables),
                'SQL' => $this->truncateQuery($query->sql, 60),
            ];
        }

        $this->table(
            ['#', 'Avg Time', 'Count', 'Tables', 'SQL'],
            $tableData
        );

        $this->warn("💡 Consider adding indexes to the tables listed above.");
        $this->newLine();
    }

    /**
     * Analyze queries by route
     */
    private function analyzeQueriesByRoute(int $days, int $limit): void
    {
        $this->info("🛣️  Slow Queries by Route:");
        $this->newLine();

        $routes = SlowQuery::query()
            ->recent($days)
            ->whereNotNull('route')
            ->selectRaw('
                route,
                COUNT(DISTINCT sql) as unique_queries,
                AVG(execution_time) as avg_time,
                SUM(query_count) as total_count
            ')
            ->groupBy('route')
            ->orderByDesc('avg_time')
            ->limit($limit)
            ->get();

        if ($routes->isEmpty()) {
            $this->warn("No route information available.");
            $this->newLine();
            return;
        }

        $tableData = [];
        foreach ($routes as $index => $route) {
            $tableData[] = [
                '#' => $index + 1,
                'Route' => $route->route,
                'Unique Queries' => $route->unique_queries,
                'Avg Time' => round($route->avg_time, 2) . 'ms',
                'Total Count' => $route->total_count,
            ];
        }

        $this->table(
            ['#', 'Route', 'Unique Queries', 'Avg Time', 'Total Count'],
            $tableData
        );

        $this->newLine();
    }

    /**
     * Analyze query patterns
     */
    private function analyzeQueryPatterns(int $days): void
    {
        $this->info("🔍 Query Pattern Analysis:");
        $this->newLine();

        // Count queries by type
        $patterns = [
            'SELECT' => 0,
            'INSERT' => 0,
            'UPDATE' => 0,
            'DELETE' => 0,
            'JOIN' => 0,
            'SUBQUERY' => 0,
        ];

        $queries = SlowQuery::recent($days)->get();

        foreach ($queries as $query) {
            $sql = strtoupper($query->sql);
            
            if (str_contains($sql, 'SELECT')) $patterns['SELECT']++;
            if (str_contains($sql, 'INSERT')) $patterns['INSERT']++;
            if (str_contains($sql, 'UPDATE')) $patterns['UPDATE']++;
            if (str_contains($sql, 'DELETE')) $patterns['DELETE']++;
            if (str_contains($sql, 'JOIN')) $patterns['JOIN']++;
            if (preg_match('/\(SELECT/', $sql)) $patterns['SUBQUERY']++;
        }

        $tableData = [];
        foreach ($patterns as $pattern => $count) {
            if ($count > 0) {
                $tableData[] = [
                    'Pattern' => $pattern,
                    'Count' => $count,
                    'Percentage' => round(($count / $queries->count()) * 100, 1) . '%',
                ];
            }
        }

        $this->table(['Pattern', 'Count', 'Percentage'], $tableData);
        $this->newLine();
    }

    /**
     * Provide optimization recommendations
     */
    private function provideOptimizationRecommendations(int $days): void
    {
        $this->info("💡 Optimization Recommendations:");
        $this->newLine();

        $recommendations = [];

        // Check for N+1 queries
        $frequentQueries = SlowQuery::query()
            ->recent($days)
            ->where('query_count', '>', 100)
            ->count();

        if ($frequentQueries > 0) {
            $recommendations[] = "• Found {$frequentQueries} frequently executed queries. Consider eager loading relationships to prevent N+1 queries.";
        }

        // Check for missing indexes
        $noIndexQueries = SlowQuery::query()
            ->recent($days)
            ->where(function ($q) {
                $q->where('execution_plan', 'like', '%Full Table Scan%')
                  ->orWhere('execution_plan', 'like', '%Seq Scan%');
            })
            ->count();

        if ($noIndexQueries > 0) {
            $recommendations[] = "• Found {$noIndexQueries} queries without proper indexes. Add indexes to improve performance.";
        }

        // Check for very slow queries
        $criticalQueries = SlowQuery::query()
            ->recent($days)
            ->where('execution_time', '>', 1000)
            ->count();

        if ($criticalQueries > 0) {
            $recommendations[] = "• Found {$criticalQueries} critical slow queries (>1s). These require immediate optimization.";
        }

        // Check for queries with subqueries
        $subqueryCount = SlowQuery::query()
            ->recent($days)
            ->where('sql', 'like', '%(SELECT%')
            ->count();

        if ($subqueryCount > 0) {
            $recommendations[] = "• Found {$subqueryCount} queries with subqueries. Consider using JOINs or CTEs for better performance.";
        }

        // Check for SELECT *
        $selectAllCount = SlowQuery::query()
            ->recent($days)
            ->where('sql', 'like', '%SELECT *%')
            ->count();

        if ($selectAllCount > 0) {
            $recommendations[] = "• Found {$selectAllCount} queries using SELECT *. Specify only needed columns to reduce data transfer.";
        }

        if (empty($recommendations)) {
            $this->info("✅ No major optimization opportunities found. Your queries are performing well!");
        } else {
            foreach ($recommendations as $recommendation) {
                $this->line($recommendation);
            }
        }

        $this->newLine();
        $this->info("📝 For detailed query information, use: php artisan queries:analyze --days={$days} --limit=50");
    }

    /**
     * Truncate query for display
     */
    private function truncateQuery(string $sql, int $length = 80): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql);
        return strlen($sql) > $length ? substr($sql, 0, $length) . '...' : $sql;
    }

    /**
     * Extract table names from SQL
     */
    private function extractTables(string $sql): array
    {
        preg_match_all('/(?:from|join)\s+`?(\w+)`?/i', $sql, $matches);
        return array_unique($matches[1] ?? []);
    }
}
