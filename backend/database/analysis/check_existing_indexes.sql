-- ================================================================
-- Check Existing Indexes Script
-- ================================================================
-- This script checks existing indexes across all tables
-- Run with: mysql -u user -p database < check_existing_indexes.sql
-- Or in PostgreSQL: psql -U user -d database -f check_existing_indexes.sql
-- ================================================================

-- For MySQL
-- ================================================================
SELECT 
    TABLE_NAME as 'Table',
    INDEX_NAME as 'Index Name',
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as 'Columns',
    INDEX_TYPE as 'Type',
    NON_UNIQUE as 'Non-Unique',
    CARDINALITY as 'Cardinality'
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'attendances',
    'users',
    'schedules',
    'qr_codes',
    'class_students',
    'subscriptions',
    'payments',
    'audit_logs'
  )
GROUP BY TABLE_NAME, INDEX_NAME, INDEX_TYPE, NON_UNIQUE, CARDINALITY
ORDER BY TABLE_NAME, INDEX_NAME;

-- ================================================================
-- For PostgreSQL
-- ================================================================
-- SELECT 
--     schemaname,
--     tablename,
--     indexname,
--     indexdef
-- FROM pg_indexes
-- WHERE schemaname = 'public'
--   AND tablename IN (
--     'attendances',
--     'users',
--     'schedules',
--     'qr_codes',
--     'class_students',
--     'subscriptions',
--     'payments',
--     'audit_logs'
--   )
-- ORDER BY tablename, indexname;

-- ================================================================
-- Check Index Usage Statistics (PostgreSQL only)
-- ================================================================
-- SELECT 
--     schemaname,
--     tablename,
--     indexname,
--     idx_scan as index_scans,
--     idx_tup_read as tuples_read,
--     idx_tup_fetch as tuples_fetched
-- FROM pg_stat_user_indexes
-- WHERE schemaname = 'public'
--   AND tablename IN (
--     'attendances',
--     'users',
--     'schedules',
--     'qr_codes',
--     'class_students',
--     'subscriptions',
--     'payments',
--     'audit_logs'
--   )
-- ORDER BY idx_scan DESC;

-- ================================================================
-- Check Table Sizes
-- ================================================================
SELECT 
    TABLE_NAME as 'Table',
    TABLE_ROWS as 'Estimated Rows',
    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as 'Size (MB)',
    ROUND((INDEX_LENGTH / 1024 / 1024), 2) as 'Index Size (MB)'
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'attendances',
    'users',
    'schedules',
    'qr_codes',
    'class_students',
    'subscriptions',
    'payments',
    'audit_logs'
  )
ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC;
