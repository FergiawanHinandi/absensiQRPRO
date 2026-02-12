# Slow Query Analysis Summary - Day 11

**Date:** 2026-02-11  
**Task:** 11.1 Run slow query analysis  
**Database:** PostgreSQL (absensi_qr)

## Executive Summary

Comprehensive database analysis completed using custom Artisan command `db:analyze-slow-queries`. Analysis identified **71 total issues** across multiple categories:

- **1 Configuration Issue** (HIGH priority)
- **50 Missing Indexes** on foreign keys (HIGH priority)
- **20 Unused Indexes** (LOW priority)
- **0 Sequential Scan Issues** (database is empty/development)
- **0 Table Bloat Issues** (database is empty/development)

## Key Findings

### 1. Configuration Issues (1 issue)

#### pg_stat_statements Extension Not Installed
- **Severity:** HIGH
- **Impact:** Cannot track query performance statistics in production
- **Recommendation:** Install extension for production monitoring
  ```sql
  CREATE EXTENSION pg_stat_statements;
  ```

### 2. Missing Indexes on Foreign Keys (50 issues)

Foreign keys without indexes cause slow JOIN operations and can lead to table locks during DELETE operations. Critical tables affected:

#### High-Impact Tables (Attendance Domain)
- `attendances` - 6 missing indexes
  - `approved_by`, `qr_code_id`, `subject_id`, `verified_by`, `rejected_by`, `correction_requested_by`
- `attendance_logs` - 1 missing index
  - `qr_code_id`
- `attendance_summaries` - 1 missing index
  - `class_id`
- `attendance_interventions` - 2 missing indexes
  - `school_id`, `teacher_id`

#### Security & Audit Tables
- `security_events` - 2 missing indexes
  - `user_id`, `reviewed_by`
- `security_alerts` - 1 missing index
  - `resolved_by`
- `audit_logs` - 1 missing index
  - `user_id`

#### User & School Management
- `users` - 1 missing index
  - `photo_reviewed_by`
- `schools` - Already well-indexed
- `notifications` - 1 missing index
  - `user_id`

#### Payment & Subscription
- `payments` - 2 missing indexes
  - `package_id`, `school_id`
- `subscriptions` - Already well-indexed

#### QR Code System
- `qr_codes` - 1 missing index
  - `generated_by`
- `qr_nonces` - 2 missing indexes
  - `school_id`, `schedule_id`

#### Student Management
- `student_cards` - 4 missing indexes
  - `school_id`, `issued_by`, `distributed_by`, `student_id`
- `student_notes` - 1 missing index
  - `teacher_id`
- `student_permissions` - 2 missing indexes
  - `approved_by`, `class_id`
- `student_attendance_risk` - 1 missing index
  - `student_id`

#### Teacher Management
- `teacher_attendances` - 1 missing index
  - `recorded_by`
- `teacher_devices` - 2 missing indexes
  - `revoked_by`, `approved_by`
- `teacher_roles` - 1 missing index
  - `homeroom_class_id`
- `teacher_attendance_anomalies` - 2 missing indexes
  - `reviewed_by`, `teacher_attendance_id`

#### Other Tables
- `schedules` - 2 missing indexes
  - `subject_id`, `academic_year_id`
- `announcements` - 1 missing index
  - `created_by`
- `certificates` - 2 missing indexes
  - `redeemed_by`, `student_id`
- `backup_jobs` - 1 missing index
  - `user_id`
- `behavior_baselines` - 1 missing index
  - `flagged_by`
- `suspicious_students` - 1 missing index
  - `reviewed_by`
- `refresh_tokens` - 1 missing index
  - `personal_access_token_id`
- `pitr_operations` - 2 missing indexes
  - `created_by`, `base_backup_id`
- `attendance_reports` - 1 missing index
  - `generated_by`
- `activity_logs` - 1 missing index
  - `school_id`

#### Partitioned Tables
- `attendances_2024` - 1 missing index
  - `recorded_by`
- `attendances_2025` - 1 missing index
  - `recorded_by`

### 3. Unused Indexes (20 issues)

Several indexes are currently unused. These should be monitored in production before removal:

#### Immutable Security Logs (9 indexes)
- `immutable_security_logs_event_type_created_at_index`
- `immutable_security_logs_current_hash_unique`
- `immutable_security_logs_previous_hash_index`
- `immutable_security_logs_sequence_number_index`
- `immutable_security_logs_key_unique`
- `immutable_security_logs_school_id_created_at_index`
- `immutable_security_logs_user_id_created_at_index`
- `immutable_security_logs_event_type_index`
- `immutable_security_logs_sequence_number_unique`

**Note:** These may be unused because the table is empty in development. Monitor in production before dropping.

