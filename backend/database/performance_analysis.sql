-- ================================================================
-- DATABASE PERFORMANCE ANALYSIS QUERIES
-- AbsensiQR Pro - Critical Query Performance Testing
-- ================================================================

-- ================================================================
-- 1. ATTENDANCE SCAN QUERIES (MOST CRITICAL)
-- ================================================================

-- Query 1A: Duplicate check during QR scan (HIGHEST FREQUENCY)
EXPLAIN ANALYZE
SELECT * FROM attendances 
WHERE student_id = 1 
  AND schedule_id = 1 
  AND attendance_date = '2026-02-02';

-- Query 1B: Student attendance history
EXPLAIN ANALYZE
SELECT a.*, s.subject_id, c.name as class_name
FROM attendances a
JOIN schedules s ON a.schedule_id = s.id
JOIN classes c ON s.class_id = c.id
WHERE a.student_id = 1
ORDER BY a.attendance_date DESC
LIMIT 30;

-- ================================================================
-- 2. DAILY ATTENDANCE REPORT QUERIES
-- ================================================================

-- Query 2A: Daily aggregation (Admin Dashboard)
EXPLAIN ANALYZE
SELECT 
  COUNT(DISTINCT CASE WHEN status = 'present' THEN student_id END) as present_count,
  COUNT(DISTINCT CASE WHEN status = 'late' THEN student_id END) as late_count,
  COUNT(DISTINCT CASE WHEN status = 'sick' THEN student_id END) as sick_count,
  COUNT(DISTINCT CASE WHEN status = 'permit' THEN student_id END) as permit_count,
  COUNT(DISTINCT student_id) as total_attended
FROM attendances 
WHERE school_id = 1 
  AND attendance_date = '2026-02-02';

-- Query 2B: Total active students count
EXPLAIN ANALYZE
SELECT COUNT(*) as total_students
FROM users 
WHERE school_id = 1 
  AND role_type = 'student' 
  AND is_active = true;

-- ================================================================
-- 3. MONTHLY ATTENDANCE SUMMARY QUERIES
-- ================================================================

-- Query 3A: Monthly trend (Principal Dashboard)
EXPLAIN ANALYZE
SELECT 
  DATE(attendance_date) as date,
  COUNT(*) as total_students,
  SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
  SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
  SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
FROM attendances 
WHERE school_id = 1 
  AND attendance_date >= '2026-01-02'
GROUP BY DATE(attendance_date)
ORDER BY date;

-- Query 3B: Student monthly statistics
EXPLAIN ANALYZE
SELECT 
  student_id,
  COUNT(*) as total_days,
  SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as attended_days,
  SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days
FROM attendances 
WHERE school_id = 1 
  AND attendance_date >= '2026-01-01' 
  AND attendance_date <= '2026-01-31'
GROUP BY student_id;

-- ================================================================
-- 4. SECURITY MONITORING DASHBOARD QUERIES
-- ================================================================

-- Query 4A: Security events trend
EXPLAIN ANALYZE
SELECT 
  DATE(created_at) as date,
  COUNT(*) as total,
  SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
  SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
  SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium,
  SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low
FROM security_events 
WHERE school_id = 1 
  AND created_at >= '2026-01-26'
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- Query 4B: Critical events with user data
EXPLAIN ANALYZE
SELECT 
  se.id, se.event_type, se.severity, se.description, se.created_at,
  u.name as user_name, u.email as user_email, u.role_type as user_role
FROM security_events se
LEFT JOIN users u ON se.user_id = u.id
WHERE se.school_id = 1
  AND se.severity IN ('high', 'critical')
ORDER BY se.created_at DESC
LIMIT 20;

-- ================================================================
-- 5. CLASS ATTENDANCE QUERIES (TEACHER DASHBOARD)
-- ================================================================

-- Query 5A: Class attendance for specific schedule
EXPLAIN ANALYZE
SELECT 
  a.student_id, a.status, a.check_in_time, a.is_manual,
  u.name as student_name, u.username
FROM attendances a
JOIN users u ON a.student_id = u.id
WHERE a.schedule_id = 1 
  AND a.attendance_date = '2026-02-02';

-- Query 5B: Schedule with class and students
EXPLAIN ANALYZE
SELECT 
  s.id, s.start_time, s.end_time,
  c.name as class_name,
  sub.name as subject_name,
  u.name as student_name, u.id as student_id
