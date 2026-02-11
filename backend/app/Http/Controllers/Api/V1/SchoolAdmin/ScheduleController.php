<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    /**
     * Get Schedules
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $classId = $request->input('class_id');
        $teacherId = $request->input('teacher_id');
        $day = $request->input('day');

        $query = Schedule::with(['class:id,name', 'subject:id,name,code', 'teacher:id,name'])
            ->where('school_id', $schoolId);

        if ($classId) {
            $query->where('class_id', $classId);
        }

        if ($teacherId) {
            $query->where('teacher_id', $teacherId);
        }

        if ($day !== null) {
            $query->where('day_of_week', $day);
        }

        $schedules = $query
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->paginate($request->get('per_page', 50));

        return response()->success($schedules);
    }

    /**
     * Store Schedule
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'required|exists:users,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'day_of_week' => 'required|integer|between:0,6', // 0=Sunday, 6=Saturday
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'room' => 'nullable|string|max:100',
        ]);

        // 1. Check for Class Overlap
        $this->checkClassOverlap($schoolId, $validated['class_id'], $validated['day_of_week'], $validated['start_time'], $validated['end_time']);

        // 2. Check for Teacher Double Booking
        $this->checkTeacherDoubleBooking($schoolId, $validated['teacher_id'], $validated['day_of_week'], $validated['start_time'], $validated['end_time']);

        // 3. Create
        $schedule = DB::transaction(function () use ($validated, $schoolId, $user) {
            $schedule = Schedule::create(array_merge($validated, [
                'school_id' => $schoolId,
                'is_active' => true,
            ]));

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $user->id,
                'action' => 'schedule_created',
                'module' => 'schedule',
                'severity' => 'info',
                'description' => "Created schedule for Class ID {$schedule->class_id}, Subject ID {$schedule->subject_id}",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $schedule;
        });

        return response()->success($schedule, 'Schedule created successfully', 201);
    }

    /**
     * Update Schedule
     */
    public function update(Request $request, Schedule $schedule)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        if ($schedule->school_id !== $schoolId) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'class_id' => 'sometimes|exists:classes,id',
            'subject_id' => 'sometimes|exists:subjects,id',
            'teacher_id' => 'sometimes|exists:users,id',
            'day_of_week' => 'sometimes|integer|between:0,6',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'room' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        // If time/day/class/teacher changing, re-validate overlaps
        if (isset($validated['start_time']) || isset($validated['end_time']) || isset($validated['day_of_week']) || isset($validated['class_id']) || isset($validated['teacher_id'])) {
            $classId = $validated['class_id'] ?? $schedule->class_id;
            $teacherId = $validated['teacher_id'] ?? $schedule->teacher_id;
            $day = $validated['day_of_week'] ?? $schedule->day_of_week;
            $start = $validated['start_time'] ?? $schedule->start_time;
            $end = $validated['end_time'] ?? $schedule->end_time;

            $this->checkClassOverlap($schoolId, $classId, $day, $start, $end, $schedule->id);
            $this->checkTeacherDoubleBooking($schoolId, $teacherId, $day, $start, $end, $schedule->id);
        }

        DB::transaction(function () use ($schedule, $validated, $user, $schoolId) {
            $schedule->update($validated);

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $user->id,
                'action' => 'schedule_updated',
                'module' => 'schedule',
                'severity' => 'info',
                'description' => "Updated schedule ID {$schedule->id}",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });

        return response()->success($schedule, 'Schedule updated successfully');
    }

    /**
     * Delete Schedule
     */
    public function destroy(Request $request, Schedule $schedule)
    {
        if ($schedule->school_id !== $request->user()->school_id) {
            abort(403, 'Unauthorized');
        }

        $schedule->delete();

        return response()->success(null, 'Schedule deleted successfully');
    }

    /**
     * Bulk Import Schedules
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        $data = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_shift($data); // Assume first row is header: class, subject, teacher, day, start, end

        $successCount = 0;
        $errors = [];
        $schoolId = $request->user()->school_id;

        // Naive implementation for brevity. In prod, use queued job.
        foreach ($data as $index => $row) {
            if (count($row) < 6) {
                continue;
            }

            try {
                // Map CSV columns (assuming strict order or name matching needed in real app)
                // Format: Class Name, Subject Code, Teacher Email/Name, Day(0-6), Start, End
                $className = trim($row[0]);
                $subjectCode = trim($row[1]);
                $teacherEmail = trim($row[2]);
                $day = (int) trim($row[3]);
                $start = trim($row[4]);
                $end = trim($row[5]);

                // Resolvers
                $class = \App\Models\ClassModel::where('school_id', $schoolId)->where('name', $className)->firstOrFail();
                $subject = \App\Models\Subject::where('school_id', $schoolId)->where('code', $subjectCode)->firstOrFail();
                $teacher = \App\Models\User::where('school_id', $schoolId)->where('email', $teacherEmail)->firstOrFail();

                // Validation
                $this->checkClassOverlap($schoolId, $class->id, $day, $start, $end);
                $this->checkTeacherDoubleBooking($schoolId, $teacher->id, $day, $start, $end);

                Schedule::create([
                    'school_id' => $schoolId,
                    'class_id' => $class->id,
                    'subject_id' => $subject->id,
                    'teacher_id' => $teacher->id,
                    'day_of_week' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'is_active' => true,
                ]);

                $successCount++;
            } catch (\Exception $e) {
                $errors[] = 'Row '.($index + 2).': '.$e->getMessage();
            }
        }

        return response()->json([
            'success' => true,
            'imported' => $successCount,
            'errors' => $errors,
        ]);
    }

    /**
     * Get Weekly Schedule for a Class
     */
    public function getWeeklyScheduleByClass(Request $request, $classId)
    {
        $schoolId = $request->user()->school_id;

        $schedules = Schedule::with(['subject', 'teacher'])
            ->where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->groupBy('day_of_week');

        return response()->success($schedules);
    }

    /**
     * Get Weekly Schedule for a Teacher
     */
    public function getWeeklyScheduleByTeacher(Request $request, $teacherId)
    {
        $schoolId = $request->user()->school_id;

        $schedules = Schedule::with(['subject', 'class'])
            ->where('school_id', $schoolId)
            ->where('teacher_id', $teacherId)
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->groupBy('day_of_week');

        return response()->success($schedules);
    }

    // --- Helpers ---

    private function checkClassOverlap($schoolId, $classId, $day, $start, $end, $ignoreId = null)
    {
        $exists = Schedule::where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->where('day_of_week', $day)
            ->where('is_active', true)
            ->where(function ($q) use ($start, $end) {
                $q->where('start_time', '<', $end)
                  ->where('end_time', '>', $start);
            })
            ->when($ignoreId, function ($q, $id) {
                $q->where('id', '!=', $id);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'start_time' => ['Jadwal kelas bertabrakan dengan sesi lain pada waktu ini.'],
            ]);
        }
    }

    private function checkTeacherDoubleBooking($schoolId, $teacherId, $day, $start, $end, $ignoreId = null)
    {
        $exists = Schedule::where('school_id', $schoolId)
            ->where('teacher_id', $teacherId)
            ->where('day_of_week', $day)
            ->where('is_active', true)
            ->where(function ($q) use ($start, $end) {
                $q->where('start_time', '<', $end)
                  ->where('end_time', '>', $start);
            })
            ->when($ignoreId, function ($q, $id) {
                $q->where('id', '!=', $id);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'teacher_id' => ['Guru sudah memiliki jadwal mengajar di kelas lain pada waktu ini.'],
            ]);
        }
    }
}
