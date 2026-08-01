<?php

/**
 * Missing Indexes Analysis Script
 * 
 * This script analyzes query execution plans using EXPLAIN to identify missing indexes
 * and determine optimal index types (BTREE, HASH) for the AbsensiQR Pro database.
 * 
 * Requirements: Week 3 Day 11, Acceptance Criteria 2
 * 
 * Usage: php artisan tinker < database/analysis/missing_indexes_analysis.php
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=================================================================\n";
echo "MISSING INDEXES ANALYSIS - AbsensiQR Pro\n";
echo "=================================================================\n\n";

// Get database driver
$driver = DB::getDriverName();
echo "Database Driver: {$driver}\n\n";

/**
 * Helper function to run EXPLAIN and analyze results
 */
function analyzeQuery(string $description, string $query, array $bindings = []): array
{
    global $driver;
    
    echo "-------------------------------------------------------------------\n";
    echo "Query: {$description}\n";
    echo "-------------------------------------------------------------------\n";
    
    try {
        // Run EXPLAIN based on driver
        if ($driver === 'pgsql') {
            $explain = DB::select("EXPLAIN (FORMAT JSON, ANALYZE false) {$query}", $bindings);
            $plan = json_decode($explain[0]->{'QUERY PLAN'}, true);
        } elseif ($driver === 'mysql') {
            $explain = DB::select("EXPLAIN {$query}", $bindings);
        } else {
            // SQLite
            $explain = DB::select("EXPLAIN QUERY PLAN {$query}", $bindings);
        }
        
        echo "SQL: {$query}\n";
        echo "Bindings: " . json_encode($bindings) . "\n\n";
        
        // Analyze the execution plan
        $analysis = [
            'query' => $query,
            'description' => $description,
            'uses_index' => false,
            'scan_type' => 'UNKNOWN',
            'rows_examined' => 0,
            'recommendations' => []
        ];
        
        if ($driver === 'mysql') {
            foreach ($explain as $row) {
                echo "Table: {$row->table}\n";
                echo "Type: {$row->type}\n";
                echo "Possible Keys: " . ($row->possible_keys ?? 'NULL') . "\n";
                echo "Key Used: " . ($row->key ?? 'NULL') . "\n";
                echo "Rows: {$row->rows}\n";
                echo "Extra: {$row->Extra}\n\n";
                
                $analysis['scan_type'] = $row->type;
                $analysis['rows_examined'] = $row->rows;
                $analysis['uses_index'] = !is_null($row->key);
                
                // Identify issues
                if ($row->type === 'ALL') {
                    $analysis['recommendations'][] = "⚠️  FULL TABLE SCAN detected on {$row->table}";
                }
                if ($row->rows > 1000) {
                    $analysis['recommendations'][] = "⚠️  High row count ({$row->rows}) - consider adding index";
                }
                if (stripos($row->Extra, 'Using filesort') !== false) {
                    $analysis['recommendations'][] = "⚠️  Using filesort - consider index on ORDER BY columns";
                }
                if (stripos($row->Extra, 'Using temporary') !== false) {
                    $analysis['recommendations'][] = "⚠️  Using temporary table - consider composite index";
                }
            }
        } elseif ($driver === 'pgsql') {
            echo json_encode($plan, JSON_PRETTY_PRINT) . "\n\n";
            
            // Parse PostgreSQL plan
            $planNode = $plan[0]['Plan'] ?? [];
            $analysis['scan_type'] = $planNode['Node Type'] ?? 'UNKNOWN';
            $analysis['rows_examined'] = $planNode['Plan Rows'] ?? 0;
            
            if (stripos($analysis['scan_type'], 'Seq Scan') !== false) {
                $analysis['recommendations'][] = "⚠️  Sequential Scan detected - missing index";
            }
            if (stripos($analysis['scan_type'], 'Index Scan') !== false) {
                $analysis['uses_index'] = true;
            }
        } else {
            // SQLite
            foreach ($explain as $row) {
                echo "Detail: {$row->detail}\n\n";
                
                if (stripos($row->detail, 'SCAN') !== false) {
                    $analysis['scan_type'] = 'SCAN';
                    $analysis['recommendations'][] = "⚠️  Table scan detected - consider adding index";
                } elseif (stripos($row->detail, 'SEARCH') !== false) {
                    $analysis['scan_type'] = 'INDEX';
                    $analysis['uses_index'] = true;
                }
            }
        }
        
        // Print recommendations
        if (!empty($analysis['recommendations'])) {
            echo "RECOMMENDATIONS:\n";
            foreach ($analysis['recommendations'] as $rec) {
                echo "  {$rec}\n";
            }
        } else {
            echo "✅ Query appears optimized\n";
        }
        
        echo "\n";
        
        return $analysis;
        
    } catch (\Exception $e) {
        echo "ERROR: {$e->getMessage()}\n\n";
        return [
            'query' => $query,
            'description' => $description,
            'error' => $e->getMessage()
        ];
    }
}