FROM schedules s
JOIN classes c ON s.class_id = c.id
JOIN subjects sub ON s.subject_id = sub.id
JOIN class_students cs ON c.id = cs.class_id
JOIN users u ON cs.student_id = u.id
WHERE s.id = 1;

-- ================================================================
-- 6. PERFORMANCE ANALYSIS QUERIES
-- ================================================================

-- Query 6A: Index usage analysis
SELECT 
  table_name,
  index_name,
  non_unique,
  seq_in_index,
  column_name,
  cardinality
FROM information_schema.statistics 
WHERE table_schema = DATABASE()
  AND table_name IN ('attendances', 'security_events', 'users', 'schedules')
ORDER BY table_name, index_name, seq_in_index;

-- Query 6B: Table size analysis
SELECT 
  table_name,
  table_rows,
  data_length,
  index_length,
  ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
FROM information_schema.tables 
WHERE table_schema = DATABASE()
  AND table_name IN ('attendances', 'security_events', 'users', 'schedules', 'classes')
ORDER BY (data_length + index_length) DESC;

-- ================================================================
-- 7. SLOW QUERY IDENTIFICATION
-- ================================================================

-- Query 7A: Identify queries without proper indexes (MySQL)
SELECT 
  query_time,
  lock_time,
  rows_sent,
  rows_examined,
  sql_text
FROM mysql.slow_log 
WHERE sql_text LIKE '%attendances%' 
   OR sql_text LIKE '%security_events%'
ORDER BY query_time DESC
LIMIT 10;

-- Query 7B: Check for full table scans
SHOW STATUS LIKE 'Handler_read%';

-- ================================================================
-- 8. OPTIMIZATION VERIFICATION QUERIES
-- ================================================================

-- Query 8A: Verify attendance indexes are being used
EXPLAIN FORMAT=JSON
SELECT * FROM attendances 
WHERE student_id = 1 AND schedule_id = 1 AND attendance_date = '2026-02-02';

-- Query 8B: Verify security events indexes are being used  
EXPLAIN FORMAT=JSON
SELECT * FROM security_events 
WHERE school_id = 1 AND created_at >= '2026-01-26' 
ORDER BY created_at DESC;

-- Query 8C: Verify daily report optimization
EXPLAIN FORMAT=JSON
SELECT 
  COUNT(DISTINCT CASE WHEN status = 'present' THEN student_id END) as present_count
FROM attendances 
WHERE school_id = 1 AND attendance_date = '2026-02-02';

-- ================================================================
-- 9. BENCHMARK QUERIES (Before/After Index Creation)
-- ================================================================

-- Benchmark 1: Attendance scan performance
SET @start_time = NOW(6);
SELECT * FROM attendances WHERE student_id = 1 AND schedule_id = 1 AND attendance_date = '2026-02-02';
SELECT TIMESTAMPDIFF(MICROSECOND, @start_time, NOW(6)) as execution_time_microseconds;

-- Benchmark 2: Daily report performance
SET @start_time = NOW(6);
SELECT COUNT(DISTINCT student_id) FROM attendances WHERE school_id = 1 AND attendance_date = '2026-02-02';
SELECT TIMESTAMPDIFF(MICROSECOND, @start_time, NOW(6)) as execution_time_microseconds;

-- Benchmark 3: Monthly trend performance
SET @start_time = NOW(6);
SELECT DATE(attendance_date), COUNT(*) FROM attendances 
WHERE school_id = 1 AND attendance_date >= '2026-01-02' 
GROUP BY DATE(attendance_date);
SELECT TIMESTAMPDIFF(MICROSECOND, @start_time, NOW(6)) as execution_time_microseconds;

-- ================================================================
-- 10. MAINTENANCE QUERIES
-- ================================================================

-- Query 10A: Update table statistics
ANALYZE TABLE attendances, security_events, users, schedules, classes;

-- Query 10B: Check index fragmentation
SELECT 
  table_name,
  index_name,
  stat_name,
  stat_value
FROM mysql.innodb_index_stats 
WHERE database_name = DATABASE()
  AND table_name IN ('attendances', 'security_events')
ORDER BY table_name, index_name;

-- Query 10C: Optimize tables if needed
-- OPTIMIZE TABLE attendances, security_events, users, schedules, classes;