#### Rate Limiting Tables (7 indexes)
- `rate_limit_fallback_key_index`
- `rate_limit_fallback_expires_at_index`
- `rate_limit_cleanup_idx`
- `rate_limit_fallback_school_id_index`
- `rate_limit_fallback_user_id_index`
- `rate_limit_unique_window`

**Note:** These are for fallback scenarios and may not be used in normal operation.

#### Other Tables (4 indexes)
- `system_health_state_component_unique`
- `fallback_events_reporting_idx`
- `fallback_events_component_idx`
- `fallback_events_created_at_index`
- `security_policy_history_policy_id_index`
- `feature_flags_key_unique`

### 4. Current Database State

All analyzed tables are currently empty (0 rows), which is expected for a development environment. Key observations:

- **attendances**: 40 columns, 56 indexes (well-indexed)
- **attendance_logs**: 23 columns, 9 indexes
- **users**: 27 columns, 19 indexes
- **schools**: 22 columns, 7 indexes
- **schedules**: 16 columns, 13 indexes

## Performance Impact Analysis

### High Priority (Immediate Action Required)

1. **Missing FK Indexes on Attendance Tables**
   - Impact: Slow JOINs when querying attendance with users/schedules
   - Estimated improvement: 10-100x faster for JOIN queries
   - Risk: Table locks during DELETE operations on referenced tables

2. **Missing FK Indexes on Security Tables**
   - Impact: Slow audit log queries, security event analysis
   - Estimated improvement: 5-50x faster for security reports
   - Risk: Compliance reporting delays

3. **Missing FK Indexes on Payment Tables**
   - Impact: Slow subscription checks, payment history queries
   - Estimated improvement: 10-50x faster for billing operations
   - Risk: Revenue reporting delays

### Medium Priority (Monitor in Production)

1. **Unused Indexes**
   - Impact: Minimal (small disk space usage)
   - Action: Monitor usage in production for 30 days before dropping
   - Risk: Low (can be recreated if needed)

### Low Priority (Future Optimization)

1. **pg_stat_statements Extension**
   - Impact: Better monitoring capabilities
   - Action: Install in production environment
   - Risk: None (read-only monitoring)

## Recommendations

### Immediate Actions (Week 3, Day 11)

1. **Create Migration for Missing FK Indexes**
   - Priority: HIGH
   - Effort: 2 hours
   - Impact: Significant performance improvement for JOIN queries
   - See: Task 11.2 (Create index migration)

2. **Install pg_stat_statements in Production**
   - Priority: HIGH
   - Effort: 30 minutes
   - Impact: Enables query performance monitoring
   - Command: `CREATE EXTENSION pg_stat_statements;`

### Future Actions (Week 3, Day 15)

1. **Monitor Unused Indexes in Production**
   - Priority: LOW
   - Effort: 1 hour
   - Action: Run analysis after 30 days of production traffic
   - Decision: Drop if still unused, keep if used

2. **Analyze Query Performance with Real Data**
   - Priority: MEDIUM
   - Effort: 2 hours
   - Action: Re-run analysis with production data
   - Goal: Identify slow queries and sequential scans

## Tools Created

### Artisan Command: `db:analyze-slow-queries`

**Location:** `app/Console/Commands/AnalyzeSlowQueries.php`

**Usage:**
```bash
# Console output
php artisan db:analyze-slow-queries

# JSON output
php artisan db:analyze-slow-queries --output=json

# Markdown report
php artisan db:analyze-slow-queries --output=markdown

# Analyze specific tables
php artisan db:analyze-slow-queries --tables=attendances,users,schools

# Custom threshold (default: 100ms)
php artisan db:analyze-slow-queries --threshold=500
```

**Features:**
- ✅ Detects missing indexes on foreign keys
- ✅ Identifies unused indexes
- ✅ Analyzes sequential scans
- ✅ Detects table bloat
- ✅ Checks pg_stat_statements availability
- ✅ Supports PostgreSQL, MySQL, SQLite
- ✅ Multiple output formats (console, JSON, markdown)
- ✅ Configurable thresholds

**Output:**
- Console: Color-coded severity levels
- JSON: Machine-readable format for automation
- Markdown: Detailed report saved to `storage/logs/`

## Next Steps

1. ✅ **Task 11.1 Complete:** Slow query analysis performed
2. ⏭️ **Task 11.2:** Create migration with missing indexes
3. ⏭️ **Task 11.3:** Write tests to verify index usage

## Appendix: Full Analysis Reports

Detailed reports saved to:
- `backend/storage/logs/slow-query-analysis-2026-02-11-221850.md`
- `backend/storage/logs/slow-query-analysis-2026-02-11-222048.md`

## References

- Laravel Database Indexes: https://laravel.com/docs/12.x/migrations#indexes
- PostgreSQL Index Types: https://www.postgresql.org/docs/current/indexes-types.html
- pg_stat_statements: https://www.postgresql.org/docs/current/pgstatstatements.html