// =================================================================
// CRITICAL QUERY PATTERNS TO ANALYZE
// =================================================================

$analyses = [];

echo "\n=================================================================\n";
echo "SECTION 1: ATTENDANCE QUERIES (HIGHEST PRIORITY)\n";
echo "=================================================================\n\n";

// Query 1: Daily attendance scan duplicate check (MOST CRITICAL)
$analyses[] = analyzeQuery(
    "Attendance duplicate check during QR scan",
    "SELECT * FROM attendances WHERE student_id = ? AND schedule_id = ? AND attendance_date = ? LIMIT 1",
    [1, 1, '2026-02-16']
);

// Query 2: Student attendance history
$analyses[] = analyzeQuery(
    "Student attendance history (last 30 days)",
    "SELECT * FROM attendances WHERE student_id = ? AND attendance_date >= ? ORDER BY attendance_date DESC",
    [1, '2026-01-16']
);

// Query 3: Daily school report aggregation
$analyses[] = analyzeQuery(
    "Daily school attendance report",
    "SELECT status, COUNT(*) as count FROM attendances WHERE school_id = ? AND attendance_date = ? GROUP BY status",
    [1, '2026-02-16']
);

// Query 4: Class attendance report
$analyses[] = analyzeQuery(
    "Class attendance report for specific schedule",
    "SELECT a.*, u.name FROM attendances a JOIN users u ON a.student_id = u.id WHERE a.schedule_id = ? AND a.attendance_date = ?",
    [1, '2026-02-16']
);

// Query 5: Monthly attendance summary
$analyses[] = analyzeQuery(
    "Monthly attendance summary by student",
    "SELECT student_id, COUNT(*) as total, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present FROM attendances WHERE school_id = ? AND attendance_date >= ? AND attendance_date < ? GROUP BY student_id",
    [1, '2026-02-01', '2026-03-01']
);

// Query 6: Late students monitoring
$analyses[] = analyzeQuery(
    "Find late students today",
    "SELECT a.*, u.name FROM attendances a JOIN users u ON a.student_id = u.id WHERE a.school_id = ? AND a.attendance_date = ? AND a.status = 'late'",
    [1, '2026-02-16']
);

echo "\n=================================================================\n";
echo "SECTION 2: USER & AUTHENTICATION QUERIES\n";
echo "=================================================================\n\n";

// Query 7: User login by email
$analyses[] = analyzeQuery(
    "User login by email",
    "SELECT * FROM users WHERE email = ? LIMIT 1",
    ['teacher@school.com']
);

// Query 8: Active users by school and role
$analyses[] = analyzeQuery(
    "Get active teachers for a school",
    "SELECT * FROM users WHERE school_id = ? AND role_type = ? AND is_active = true",
    [1, 'teacher']
);

// Query 9: User profile with school
$analyses[] = analyzeQuery(
    "User profile with school details",
    "SELECT u.*, s.name as school_name FROM users u JOIN schools s ON u.school_id = s.id WHERE u.id = ?",
    [1]
);

echo "\n=================================================================\n";
echo "SECTION 3: SCHEDULE & CLASS QUERIES\n";
echo "=================================================================\n\n";

// Query 10: Today's schedules for a school
$analyses[] = analyzeQuery(
    "Get today's schedules",
    "SELECT * FROM schedules WHERE school_id = ? AND day_of_week = ? AND is_active = true",
    [1, 1]
);

// Query 11: Teacher's schedules
$analyses[] = analyzeQuery(
    "Get teacher's schedules for today",
    "SELECT s.*, sub.name as subject_name FROM schedules s JOIN subjects sub ON s.subject_id = sub.id WHERE s.teacher_id = ? AND s.day_of_week = ?",
    [1, 1]
);

// Query 12: Class students list
$analyses[] = analyzeQuery(
    "Get students in a class",
    "SELECT cs.*, u.name, u.email FROM class_students cs JOIN users u ON cs.student_id = u.id WHERE cs.class_id = ? AND cs.status = 'active'",
    [1]
);

echo "\n=================================================================\n";
echo "SECTION 4: QR CODE & SECURITY QUERIES\n";
echo "=================================================================\n\n";

