# PostgreSQL Index Audit & Optimization

## 1. Index Usage Analysis Query

Run this SQL query to identify which indexes are actually being used by your application. Indexes with `idx_scan = 0` or very low relative usage are candidates for removal.

```sql
SELECT
    schemaname,
    relname AS table_name,
    indexrelname AS index_name,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size,
    idx_scan AS number_of_scans,
    idx_tup_read AS tuples_read,
    idx_tup_fetch AS tuples_fetched
FROM
    pg_stat_user_indexes
WHERE
    relname = 'attendances'
ORDER BY
    idx_scan DESC,
    pg_relation_size(indexrelid) DESC;
```

---

## 2. Redundant Index Analysis

The following indexes appear to be potentially redundant or problematic:

### Problematic: `idx_attendance_today`
```sql
CREATE INDEX ... WHERE attendance_date = CURRENT_DATE
```
**CRITICAL ISSUE:** PostgreSQL does **not** allow mutable functions (like `CURRENT_DATE`) in index predicates because they change over time. This migration likely failed or created a static index for the date the migration ran.
**RECOMMENDATION:** Drop this index. Partitioning by date is a better strategy for "today" queries if volume is massive, otherwise standard date range indexes are sufficient.

### Redundant: `idx_attendance_duplicate_check`
```text
(student_id, schedule_id, attendance_date)
```
**ANALYSIS:** If you already have a `UNIQUE` constraint on these columns (which you should for data integrity), this explicit index is 100% redundant. The unique constraint automatically creates an underlying unique index.
**RECOMMENDATION:** Check for `attendances_student_id_schedule_id_attendance_date_unique` (or similar). If it exists, DROP `idx_attendance_duplicate_check`.

### Overlapping: `idx_attendance_monthly` vs `idx_attendance_school_date_range`
- `idx_attendance_monthly`: `(school_id, student_id, attendance_date)`
- `idx_attendance_school_date_range`: `(school_id, attendance_date, status, student_id)`
**ANALYSIS:** Both start with `school_id`.
- If you often query `WHERE school_id = ? AND student_id = ?`, the first index is better.
- If you mostly query `WHERE school_id = ? AND attendance_date ...`, the second is better.
**RECOMMENDATION:** Keep `idx_attendance_student_date_desc` `(student_id, attendance_date)` for student-centric queries and `idx_attendance_school_date_range` for school-wide stats. `idx_attendance_monthly` might be removable if `student_id` cardinality is high enough that scanning `idx_attendance_student_date_desc` is efficient.

---

## 3. Recommended Optimization Plan

### Step 1: Remove Invalid/Redundant Indexes

```sql
-- 1. Drop invalid partial index (likely failed or static)
DROP INDEX IF EXISTS idx_attendance_today;

-- 2. Drop redundant duplicate check (if unique constraint exists)
-- Confirm unique constraint exists first:
-- \d attendances
DROP INDEX IF EXISTS idx_attendance_duplicate_check;
```

### Step 2: Consolidate Reporting Indexes

Combine overlapping indexes to reduce write overhead.

**Keep these Critical Indexes:**
1.  **Primary Key:** `id` (Automatic)
2.  **Unique Constraint:** `(student_id, schedule_id, attendance_date)` - **CRITICAL** for race condition prevention (Gap Locks).
3.  **Student History:** `idx_attendance_student_date_desc` `(student_id, attendance_date DESC)`
4.  **School Reports:** `idx_attendance_school_date_range` `(school_id, attendance_date, status)`
5.  **Schedule Lookup:** `idx_attendance_schedule_date_status` `(schedule_id, attendance_date)`
6.  **Request ID (Idempotency):** `request_id` (Unique)

**Drop these (Low Value / High Maintenance):**
- `idx_attendance_monthly` (covered by Student History or School Reports)
- `idx_attendance_status_school` (low cardinality of 'status' makes this rarely used by planner unless combined with dates)
- `idx_attendance_class_date` (unless class reports are frequent and slow; otherwise `school_id` index might suffice if filtered later)
- `idx_attendance_created_at` (unless you have heavy "feed" features sorting purely by time)

### Step 3: Verify "Active" Partial Index

The partial index for soft deletes is useful ONLY if you frequently query non-deleted records and have MANY deleted records.

```sql
CREATE INDEX idx_attendance_active
ON attendances (school_id, attendance_date)
WHERE deleted_at IS NULL;
```
**Verdict:** Keep if you use SoftDeletes heavily. Drop if soft deletes are rare (archival strategy is better).

---

## 4. Performance Trade-off Explanation

| Action | Writes (Insert/Update) | Reads (Select) | Space |
|--------|------------------------|----------------|-------|
| **Drop Redundant Indexes** | **FASTER** (Less overhead) | Same (Planner uses best index) | **SAVE** |
| **Consolidate Indexes** | **FASTER** | Slight variation | **SAVE** |
| **Keep Everything** | Slower | Optimal for specific queries | Bloated |

**Golden Rule:** Every index slows down every INSERT/UPDATE. Only keep indexes that strictly serve your critical query patterns (Attendance Check-in, Monthly Reports, Dashboard).
