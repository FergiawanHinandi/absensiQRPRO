<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyzeSlowQueries extends Command
{
    protected $signature = 'db:analyze-slow-queries 
                            {--output=console : Output format (console, json, markdown)}
                            {--threshold=100 : Query time threshold in milliseconds}
                            {--tables= : Comma-separated list of tables to analyze}';

    protected $description = 'Analyze database for potential slow queries and missing indexes';

    private array $findings = [];
    private array $recommendations = [];

    public function handle(): int
    {
        $this->info('🔍 Starting Slow Query Analysis...');
        $this->newLine();

        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            $this->analyzePostgreSQL();
        } elseif ($driver === 'mysql') {
            $this->analyzeMySQL();
        } elseif ($driver === 'sqlite') {
            $this->analyzeSQLite();
        } else {
            $this->error("Unsupported database driver: {$driver}");
            return self::FAILURE;
        }

        $this->displayResults();
        
        return self::SUCCESS;
    }

    private function analyzePostgreSQL(): void
    {
        $this->info('📊 Analyzing PostgreSQL database...');
        $this->newLine();

        // 1. Check if pg_stat_statements extension is available
        $this->checkPgStatStatements();

        // 2. Analyze table statistics
        $this->analyzeTableStatistics();

        // 3. Check for missing indexes
        $this->checkMissingIndexes();

        // 4. Analyze sequential scans
        $this->analyzeSequentialScans();

        // 5. Check index usage
        $this->analyzeIndexUsage();

        // 6. Analyze table bloat
        $this->analyzeTableBloat();
    }

    private function analyzeMySQL(): void
    {
        $this->info('📊 Analyzing MySQL database...');
        $this->newLine();

        // Check if slow query log is enabled
        $this->checkSlowQueryLog();

        // Analyze table statistics
        $this->analyzeTableStatistics();

        // Check for missing indexes
        $this->checkMissingIndexes();
    }

    private function analyzeSQLite(): void
    {
        $this->info('📊 Analyzing SQLite database...');
        $this->newLine();

        $this->warn('SQLite has limited query analysis capabilities.');
        $this->warn('Consider using PostgreSQL or MySQL for production.');
        $this->newLine();

        // Basic table analysis
        $this->analyzeTableStatistics();
    }

    private function checkPgStatStatements(): void
    {
        try {
            $result = DB::select("SELECT * FROM pg_extension WHERE extname = 'pg_stat_statements'");
            
            if (empty($result)) {
                $this->findings[] = [
                    'category' => 'Configuration',
                    'severity' => 'HIGH',
                    'issue' => 'pg_stat_statements extension not installed',
                    'impact' => 'Cannot track query performance statistics',
                    'recommendation' => 'Install extension: CREATE EXTENSION pg_stat_statements;'
                ];
            } else {
                $this->info('✅ pg_stat_statements extension is installed');
                
                // Get slow queries from pg_stat_statements
                $slowQueries = DB::select("
                    SELECT 
                        query,
                        calls,
                        total_exec_time,
                        mean_exec_time,
                        max_exec_time
                    FROM pg_stat_statements
                    WHERE mean_exec_time > ?
                    ORDER BY mean_exec_time DESC
                    LIMIT 20
                ", [$this->option('threshold')]);

                if (!empty($slowQueries)) {
                    $this->warn('⚠️  Found ' . count($slowQueries) . ' slow queries');
                    foreach ($slowQueries as $query) {
                        $this->findings[] = [
                            'category' => 'Slow Query',
                            'severity' => $query->mean_exec_time > 1000 ? 'CRITICAL' : 'HIGH',
                            'issue' => 'Query exceeds threshold',
                            'query' => substr($query->query, 0, 100) . '...',
                            'calls' => $query->calls,
                            'mean_time' => round($query->mean_exec_time, 2) . 'ms',
                            'max_time' => round($query->max_exec_time, 2) . 'ms',
                            'recommendation' => 'Optimize query or add appropriate indexes'
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->warn('Could not check pg_stat_statements: ' . $e->getMessage());
        }
    }

    private function analyzeTableStatistics(): void
    {
        $this->info('📈 Analyzing table statistics...');
        
        $tables = $this->option('tables') 
            ? explode(',', $this->option('tables'))
            : $this->getRelevantTables();

        foreach ($tables as $table) {
            $table = trim($table);
            
            if (!Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->count();
            $columns = Schema::getColumns($table);
            $indexes = Schema::getIndexes($table);

            $this->line("  📋 Table: {$table}");
            $this->line("     Rows: " . number_format($count));
            $this->line("     Columns: " . count($columns));
            $this->line("     Indexes: " . count($indexes));

            // Check for large tables without proper indexes
            if ($count > 10000 && count($indexes) <= 1) {
                $this->findings[] = [
                    'category' => 'Missing Indexes',
                    'severity' => 'HIGH',
                    'table' => $table,
                    'issue' => "Large table ({$count} rows) with insufficient indexes",
                    'recommendation' => 'Add indexes on frequently queried columns'
                ];
            }

            $this->newLine();
        }
    }

    private function checkMissingIndexes(): void
    {
        $this->info('🔎 Checking for missing indexes on foreign keys...');
        
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            $this->checkMissingIndexesPostgreSQL();
        } elseif ($driver === 'mysql') {
            $this->checkMissingIndexesMySQL();
        }
    }

    private function checkMissingIndexesPostgreSQL(): void
    {
        try {
            $unindexedFKs = DB::select("
                SELECT 
                    c.conrelid::regclass AS table_name,
                    a.attname AS column_name,
                    c.confrelid::regclass AS referenced_table
                FROM pg_constraint c
                JOIN pg_attribute a ON a.attnum = ANY(c.conkey) AND a.attrelid = c.conrelid
                WHERE c.contype = 'f'
                AND NOT EXISTS (
                    SELECT 1 FROM pg_index i
                    WHERE i.indrelid = c.conrelid
                    AND a.attnum = ANY(i.indkey)
                )
                AND c.conrelid::regclass::text NOT LIKE 'pg_%'
                ORDER BY c.conrelid::regclass::text
            ");

            foreach ($unindexedFKs as $fk) {
                $this->findings[] = [
                    'category' => 'Missing Index',
                    'severity' => 'HIGH',
                    'table' => $fk->table_name,
                    'column' => $fk->column_name,
                    'issue' => 'Foreign key without index',
                    'recommendation' => "CREATE INDEX idx_{$fk->table_name}_{$fk->column_name} ON {$fk->table_name}({$fk->column_name});"
                ];
            }

            if (empty($unindexedFKs)) {
                $this->info('✅ All foreign keys have indexes');
            } else {
                $this->warn('⚠️  Found ' . count($unindexedFKs) . ' unindexed foreign keys');
            }
        } catch (\Exception $e) {
            $this->warn('Could not check missing indexes: ' . $e->getMessage());
        }
    }

    private function checkMissingIndexesMySQL(): void
    {
        // MySQL-specific index analysis
        $this->warn('MySQL index analysis not yet implemented');
    }

    private function analyzeSequentialScans(): void
    {
        $this->info('🔄 Analyzing sequential scans...');
        
        try {
            $seqScans = DB::select("
                SELECT 
                    schemaname,
                    relname as tablename,
                    seq_scan,
                    seq_tup_read,
                    idx_scan,
                    idx_tup_fetch,
                    n_live_tup
                FROM pg_stat_user_tables
                WHERE seq_scan > 0
                AND n_live_tup > 10000
                ORDER BY seq_scan DESC
                LIMIT 20
            ");

            foreach ($seqScans as $scan) {
                $seqRatio = $scan->idx_scan > 0 
                    ? $scan->seq_scan / ($scan->seq_scan + $scan->idx_scan) 
                    : 1;

                if ($seqRatio > 0.5 && $scan->n_live_tup > 10000) {
                    $this->findings[] = [
                        'category' => 'Sequential Scan',
                        'severity' => 'MEDIUM',
                        'table' => $scan->tablename,
                        'seq_scans' => number_format($scan->seq_scan),
                        'rows' => number_format($scan->n_live_tup),
                        'issue' => 'High sequential scan ratio on large table',
                        'recommendation' => 'Add indexes on frequently filtered columns'
                    ];
                }
            }

            if (empty($seqScans)) {
                $this->info('✅ No problematic sequential scans found');
            }
        } catch (\Exception $e) {
            $this->warn('Could not analyze sequential scans: ' . $e->getMessage());
        }
    }

    private function analyzeIndexUsage(): void
    {
        $this->info('📊 Analyzing index usage...');
        
        try {
            $unusedIndexes = DB::select("
                SELECT 
                    schemaname,
                    relname as tablename,
                    indexrelname as indexname,
                    idx_scan,
                    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
                FROM pg_stat_user_indexes
                WHERE idx_scan = 0
                AND indexrelname NOT LIKE '%_pkey'
                ORDER BY pg_relation_size(indexrelid) DESC
                LIMIT 20
            ");

            foreach ($unusedIndexes as $index) {
                $this->findings[] = [
                    'category' => 'Unused Index',
                    'severity' => 'LOW',
                    'table' => $index->tablename,
                    'index' => $index->indexname,
                    'size' => $index->index_size,
                    'issue' => 'Index never used',
                    'recommendation' => "Consider dropping: DROP INDEX {$index->indexname};"
                ];
            }

            if (empty($unusedIndexes)) {
                $this->info('✅ All indexes are being used');
            } else {
                $this->warn('⚠️  Found ' . count($unusedIndexes) . ' unused indexes');
            }
        } catch (\Exception $e) {
            $this->warn('Could not analyze index usage: ' . $e->getMessage());
        }
    }

    private function analyzeTableBloat(): void
    {
        $this->info('💾 Analyzing table bloat...');
        
        try {
            $bloatedTables = DB::select("
                SELECT 
                    schemaname,
                    relname as tablename,
                    pg_size_pretty(pg_total_relation_size(schemaname||'.'||relname)) AS total_size,
                    n_dead_tup,
                    n_live_tup,
                    ROUND(100 * n_dead_tup / NULLIF(n_live_tup + n_dead_tup, 0), 2) AS dead_ratio
                FROM pg_stat_user_tables
                WHERE n_dead_tup > 1000
                AND n_live_tup > 0
                ORDER BY n_dead_tup DESC
                LIMIT 20
            ");

            foreach ($bloatedTables as $table) {
                if ($table->dead_ratio > 20) {
                    $this->findings[] = [
                        'category' => 'Table Bloat',
                        'severity' => 'MEDIUM',
                        'table' => $table->tablename,
                        'dead_tuples' => number_format($table->n_dead_tup),
                        'dead_ratio' => $table->dead_ratio . '%',
                        'size' => $table->total_size,
                        'issue' => 'High dead tuple ratio',
                        'recommendation' => "Run VACUUM ANALYZE {$table->tablename};"
                    ];
                }
            }

            if (empty($bloatedTables)) {
                $this->info('✅ No significant table bloat detected');
            }
        } catch (\Exception $e) {
            $this->warn('Could not analyze table bloat: ' . $e->getMessage());
        }
    }

    private function checkSlowQueryLog(): void
    {
        try {
            $slowLogEnabled = DB::select("SHOW VARIABLES LIKE 'slow_query_log'");
            
            if (empty($slowLogEnabled) || $slowLogEnabled[0]->Value === 'OFF') {
                $this->findings[] = [
                    'category' => 'Configuration',
                    'severity' => 'HIGH',
                    'issue' => 'Slow query log is disabled',
                    'recommendation' => 'Enable slow query log: SET GLOBAL slow_query_log = 1;'
                ];
            }
        } catch (\Exception $e) {
            $this->warn('Could not check slow query log: ' . $e->getMessage());
        }
    }

    private function getRelevantTables(): array
    {
        return [
            'attendances',
            'attendance_logs',
            'attendance_summaries',
            'users',
            'schools',
            'schedules',
            'classes',
            'students',
            'teachers',
            'qr_codes',
            'payments',
            'subscriptions',
            'notifications',
            'audit_logs',
            'security_events'
        ];
    }

    private function displayResults(): void
    {
        $this->newLine(2);
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('                    ANALYSIS RESULTS                        ');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        if (empty($this->findings)) {
            $this->info('✅ No significant issues found!');
            return;
        }

        $output = $this->option('output');

        if ($output === 'json') {
            $this->line(json_encode($this->findings, JSON_PRETTY_PRINT));
            return;
        }

        if ($output === 'markdown') {
            $this->outputMarkdown();
            return;
        }

        // Console output (default)
        $this->outputConsole();
    }

    private function outputConsole(): void
    {
        $grouped = collect($this->findings)->groupBy('category');

        foreach ($grouped as $category => $findings) {
            $this->newLine();
            $this->info("📌 {$category} (" . count($findings) . " issues)");
            $this->line(str_repeat('─', 60));

            foreach ($findings as $finding) {
                $severity = $finding['severity'];
                $color = match($severity) {
                    'CRITICAL' => 'red',
                    'HIGH' => 'yellow',
                    'MEDIUM' => 'blue',
                    default => 'gray'
                };

                $this->newLine();
                $this->line("  <fg={$color}>● {$severity}</>");
                
                foreach ($finding as $key => $value) {
                    if (in_array($key, ['category', 'severity'])) {
                        continue;
                    }
                    $this->line("    <fg=gray>{$key}:</> {$value}");
                }
            }
        }

        $this->newLine(2);
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('Total Issues: ' . count($this->findings));
        $this->info('═══════════════════════════════════════════════════════════');
    }

    private function outputMarkdown(): void
    {
        $md = "# Slow Query Analysis Report\n\n";
        $md .= "**Generated:** " . now()->toDateTimeString() . "\n\n";
        $md .= "**Database:** " . DB::connection()->getDatabaseName() . "\n\n";
        $md .= "**Driver:** " . DB::connection()->getDriverName() . "\n\n";
        $md .= "---\n\n";

        $grouped = collect($this->findings)->groupBy('category');

        foreach ($grouped as $category => $findings) {
            $md .= "## {$category}\n\n";
            
            foreach ($findings as $finding) {
                $md .= "### {$finding['severity']} - {$finding['issue']}\n\n";
                
                foreach ($finding as $key => $value) {
                    if (in_array($key, ['category', 'severity', 'issue'])) {
                        continue;
                    }
                    $md .= "- **{$key}:** {$value}\n";
                }
                
                $md .= "\n";
            }
        }

        $md .= "---\n\n";
        $md .= "**Total Issues:** " . count($this->findings) . "\n";

        $filename = storage_path('logs/slow-query-analysis-' . now()->format('Y-m-d-His') . '.md');
        file_put_contents($filename, $md);

        $this->info("Report saved to: {$filename}");
    }
}