// Query 13: Active QR codes
$analyses[] = analyzeQuery(
    "Get active QR codes for school",
    "SELECT * FROM qr_codes WHERE school_id = ? AND is_active = true AND valid_until > NOW()",
    [1]
);

// Query 14: QR code validation
$analyses[] = analyzeQuery(
    "Validate QR code by token",
    "SELECT * FROM qr_codes WHERE qr_token = ? AND is_active = true AND valid_until > NOW() LIMIT 1",
    ['sample-token-123']
);

// Query 15: Audit logs for user
if (Schema::hasTable('audit_logs')) {
    $analyses[] = analyzeQuery(
        "Get recent audit logs for school",
        "SELECT * FROM audit_logs WHERE school_id = ? AND created_at >= ? ORDER BY created_at DESC LIMIT 100",
        [1, '2026-02-15']
    );
}

echo "\n=================================================================\n";
echo "SECTION 5: PAYMENT & SUBSCRIPTION QUERIES\n";
echo "=================================================================\n\n";

// Query 16: School subscription status
if (Schema::hasTable('subscriptions')) {
    $analyses[] = analyzeQuery(
        "Get active subscription for school",
        "SELECT * FROM subscriptions WHERE school_id = ? AND status = 'active' ORDER BY expires_at DESC LIMIT 1",
        [1]
    );
}

// Query 17: Payment history
if (Schema::hasTable('payments')) {
    $analyses[] = analyzeQuery(
        "Get payment history for school",
        "SELECT * FROM payments WHERE school_id = ? ORDER BY created_at DESC LIMIT 20",
        [1]
    );
}

echo "\n=================================================================\n";
echo "SECTION 6: REPORTING & ANALYTICS QUERIES\n";
echo "=================================================================\n\n";

// Query 18: Attendance summary table usage
if (Schema::hasTable('attendance_summaries')) {
    $analyses[] = analyzeQuery(
        "Get attendance summary from pre-aggregated table",
        "SELECT * FROM attendance_summaries WHERE school_id = ? AND date = ?",
        [1, '2026-02-16']
    );
}

// Query 19: Weekly attendance trend
$analyses[] = analyzeQuery(
    "Weekly attendance trend",
    "SELECT attendance_date, COUNT(*) as total, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present FROM attendances WHERE school_id = ? AND attendance_date >= ? AND attendance_date < ? GROUP BY attendance_date ORDER BY attendance_date",
    [1, '2026-02-10', '2026-02-17']
);

// Query 20: Student attendance percentage
$analyses[] = analyzeQuery(
    "Calculate student attendance percentage",
    "SELECT student_id, COUNT(*) as total_days, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days FROM attendances WHERE school_id = ? AND attendance_date >= ? GROUP BY student_id HAVING total_days > 0",
    [1, '2026-01-01']
);

// =================================================================
// SUMMARY AND RECOMMENDATIONS
// =================================================================

echo "\n=================================================================\n";
echo "ANALYSIS SUMMARY\n";
echo "=================================================================\n\n";

$totalQueries = count($analyses);
$queriesWithIssues = 0;
$queriesOptimized = 0;
$fullTableScans = 0;

foreach ($analyses as $analysis) {
    if (isset($analysis['error'])) {
        continue;
    }
    
    if (!empty($analysis['recommendations'])) {
        $queriesWithIssues++;
    } else {
        $queriesOptimized++;
    }
    
    if ($analysis['scan_type'] === 'ALL' || stripos($analysis['scan_type'], 'Seq Scan') !== false || $analysis['scan_type'] === 'SCAN') {
        $fullTableScans++;
    }
}

echo "Total Queries Analyzed: {$totalQueries}\n";
echo "Queries with Issues: {$queriesWithIssues}\n";
echo "Queries Optimized: {$queriesOptimized}\n";
echo "Full Table Scans: {$fullTableScans}\n\n";

echo "=================================================================\n";
echo "RECOMMENDED MISSING INDEXES\n";
echo "=================================================================\n\n";

$recommendations = [];

