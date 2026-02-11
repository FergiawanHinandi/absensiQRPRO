<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Attendance Service
 * 
 * Handles all attendance business logic:
 * - QR code scanning
 * - Manual attendance input
 * - Report generation
 * 
 * All queries are school-scoped for multi-tenant security
 */
class AttendanceService
{
    public function __construct(
        private AttendanceCheckInService $checkInService
    ) {}

    /**
     * Process QR code scan for attendance
     * 
     * @param User $student
     * @param array $scanData
     * @param \Illuminate\Http\Request $request
     * @return \App\DTOs\AttendanceCheckInResult
     * @throws AttendanceException
     */
    public function scan(User $student, array $scanData, $request)
    {
        // Validate student has school_id
        if (!$student->school_id) {
            throw new AttendanceException('Siswa tidak terdaftar di sekolah manapun.');
        }

        // Delegate to check-in service (handles all validation and business logic)
        $result = $this->checkInService->checkIn($student, $scanData, $request);

        // Log successful scan
        if ($result->isSuccessful() && $result->attendance) {
            Log::channel('audit')->info('attendance_scanned', [
                'user_id' => $student->id,
                'school_id' => $student->school_id,
                'schedule_id' => $result->attendance->schedule_id,
                'status' => $result->attendance->status,
                'timestamp' => now(),
            ]);
        }

        return $result;
    }

    /**
     * Generate QR code for attendance session (REFACTORED WITH ATOMIC LOCK)
     * 
     * Comprehensive validation + Redis atomic locking:
     * - Fetches schedule by session_id
     * - Validates teacher_id, school_id, is_active
     * - Time-based validation with tolerance
     * - Atomic lock ensures only 1 active QR per session
     * - Idempotent: returns existing QR if still valid
     * - Race condition safe with SET NX
     * - Logs invalid attempts and all QR lifecycle events
     * 
     * @param int $sessionId Schedule ID for the attendance session
     * @param User $teacher The teacher generating the QR
     * @param int $expirySeconds QR expiry time in seconds (default: 60)
     * @return array QR payload with signature
     * @throws AttendanceException
     */
    public function generateQR(int $sessionId, User $teacher, int $expirySeconds = 60): array
    {
        // 1. Validate teacher role
        if (!in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
            throw new AttendanceException('Hanya guru yang dapat membuat QR absensi.');
        }

        // 2. Fetch schedule by session_id with all necessary fields
        $schedule = Schedule::select([
                'id',
                'school_id',
                'teacher_id',
                'class_id',
                'subject_id',
                'day_of_week',
                'start_time',
                'end_time',
                'is_active',
                'schedule_type'
            ])
            ->with(['class:id,name', 'subject:id,name'])
            ->find($sessionId);

        if (!$schedule) {
            Log::channel('audit')->warning('qr_generation_failed_schedule_not_found', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
            ]);
            
            throw new AttendanceException('Sesi tidak ditemukan.');
        }

        // 3. Validate school_id match
        if ($schedule->school_id !== $teacher->school_id) {
            Log::channel('audit')->warning('qr_generation_failed_school_mismatch', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'teacher_school_id' => $teacher->school_id,
                'schedule_school_id' => $schedule->school_id,
            ]);
            
