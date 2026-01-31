<?php

/**
 * EAGER LOADING QUICK REFERENCE
 *
 * This file provides quick copy-paste examples for eager loading Attendance queries.
 * Always use these patterns to prevent N+1 query issues.
 */

namespace App\Examples;

use App\Models\Attendance;

class AttendanceEagerLoadingExamples
{
    /**
     * ✅ STANDARD PATTERN - Use this for all Attendance queries
     */
    public function standardPattern()
    {
        return Attendance::with([
            'student',              // Direct relationship to User
            'schedule.class',       // Nested: Schedule -> ClassModel
            'schedule.subject',     // Nested: Schedule -> Subject
            'schedule.teacher',      // Nested: Schedule -> User (teacher)
        ])
            ->where('school_id', auth()->user()->school_id)
            ->get();
    }

    /**
     * ✅ Single Record with Eager Loading
     */
    public function findSingleRecord($id)
    {
        return Attendance::with([
            'student',
            'schedule.class',
            'schedule.subject',
            'schedule.teacher',
        ])
            ->find($id);
    }

    /**
     * ✅ Paginated Results with Eager Loading
     */
    public function paginatedResults()
    {
        return Attendance::with([
            'student',
            'schedule.class',
            'schedule.subject',
            'schedule.teacher',
        ])
            ->where('school_id', auth()->user()->school_id)
            ->latest()
            ->paginate(20);
    }

    /**
     * ✅ Student History
     */
    public function studentHistory($studentId)
    {
        return Attendance::with([
            'student',
            'schedule.class',
            'schedule.subject',
            'schedule.teacher',
        ])
            ->where('student_id', $studentId)
            ->orderBy('attendance_date', 'desc')
            ->limit(30)
            ->get();
    }

    /**
     * ✅ Class Attendance for Today
     */
    public function classAttendanceToday($scheduleId)
    {
        return Attendance::with([
            'student',
            'schedule.class',
            'schedule.subject',
            'schedule.teacher',
        ])
            ->where('schedule_id', $scheduleId)
            ->where('attendance_date', now()->toDateString())
            ->get();
    }

    /**
     * ✅ Date Range Query
     */
    public function dateRangeQuery($startDate, $endDate)
    {
        return Attendance::with([
            'student',
            'schedule.class',
            'schedule.subject',
            'schedule.teacher',
        ])
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->orderBy('attendance_date', 'asc')
            ->get();
    }

    /**
     * ✅ With Additional Relationships
     * Add more relationships as needed
     */
    public function withAdditionalRelations()
    {
        return Attendance::with([
            'student',
            'schedule.class',
            'schedule.subject',
            'schedule.teacher',
            'recorder',             // Who recorded the attendance
            'logs',                  // Attendance logs
        ])
            ->where('is_manual', true)
            ->get();
    }

    /**
     * ✅ Repository Pattern
     */
    public function repositoryExample($requestId)
    {
        return Attendance::with([
            'student',
            'schedule.class',
            'schedule.subject',
            'schedule.teacher',
        ])
            ->where('request_id', $requestId)
            ->first();
    }

    /**
     * ❌ BAD EXAMPLE - DO NOT USE
     * This will cause N+1 queries!
     */
    public function badExample()
    {
        // ❌ Missing eager loading
        $attendances = Attendance::where('school_id', 1)->get();

        // ❌ This will trigger N+1 queries
        foreach ($attendances as $attendance) {
            echo $attendance->student->name;           // +1 query
            echo $attendance->schedule->class->name;   // +2 queries
            echo $attendance->schedule->subject->name; // +1 query
            echo $attendance->schedule->teacher->name; // +1 query
        }
        // Total: 1 + (N * 5) queries for N records!
    }

    /**
     * ✅ GOOD EXAMPLE - USE THIS
     * Efficient with eager loading
     */
    public function goodExample()
    {
        // ✅ With eager loading
        $attendances = Attendance::with([
            'student',
            'schedule.class',
            'schedule.subject',
            'schedule.teacher',
        ])
            ->where('school_id', 1)
            ->get();

        // ✅ No additional queries
        foreach ($attendances as $attendance) {
            echo $attendance->student->name;
            echo $attendance->schedule->class->name;
            echo $attendance->schedule->subject->name;
            echo $attendance->schedule->teacher->name;
        }
        // Total: Only 6 queries regardless of N!
    }

    /**
     * ✅ Conditional Eager Loading
     */
    public function conditionalEagerLoading($includeRecorder = false)
    {
        $query = Attendance::with([
            'student',
            'schedule.class',
            'schedule.subject',
            'schedule.teacher',
        ]);

        if ($includeRecorder) {
            $query->with('recorder');
        }

        return $query->get();
    }

    /**
     * ✅ Eager Loading with Constraints
     */
    public function eagerLoadingWithConstraints()
    {
        return Attendance::with([
            'student' => function ($query) {
                $query->select('id', 'name', 'username');
            },
            'schedule.class',
            'schedule.subject' => function ($query) {
                $query->select('id', 'name', 'code');
            },
            'schedule.teacher' => function ($query) {
                $query->select('id', 'name');
            },
        ])
            ->get();
    }

    /**
     * ✅ Count Queries (No Eager Loading Needed)
     */
    public function countQueries()
    {
        // ✅ Aggregations don't need eager loading
        $total = Attendance::where('school_id', 1)->count();
        $present = Attendance::where('status', 'present')->count();

        return compact('total', 'present');
    }

    /**
     * ✅ Exists Queries (No Eager Loading Needed)
     */
    public function existsQueries($studentId, $scheduleId, $date)
    {
        // ✅ Existence checks don't need eager loading
        return Attendance::where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->where('attendance_date', $date)
            ->exists();
    }
}

/**
 * QUICK COPY-PASTE SNIPPETS
 * ========================
 *
 * Basic Query:
 * -----------
 * Attendance::with(['student', 'schedule.class', 'schedule.subject', 'schedule.teacher'])
 *     ->where('school_id', $schoolId)
 *     ->get();
 *
 * Find by ID:
 * ----------
 * Attendance::with(['student', 'schedule.class', 'schedule.subject', 'schedule.teacher'])
 *     ->find($id);
 *
 * Paginated:
 * ---------
 * Attendance::with(['student', 'schedule.class', 'schedule.subject', 'schedule.teacher'])
 *     ->latest()
 *     ->paginate(20);
 *
 * Date Range:
 * ----------
 * Attendance::with(['student', 'schedule.class', 'schedule.subject', 'schedule.teacher'])
 *     ->whereBetween('attendance_date', [$start, $end])
 *     ->get();
 */
