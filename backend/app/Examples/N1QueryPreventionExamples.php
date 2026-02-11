<?php

namespace App\Examples;

/**
 * N+1 QUERY PREVENTION - COPY-PASTE EXAMPLES
 *
 * This file contains ready-to-use examples for preventing N+1 queries.
 * Use these patterns in your controllers.
 */
class N1QueryPreventionExamples
{
    /**
     * ❌ BAD: N+1 Query Problem
     */
    public function badExample_N1Problem()
    {
        // ❌ This will execute 1 + N queries
        $students = \App\Models\User::where('role_type', 'student')->get();

        foreach ($students as $student) {
            echo $student->class->name; // Executes 1 query per student!
            echo $student->school->name; // Another query per student!
        }

        // Total: 1 + (N * 2) queries
        // For 100 students = 201 queries! 🔥
    }

    /**
     * ✅ GOOD: Eager Loading Solution
     */
    public function goodExample_EagerLoading()
    {
        // ✅ This will execute only 3 queries total
        $students = \App\Models\User::where('role_type', 'student')
            ->with(['class', 'school'])
            ->get();

        foreach ($students as $student) {
            echo $student->class->name; // No additional query!
            echo $student->school->name; // No additional query!
        }

        // Total: 3 queries (students, classes, schools)
        // For 100 students = 3 queries! ✅
    }

    /**
     * ✅ PATTERN 1: Basic Eager Loading
     */
    public function pattern1_BasicEagerLoading()
    {
        // Load students with their classes
        $students = \App\Models\User::with('class')->get();

        // Load multiple relationships
        $students = \App\Models\User::with(['class', 'school', 'profile'])->get();

        // With conditions
        $students = \App\Models\User::where('is_active', true)
            ->with(['class', 'school'])
            ->get();
    }

    /**
     * ✅ PATTERN 2: Nested Relationships (Dot Notation)
     */
    public function pattern2_NestedRelationships()
    {
        // ✅ Load nested relationships
        $attendances = \App\Models\Attendance::with([
            'student',
            'schedule.class',       // Nested: schedule -> class
            'schedule.subject',     // Nested: schedule -> subject
            'schedule.teacher',     // Nested: schedule -> teacher
        ])->get();

        foreach ($attendances as $attendance) {
            echo $attendance->student->name;
            echo $attendance->schedule->class->name; // Already loaded!
            echo $attendance->schedule->subject->name; // Already loaded!
        }
    }

    /**
     * ✅ PATTERN 3: Selective Column Loading
     */
    public function pattern3_SelectiveColumns()
    {
        // ✅ Only load specific columns to reduce payload
        $students = \App\Models\User::with([
            'class:id,name',           // Only load id and name
            'school:id,name,address',  // Only load id, name, and address
        ])->get();

        // IMPORTANT: Always include the foreign key (e.g., 'id')
    }

    /**
     * ✅ PATTERN 4: Conditional Eager Loading
     */
    public function pattern4_ConditionalEagerLoading()
    {
        // ✅ Load relationships with conditions
        $students = \App\Models\User::with([
            'attendances' => function ($query) {
                $query->where('status', 'present')
                    ->whereBetween('attendance_date', [now()->startOfMonth(), now()])
                    ->latest()
                    ->limit(10);
            },
        ])->get();
    }

    /**
     * ✅ PATTERN 5: whereHas vs with
     */
    public function pattern5_WhereHasVsWith()
    {
        // ✅ CORRECT: Use whereHas for filtering, with for loading
        $students = \App\Models\User::whereHas('class', function ($q) {
            $q->where('name', 'like', '10%');
        })
            ->with('class:id,name') // Load the relationship too!
            ->get();

        // ❌ WRONG: Using whereHas alone
        $students = \App\Models\User::whereHas('class', function ($q) {
            $q->where('name', 'like', '10%');
        })->get(); // Missing ->with('class'), will cause N+1!
    }

    /**
     * ✅ PATTERN 6: Batch Loading with whereIn
     */
    public function pattern6_BatchLoading()
    {
        $students = \App\Models\User::where('role_type', 'student')
            ->get();

        // ❌ BAD: Query in loop
        foreach ($students as $student) {
            $attendance = \App\Models\Attendance::where('student_id', $student->id)->first();
        }

        // ✅ GOOD: Batch query before loop
        $studentIds = $students->pluck('id')->toArray();
        $attendances = \App\Models\Attendance::whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id'); // Key by student_id for easy access

        foreach ($students as $student) {
            $attendance = $attendances->get($student->id); // No query!
        }
    }

    /**
     * ✅ PATTERN 7: Grouping Results
     */
    public function pattern7_GroupingResults()
    {
        $students = \App\Models\User::where('role_type', 'student')->get();

        // ❌ BAD: Query in loop
        foreach ($students as $student) {
            $attendances = \App\Models\Attendance::where('student_id', $student->id)->get();
        }

        // ✅ GOOD: Single query with groupBy
        $studentIds = $students->pluck('id')->toArray();
        $allAttendances = \App\Models\Attendance::whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('student_id');

        foreach ($students as $student) {
            $attendances = $allAttendances->get($student->id, collect([])); // No query!
        }
    }