            throw new AttendanceException('Sesi tidak ditemukan atau tidak valid.');
        }

        // 4. Validate teacher_id match
        if ($schedule->teacher_id !== $teacher->id) {
            Log::channel('audit')->warning('qr_generation_failed_teacher_mismatch', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'schedule_teacher_id' => $schedule->teacher_id,
                'school_id' => $teacher->school_id,
            ]);
            
            throw new AttendanceException('Anda tidak memiliki akses untuk membuat QR pada sesi ini.');
        }

        // 5. Validate is_active = true
        if (!$schedule->is_active) {
            Log::channel('audit')->warning('qr_generation_failed_inactive_schedule', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'is_active' => $schedule->is_active,
            ]);
            
            throw new AttendanceException('Sesi ini tidak aktif dan tidak dapat digunakan untuk absensi.');
        }

        // 6. Get school timezone for accurate time validation
        $schoolTimezone = $teacher->school->timezone ?? 'Asia/Jakarta';
        $now = \Carbon\Carbon::now($schoolTimezone);
        
        // 7. Validate day_of_week matches today
        $todayDayOfWeek = $now->dayOfWeek;
        
        if ($schedule->day_of_week !== $todayDayOfWeek) {
            Log::channel('audit')->warning('qr_generation_failed_wrong_day', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'schedule_day' => $schedule->day_of_week,
                'current_day' => $todayDayOfWeek,
                'timezone' => $schoolTimezone,
            ]);
            
            $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            $scheduleDayName = $dayNames[$schedule->day_of_week] ?? 'Unknown';
            $todayDayName = $dayNames[$todayDayOfWeek] ?? 'Unknown';
            
            throw new AttendanceException(
                "Sesi ini dijadwalkan untuk hari {$scheduleDayName}, bukan {$todayDayName}."
            );
        }

        // 8. Time-based validation
        $currentTime = $now->format('H:i:s');
        $startTime = $schedule->start_time;
        $endTime = $schedule->end_time;
        
        // Parse times for comparison
        $currentTimeCarbon = \Carbon\Carbon::createFromFormat('H:i:s', $currentTime, $schoolTimezone);
        $startTimeCarbon = \Carbon\Carbon::createFromFormat('H:i:s', $startTime, $schoolTimezone);
        $endTimeCarbon = \Carbon\Carbon::createFromFormat('H:i:s', $endTime, $schoolTimezone);
        
        // Allow QR generation 10 minutes before start time
        $earliestAllowedTime = $startTimeCarbon->copy()->subMinutes(10);
        
        // Allow QR generation until end time + tolerance (5 minutes)
        $latestAllowedTime = $endTimeCarbon->copy()->addMinutes(5);
        
        // Check if current time is within allowed window
        if ($currentTimeCarbon->lt($earliestAllowedTime)) {
            Log::channel('audit')->warning('qr_generation_failed_too_early', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'current_time' => $currentTime,
                'start_time' => $startTime,
                'earliest_allowed' => $earliestAllowedTime->format('H:i:s'),
                'timezone' => $schoolTimezone,
            ]);
            
            throw new AttendanceException(
                "QR absensi hanya dapat dibuat mulai 10 menit sebelum sesi dimulai. " .
                "Sesi dimulai pukul {$startTime}, QR dapat dibuat mulai pukul " . 
                $earliestAllowedTime->format('H:i') . "."
            );
        }
        
        if ($currentTimeCarbon->gt($latestAllowedTime)) {
            Log::channel('audit')->warning('qr_generation_failed_too_late', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'current_time' => $currentTime,
                'end_time' => $endTime,
                'latest_allowed' => $latestAllowedTime->format('H:i:s'),
                'timezone' => $schoolTimezone,
            ]);
            
            throw new AttendanceException(
                "QR absensi tidak dapat dibuat setelah sesi berakhir. " .
                "Sesi berakhir pukul {$endTime}."
            );
        }

        // ============================================================
        // 9. ATOMIC LOCK IMPLEMENTATION - PRODUCTION SAFE
        // ============================================================
        
        $activeQRKey = "qr_active:{$teacher->school_id}:{$sessionId}";
        
        try {
            // STEP A: Check if active QR already exists (idempotent check)
            $existingQR = Redis::get($activeQRKey);
            
            if ($existingQR) {
                // QR already exists and is still valid - return it (idempotent behavior)
                $existingData = json_decode($existingQR, true);
                
                Log::channel('audit')->info('qr_reused', [
                    'teacher_id' => $teacher->id,
                    'session_id' => $sessionId,
                    'school_id' => $teacher->school_id,
                    'token' => $existingData['token'] ?? null,
                    'created_at' => $existingData['created_at'] ?? null,
                    'ttl_remaining' => Redis::ttl($activeQRKey),
                ]);
                
                return [
                    'data' => $existingData['payload']['data'] ?? [],
                    'signature' => $existingData['payload']['signature'] ?? '',
                    'expires_at' => $existingData['payload']['data']['expires_at'] ?? null,
                    'valid_for_seconds' => Redis::ttl($activeQRKey),
                    'session_info' => [
                        'class' => $schedule->class->name ?? null,
                        'subject' => $schedule->subject->name ?? null,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                    ],
                    'reused' => true,
                ];
            }
            
            // STEP B: No existing QR - generate new one
            $sessionToken = Str::uuid()->toString();
            $expiresAt = $now->copy()->addSeconds($expirySeconds);
            
            $payload = [
                'school_id' => $teacher->school_id,
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'expires_at' => $expiresAt->toIso8601String(),
                'token' => $sessionToken,
            ];

            // Generate HMAC signature
            $signature = hash_hmac('sha256', json_encode($payload), config('app.key'));
            
            // Prepare full QR data for storage
            $qrData = [
                'token' => $sessionToken,
                'payload' => [
                    'data' => $payload,
                    'signature' => $signature,
                ],
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'class_id' => $schedule->class_id,
                'subject_id' => $schedule->subject_id,
                'created_at' => $now->toIso8601String(),
                'timezone' => $schoolTimezone,
            ];
            
            // STEP C: Atomic SET with NX (only set if not exists) - RACE CONDITION SAFE
            $setResult = Redis::set(
                $activeQRKey,
                json_encode($qrData),
                'EX',
                $expirySeconds,
                'NX'
            );
            
            if ($setResult === false || $setResult === null) {
                // Race condition detected - another request won the lock
                Log::channel('audit')->warning('qr_race_detected', [
                    'teacher_id' => $teacher->id,
                    'session_id' => $sessionId,
                    'school_id' => $teacher->school_id,
                    'attempted_token' => $sessionToken,
                ]);
                
                // Retrieve the winning QR and return it
                $winningQR = Redis::get($activeQRKey);
                if ($winningQR) {
                    $winningData = json_decode($winningQR, true);
                    
                    return [
                        'data' => $winningData['payload']['data'] ?? [],
                        'signature' => $winningData['payload']['signature'] ?? '',
                        'expires_at' => $winningData['payload']['data']['expires_at'] ?? null,
                        'valid_for_seconds' => Redis::ttl($activeQRKey),
                        'session_info' => [
                            'class' => $schedule->class->name ?? null,
                            'subject' => $schedule->subject->name ?? null,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                        ],
                        'reused' => true,
                        'race_condition_resolved' => true,
                    ];
                }
                
                // Fallback if winning QR disappeared (extremely rare)
                throw new AttendanceException('Gagal membuat QR code. Silakan coba lagi.');
            }
            
            // STEP D: Also store token-based lookup for scan validation (backward compatibility)
            $tokenKey = "qr_session:{$sessionToken}";
            Redis::setex($tokenKey, $expirySeconds, json_encode([
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'class_id' => $schedule->class_id,
                'subject_id' => $schedule->subject_id,
                'created_at' => $now->toIso8601String(),
                'timezone' => $schoolTimezone,
            ]));
            
            // Log successful QR generation
            Log::channel('audit')->info('qr_generated', [
                'teacher_id' => $teacher->id,
                'session_id' => $sessionId,
                'school_id' => $teacher->school_id,
                'class' => $schedule->class->name ?? null,
                'subject' => $schedule->subject->name ?? null,
                'token' => $sessionToken,
                'current_time' => $currentTime,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'expires_at' => $expiresAt->toIso8601String(),
                'timezone' => $schoolTimezone,
            ]);

            return [
                'data' => $payload,
                'signature' => $signature,
                'expires_at' => $expiresAt->toIso8601String(),
                'valid_for_seconds' => $expirySeconds,
                'session_info' => [
                    'class' => $schedule->class->name ?? null,
                    'subject' => $schedule->subject->name ?? null,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'current_time' => $currentTime,
                ],
            ];
        } catch (\Exception $e) {
            Log::channel('audit')->error('qr_generation_exception', [
                'teacher_id' => $teacher->id,
                'session_id' => $sessionId,
                'school_id' => $teacher->school_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new AttendanceException('Gagal membuat QR code: ' . $e->getMessage());
        }
    }

    /**
     * Manual attendance input by teacher/admin
     * 
     * Uses transaction to ensure data consistency
     * All queries are school-scoped
     * 
     * @param array $data
     * @param int $recordedBy User ID who records the attendance
     * @return Attendance
     * @throws AttendanceException
     */
    public function manualInput(array $data, int $recordedBy): Attendance
    {
        // Validate required fields
        $this->validateManualInputData($data);

        // Get school_id from authenticated user
        $schoolId = $data['school_id'];

        DB::beginTransaction();
        try {
            // Validate student belongs to school
            $student = User::where('id', $data['student_id'])
                ->where('school_id', $schoolId)
                ->where('role_type', 'student')
                ->where('is_active', true)
                ->firstOrFail();

            // Validate schedule belongs to school
            $schedule = Schedule::where('id', $data['schedule_id'])
                ->where('school_id', $schoolId)
                ->firstOrFail();

            // Check if attendance already exists
            $existing = Attendance::where('student_id', $student->id)
                ->where('schedule_id', $schedule->id)
                ->where('attendance_date', $data['attendance_date'])
                ->where('school_id', $schoolId)
                ->first();

            if ($existing) {
                throw new AttendanceException('Absensi untuk siswa ini pada jadwal dan tanggal tersebut sudah ada.');
            }

            // Create attendance record (using firstOrCreate)
            $attendance = Attendance::firstOrCreate(
                [
                    'student_id' => $student->id,
                    'schedule_id' => $schedule->id,
                    'attendance_date' => $data['attendance_date'],
                    'school_id' => $schoolId,
                ],
                [
                    'status' => $data['status'],
                    'check_in_time' => now()->format('H:i:s'),
                    'is_manual' => true,
                    'attendance_type' => 'manual',
                    'recorded_by' => $recordedBy,
                    'notes' => $data['notes'] ?? null,
                ]
            );

            // Log manual attendance
            Log::channel('audit')->info('manual_attendance_created', [
                'attendance_id' => $attendance->id,
                'student_id' => $student->id,
                'schedule_id' => $schedule->id,
                'recorded_by' => $recordedBy,
                'school_id' => $schoolId,
                'timestamp' => now(),
            ]);

            DB::commit();

            // Load relationships
            $attendance->load(['student', 'schedule.subject', 'schedule.class']);

            return $attendance;

        } catch (AttendanceException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Manual attendance failed', [
                'data' => $data,
                'recorded_by' => $recordedBy,
                'error' => $e->getMessage(),
            ]);
            throw new AttendanceException('Gagal menyimpan absensi manual: ' . $e->getMessage());
        }
    }

    /**
     * Bulk manual attendance input for multiple students
     * 
     * Uses transaction and firstOrCreate to prevent duplicates
     * All queries are school-scoped
     * 
     * @param int $sessionId Schedule ID
     * @param array $students Array of ['student_id' => int, 'status' => string]
     * @param User $teacher Teacher recording the attendance
     * @return array Summary of created/updated records
     * @throws AttendanceException
     */
    public function bulkManualAttendance(int $sessionId, array $students, User $teacher): array
    {
        // Validate teacher role
        if (!in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
            throw new AttendanceException('Hanya guru yang dapat mencatat absensi manual.');
        }

        // Validate session exists and belongs to teacher
        $schedule = Schedule::where('id', $sessionId)
            ->where('school_id', $teacher->school_id)
            ->first();

        if (!$schedule) {
            throw new AttendanceException('Sesi tidak ditemukan atau tidak valid.');
        }

        // Validate teacher owns this session
        if ($schedule->teacher_id !== $teacher->id) {
            throw new AttendanceException('Anda tidak memiliki akses untuk mencatat absensi pada sesi ini.');
        }

        // Validate students array
        if (empty($students)) {
            throw new AttendanceException('Data siswa tidak boleh kosong.');
        }

        $validStatuses = ['present', 'late', 'sick', 'permit', 'alpha'];
        $today = now()->format('Y-m-d');
        $created = 0;
        $updated = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($students as $studentData) {
                // Validate student data structure
                if (!isset($studentData['student_id']) || !isset($studentData['status'])) {
                    $errors[] = 'Data siswa tidak lengkap (student_id dan status wajib diisi)';
                    continue;
                }

                $studentId = $studentData['student_id'];
                $status = $studentData['status'];
                $notes = $studentData['notes'] ?? null;

                // Validate status
                if (!in_array($status, $validStatuses)) {
                    $errors[] = "Status tidak valid untuk siswa ID {$studentId}";
                    continue;
                }

                // Validate student belongs to school
                $student = User::where('id', $studentId)
                    ->where('school_id', $teacher->school_id)
                    ->where('role_type', 'student')
                    ->where('is_active', true)
                    ->first();

                if (!$student) {
                    $errors[] = "Siswa ID {$studentId} tidak ditemukan atau tidak aktif";
                    continue;
                }

                // Use firstOrCreate to prevent duplicates
                $attendance = Attendance::firstOrCreate(
                    [
                        'student_id' => $studentId,
                        'schedule_id' => $sessionId,
                        'attendance_date' => $today,
                        'school_id' => $teacher->school_id,
                    ],
                    [
                        'status' => $status,
                        'check_in_time' => now()->format('H:i:s'),
                        'is_manual' => true,
                        'attendance_type' => 'manual',
                        'recorded_by' => $teacher->id,
                        'notes' => $notes,
                    ]
                );

                if ($attendance->wasRecentlyCreated) {
                    $created++;
                } else {
                    // Update existing record
                    $attendance->update([
                        'status' => $status,
                        'is_manual' => true,
                        'recorded_by' => $teacher->id,
                        'notes' => $notes,
                    ]);
                    $updated++;
                }
            }

            // Log bulk manual attendance
            Log::channel('audit')->info('manual_attendance', [
                'teacher_id' => $teacher->id,
                'session_id' => $sessionId,
                'school_id' => $teacher->school_id,
                'total_students' => count($students),
                'created' => $created,
                'updated' => $updated,
                'errors_count' => count($errors),
                'timestamp' => now(),
            ]);

            DB::commit();

            return [
                'success' => true,
                'created' => $created,
                'updated' => $updated,
                'total_processed' => $created + $updated,
                'errors' => $errors,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk manual attendance failed', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'error' => $e->getMessage(),
            ]);
            throw new AttendanceException('Gagal menyimpan absensi manual: ' . $e->getMessage());
        }
    }

    /**
     * Generate daily attendance report
     * 
     * All queries are school-scoped
     * 
     * @param int $schoolId
     * @param string $date Format: YYYY-MM-DD
     * @return array
     */
    public function generateDailyReport(int $schoolId, string $date): array
    {
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Invalid date format. Use YYYY-MM-DD.');
        }

        // Single aggregation query for attendance stats
        $attendanceStats = Attendance::selectRaw('
            COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as present_count,
            COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as late_count,
            COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as sick_count,
            COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as permit_count,
            COUNT(DISTINCT student_id) as total_attended
        ', ['present', 'late', 'sick', 'permit'])
            ->where('school_id', $schoolId)
            ->whereDate('attendance_date', $date)
            ->first();

        // Get total active students for this school
        $totalStudents = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->count();

        $totalAttended = $attendanceStats->total_attended ?? 0;
        $alpha = max(0, $totalStudents - $totalAttended);

        return [
            'date' => $date,
            'school_id' => $schoolId,
            'total_students' => $totalStudents,
            'total_attended' => $totalAttended,
            'attendance_rate' => $totalStudents > 0 ? round(($totalAttended / $totalStudents) * 100, 1) : 0,
            'present' => $attendanceStats->present_count ?? 0,
            'late' => $attendanceStats->late_count ?? 0,
            'sick' => $attendanceStats->sick_count ?? 0,
            'permission' => $attendanceStats->permit_count ?? 0,
            'alpha' => $alpha,
        ];
    }

    /**
     * Get student attendance history
     * 
     * School-scoped query
     * 
     * @param int $studentId
     * @param int $schoolId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getStudentHistory(int $studentId, int $schoolId, int $limit = 30)
    {
        return Attendance::with(['schedule.subject', 'schedule.class'])
            ->where('student_id', $studentId)
            ->where('school_id', $schoolId)
            ->orderBy('attendance_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get class attendance for specific schedule
     * 
     * School-scoped query
     * 
     * @param int $scheduleId
     * @param int $schoolId
     * @param string $date
     * @return array
     */
    public function getClassAttendance(int $scheduleId, int $schoolId, string $date): array
    {
        // Get schedule with class and students
        $schedule = Schedule::with('class.students', 'subject', 'teacher')
            ->where('id', $scheduleId)
            ->where('school_id', $schoolId)
            ->firstOrFail();

        // Get attendances for this schedule and date
        $attendances = Attendance::with('student')
            ->where('schedule_id', $scheduleId)
            ->where('school_id', $schoolId)
            ->whereDate('attendance_date', $date)
            ->get()
            ->keyBy('student_id');

        // Merge students with their attendance status
        $students = $schedule->class->students->map(function ($student) use ($attendances) {
            $attendance = $attendances->get($student->id);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'username' => $student->username,
                'status' => $attendance ? $attendance->status : 'alpha',
                'check_in_time' => $attendance ? $attendance->check_in_time : null,
                'is_manual' => $attendance ? $attendance->is_manual : false,
                'attendance_id' => $attendance ? $attendance->id : null,
            ];
        });

        return [
            'schedule' => [
                'id' => $schedule->id,
                'subject' => $schedule->subject->name,
                'class' => $schedule->class->name,
                'teacher' => $schedule->teacher->name,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
            ],
            'date' => $date,
            'students' => $students,
            'summary' => [
                'total' => $students->count(),
                'present' => $students->where('status', 'present')->count(),
                'late' => $students->where('status', 'late')->count(),
                'sick' => $students->where('status', 'sick')->count(),
                'permission' => $students->where('status', 'permit')->count(),
                'alpha' => $students->where('status', 'alpha')->count(),
            ],
        ];
    }

    /**
     * Validate manual input data
     * 
     * @param array $data
     * @throws AttendanceException
     */
    private function validateManualInputData(array $data): void
    {
        $required = ['school_id', 'student_id', 'schedule_id', 'attendance_date', 'status'];
        
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new AttendanceException("Field {$field} is required.");
            }
        }

        // Validate status
        $validStatuses = ['present', 'late', 'sick', 'permit', 'alpha'];
        if (!in_array($data['status'], $validStatuses)) {
            throw new AttendanceException('Invalid attendance status.');
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['attendance_date'])) {
            throw new AttendanceException('Invalid date format. Use YYYY-MM-DD.');
        }
    }
}

