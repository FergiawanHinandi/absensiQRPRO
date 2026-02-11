-- Query Existing Duplicate Attendances
-- This SQL script identifies duplicate attendance records based on:
-- (student_id, schedule_id, attendance_date, school_id)
--
-- Usage: 
--   MySQL: mysql -u username -p database_name < query_duplicates.sql
--   PostgreSQL: psql -U username -d database_name -f query_duplicates.sql

-- ============================================
-- Find Duplicate Sets
-- ============================================
SELECT 
    student_id,
    schedule_id,
    attendance_date,
    school_id,
    COUNT(*) as duplicate_count,
    GROUP_CONCAT(id ORDER BY id) as attendance_ids,
    GROUP_CONCAT(status ORDER BY id) as statuses,
    MIN(created_at) as first_created,
    MAX(created_at) as last_created
FROM attendances
GROUP BY student_id, schedule_id, attendance_date, school_id
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC, school_id, attendance_date DESC;

-- ============================================
-- Summary Statistics
-- ============================================
SELECT 
    COUNT(*) as total_duplicate_sets,
    SUM(duplicate_count - 1) as total_records_to_remove
FROM (
    SELECT 
        student_id,
        schedule_id,
        attendance_date,
        school_id,
        COUNT(*) as duplicate_count
    FROM attendances
    GROUP BY student_id, schedule_id, attendance_date, school_id
    HAVING COUNT(*) > 1
) as duplicates;

-- ============================================
-- Duplicates by School
-- ============================================
SELECT 
    school_id,
    COUNT(*) as duplicate_sets,
    SUM(duplicate_count - 1) as records_to_remove
FROM (
    SELECT 
        school_id,
        student_id,
        schedule_id,
        attendance_date,
        COUNT(*) as duplicate_count
    FROM attendances
    GROUP BY student_id, schedule_id, attendance_date, school_id
    HAVING COUNT(*) > 1
) as duplicates
GROUP BY school_id
ORDER BY records_to_remove DESC;

-- ============================================
-- Duplicates by Date Range
-- ============================================
SELECT 
    DATE_FORMAT(attendance_date, '%Y-%m') as month,
    COUNT(*) as duplicate_sets,
    SUM(duplicate_count - 1) as records_to_remove
FROM (
    SELECT 
        attendance_date,
        student_id,
        schedule_id,
        school_id,
        COUNT(*) as duplicate_count
    FROM attendances
    GROUP BY student_id, schedule_id, attendance_date, school_id
    HAVING COUNT(*) > 1
) as duplicates
GROUP BY DATE_FORMAT(attendance_date, '%Y-%m')
ORDER BY month DESC;

-- ============================================
-- Sample Duplicate Records (First 10 Sets)
-- ============================================
SELECT 
    a.*
FROM attendances a
INNER JOIN (
    SELECT 
        student_id,
        schedule_id,
        attendance_date,
        school_id
    FROM attendances
    GROUP BY student_id, schedule_id, attendance_date, school_id
    HAVING COUNT(*) > 1
    LIMIT 10
) as dups
ON a.student_id = dups.student_id
AND a.schedule_id = dups.schedule_id
AND a.attendance_date = dups.attendance_date
AND a.school_id = dups.school_id
ORDER BY a.school_id, a.attendance_date, a.student_id, a.id;