    /**
     * ✅ PATTERN 8: Lazy Eager Loading
     */
    public function pattern8_LazyEagerLoading()
    {
        // Sometimes you forget to eager load
        $students = \App\Models\User::all(); // Forgot ->with('class')!

        // ✅ You can still load relationships later (but better to do it initially)
        $students->load('class', 'school');

        foreach ($students as $student) {
            echo $student->class->name; // Now it's loaded!
        }
    }

    /**
     * ✅ PATTERN 9: Count Related Models
     */
    public function pattern9_CountRelated()
    {
        // ❌ BAD: Counting in loop
        $students = \App\Models\User::all();
        foreach ($students as $student) {
            $count = $student->attendances()->count(); // N queries!
        }

        // ✅ GOOD: Use withCount
        $students = \App\Models\User::withCount('attendances')->get();
        foreach ($students as $student) {
            echo $student->attendances_count; // Already counted!
        }
    }

    /**
     * ✅ PATTERN 10: Exists Check
     */
    public function pattern10_ExistsCheck()
    {
        // ❌ BAD: Loading just to check existence
        $students = \App\Models\User::all();
        foreach ($students as $student) {
            if ($student->attendances->count() > 0) { // Loads ALL attendances!
            }
        }

        // ✅ GOOD: Use withExists (if available) or withCount
        $students = \App\Models\User::withCount('attendances')->get();
        foreach ($students as $student) {
            if ($student->attendances_count > 0) { // Just a number!
            }
        }
    }

    /**
     * ✅ REAL WORLD EXAMPLE: Attendance Report
     */
    public function realWorld_AttendanceReport()
    {
        $startDate = '2024-01-01';
        $endDate = '2024-01-31';

        // ✅ Optimized query with all necessary relationships
        $attendances = \App\Models\Attendance::with([
            'student:id,name,nis',
            'schedule.class:id,name',
            'schedule.subject:id,name',
            'schedule.teacher:id,name',
            'schedule.academicYear:id,name',
        ])
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->orderBy('attendance_date', 'desc')
            ->get();

        // Now you can access all relationships without additional queries
        foreach ($attendances as $attendance) {
            echo $attendance->student->name;
            echo $attendance->schedule->class->name;
            echo $attendance->schedule->subject->name;
            echo $attendance->schedule->teacher->name;
            // No N+1 queries! All loaded upfront!
        }
    }

    /**
     * ✅ REAL WORLD EXAMPLE: Monthly Summary
     */
    public function realWorld_MonthlySummary()
    {
        $classId = 1;
        $month = '2024-01';

        // Get all students
        $students = \App\Models\User::where('role_type', 'student')
            ->whereHas('activeClass', function ($q) use ($classId) {
                $q->where('class_id', $classId);
            })
            ->with('school:id,name')
            ->get();

        // ✅ CRITICAL: Fetch ALL attendances in ONE query
        $startDate = $month.'-01';
        $endDate = \Carbon\Carbon::parse($startDate)->endOfMonth()->format('Y-m-d');

        $studentIds = $students->pluck('id')->toArray();
        $allAttendances = \App\Models\Attendance::whereIn('student_id', $studentIds)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->get()
            ->groupBy('student_id');

        // Now process without N+1
        $summary = $students->map(function ($student) use ($allAttendances) {
            $attendances = $allAttendances->get($student->id, collect([]));

            return [
                'student_id' => $student->id,
                'student_name' => $student->name,
                'present' => $attendances->where('status', 'present')->count(),
                'absent' => $attendances->where('status', 'absent')->count(),
                'late' => $attendances->where('status', 'late')->count(),
            ];
        });

        return $summary;
    }

    /**
     * ✅ REAL WORLD EXAMPLE: Dashboard Stats
     */
    public function realWorld_DashboardStats()
    {
        $schoolId = auth()->user()->school_id;
        $today = now()->format('Y-m-d');

        // ✅ Use aggregate queries instead of loading all records
        $stats = [
            'total_students' => \App\Models\User::where('school_id', $schoolId)
                ->where('role_type', 'student')
                ->count(), // Direct count, no loading

            'total_present_today' => \App\Models\Attendance::where('school_id', $schoolId)
                ->where('attendance_date', $today)
                ->where('status', 'present')
                ->count(), // Direct count

            'total_classes' => \App\Models\ClassModel::where('school_id', $schoolId)
                ->count(),
        ];

        return $stats;
    }

    /**
     * ✅ PATTERN 11: Using Query Builder for Complex Aggregations
     */
    public function pattern11_QueryBuilderAggregations()
    {
        // ✅ For complex aggregations, use Query Builder
        $stats = \DB::table('attendances')
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->join('classes', 'schedules.class_id', '=', 'classes.id')
            ->where('attendances.school_id', auth()->user()->school_id)
            ->whereBetween('attendances.attendance_date', [now()->startOfMonth(), now()])
            ->select(
                'classes.name as class_name',
                \DB::raw('COUNT(*) as total_attendances'),
                \DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count"),
                \DB::raw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count")
            )
            ->groupBy('classes.id', 'classes.name')
            ->get();

        // Single query for complex stats! No N+1!
    }

    /**
     * ✅ PATTERN 12: Prevent Lazy Loading in Production
     */
    public function pattern12_PreventLazyLoading()
    {
        // Add to AppServiceProvider::boot()
        // \Illuminate\Database\Eloquent\Model::preventLazyLoading(! app()->isProduction());

        // This will throw an exception in development when you access
        // a relationship that hasn't been eager loaded
    }
}