// Analyze patterns and suggest indexes
foreach ($analyses as $analysis) {
    if (empty($analysis['recommendations'])) {
        continue;
    }
    
    $query = $analysis['query'];
    $desc = $analysis['description'];
    
    // Pattern matching for index recommendations
    if (stripos($desc, 'duplicate check') !== false) {
        $recommendations[] = [
            'table' => 'attendances',
            'columns' => ['student_id', 'schedule_id', 'attendance_date'],
            'type' => 'BTREE',
            'priority' => 'P0 - CRITICAL',
            'reason' => 'Duplicate check on every QR scan (highest frequency)',
            'index_name' => 'idx_attendance_duplicate_prevention'
        ];
    }
    
    if (stripos($desc, 'history') !== false && stripos($query, 'ORDER BY') !== false) {
        $recommendations[] = [
            'table' => 'attendances',
            'columns' => ['student_id', 'attendance_date DESC'],
            'type' => 'BTREE',
            'priority' => 'P1 - HIGH',
            'reason' => 'Student history queries with sorting',
            'index_name' => 'idx_attendance_student_history'
        ];
    }
    
    if (stripos($desc, 'report') !== false && stripos($query, 'GROUP BY') !== false) {
        $recommendations[] = [
            'table' => 'attendances',
            'columns' => ['school_id', 'attendance_date', 'status'],
            'type' => 'BTREE',
            'priority' => 'P1 - HIGH',
            'reason' => 'Aggregation queries for reports',
            'index_name' => 'idx_attendance_report_aggregation'
        ];
    }
    
    if (stripos($desc, 'login') !== false) {
        $recommendations[] = [
            'table' => 'users',
            'columns' => ['email'],
            'type' => 'BTREE',
            'priority' => 'P0 - CRITICAL',
            'reason' => 'User authentication (every login)',
            'index_name' => 'idx_users_email_login'
        ];
    }
    
    if (stripos($desc, 'QR') !== false && stripos($query, 'qr_token') !== false) {
        $recommendations[] = [
            'table' => 'qr_codes',
            'columns' => ['qr_token', 'is_active', 'valid_until'],
            'type' => 'BTREE',
            'priority' => 'P0 - CRITICAL',
            'reason' => 'QR code validation (every scan)',
            'index_name' => 'idx_qr_codes_validation'
        ];
    }
    
    if (stripos($desc, 'subscription') !== false) {
        $recommendations[] = [
            'table' => 'subscriptions',
            'columns' => ['school_id', 'status', 'expires_at'],
            'type' => 'BTREE',
            'priority' => 'P1 - HIGH',
            'reason' => 'Subscription validation',
            'index_name' => 'idx_subscriptions_active'
        ];
    }
}

// Remove duplicates
$recommendations = array_unique($recommendations, SORT_REGULAR);

// Sort by priority
usort($recommendations, function($a, $b) {
    return strcmp($a['priority'], $b['priority']);
});

// Print recommendations
$indexNumber = 1;
foreach ($recommendations as $rec) {
    echo "{$indexNumber}. {$rec['index_name']}\n";
    echo "   Table: {$rec['table']}\n";
    echo "   Columns: " . implode(', ', $rec['columns']) . "\n";
    echo "   Type: {$rec['type']}\n";
    echo "   Priority: {$rec['priority']}\n";
    echo "   Reason: {$rec['reason']}\n\n";
    $indexNumber++;
}

echo "=================================================================\n";
echo "INDEX TYPE RECOMMENDATIONS\n";
echo "=================================================================\n\n";

echo "BTREE (B-Tree) Indexes:\n";
echo "  - Use for: Range queries, sorting, equality comparisons\n";
echo "  - Best for: Most queries in this application\n";
echo "  - Examples: WHERE date >= ?, ORDER BY date DESC\n\n";

echo "HASH Indexes:\n";
echo "  - Use for: Exact equality comparisons only\n";
echo "  - Best for: Primary key lookups, unique constraints\n";
echo "  - Examples: WHERE id = ?, WHERE email = ?\n";
echo "  - Note: Not supported in all databases (PostgreSQL only)\n\n";

echo "Composite Indexes:\n";
echo "  - Use for: Multi-column WHERE clauses\n";
echo "  - Best for: Queries filtering on multiple columns\n";
echo "  - Examples: WHERE school_id = ? AND date = ?\n\n";

echo "Covering Indexes:\n";
echo "  - Use for: Include all columns needed by query\n";
echo "  - Best for: Avoiding table lookups\n";
echo "  - Examples: Index includes SELECT columns\n\n";

echo "=================================================================\n";
echo "NEXT STEPS\n";
echo "=================================================================\n\n";

echo "1. Review the analysis results above\n";
echo "2. Create migration file: 2026_02_16_000001_add_missing_indexes.php\n";
echo "3. Add recommended indexes based on priority\n";
echo "4. Test query performance before and after\n";
echo "5. Monitor production query logs for additional patterns\n\n";

echo "Analysis complete!\n";
