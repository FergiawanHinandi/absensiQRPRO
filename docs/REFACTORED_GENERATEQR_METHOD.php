<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * REFACTORED AttendanceService::generateQR() - Atomic & Race-Condition Safe
 * 
 * Requirements Met:
 * 1. ✅ Only 1 active QR per schedule
 * 2. ✅ Redis key: qr_active:{school_id}:{schedule_id}
 * 3. ✅ Redis::set($key, json_encode($payload), 'EX', 60, 'NX')
 * 4. ✅ Returns existing payload if key already exists
 * 5. ✅ Returns 503 if Redis is down
 * 6. ✅ Logs: qr_generated, qr_reused, qr_race_condition
 * 7. ✅ 100% Atomic
 * 8. ✅ Race-condition safe
 */
class AttendanceServiceRefactoredQR
{
    /**
     * Generate QR code for attendance session - Atomic Implementation
     * 
     * Ensures only 1 active QR per schedule using Redis atomic operations.
     * 
     * @param int $sessionId Schedule ID for the attendance session
     * @param User $teacher The teacher generating the QR
     * @param int $expirySeconds QR expiry time in seconds (default: 60)
     * @return array QR payload with signature
     * @throws AttendanceException
     */
    public function generateQR(int $sessionId, User $teacher, int $expirySeconds = 60): array
    {
        // ============================================================
        // STEP 1: VALIDATE TEACHER ROLE
        // ============================================================
        
        if (!in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
            throw new AttendanceException('Hanya guru yang dapat membuat QR absensi.');
        }

        // ============================================================
        // STEP 2: FETCH AND VALIDATE SCHEDULE
        // ============================================================
        
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

        // ============================================================
        // STEP 3: VALIDATE SCHOOL MATCH
        // ============================================================
        
        if ($schedule->school_id !== $teacher->school_id) {
            Log::channel('audit')->warning('qr_generation_failed_school_mismatch', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'teacher_school_id' => $teacher->school_id,
                'schedule_school_id' => $schedule->school_id,
            ]);
            
            throw new AttendanceException('Sesi tidak ditemukan atau tidak valid.');
        }

        // ============================================================
        // STEP 4: VALIDATE TEACHER OWNERSHIP
        // ============================================================
        
        if ($schedule->teacher_id !== $teacher->id) {
            Log::channel('audit')->warning('qr_generation_failed_teacher_mismatch', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'schedule_teacher_id' => $schedule->teacher_id,
                'school_id' => $teacher->school_id,
            ]);
            
            throw new AttendanceException('Anda tidak memiliki akses untuk membuat QR pada sesi ini.');
        }

        // ============================================================
        // STEP 5: VALIDATE SCHEDULE IS ACTIVE
        // ============================================================
        
        if (!$schedule->is_active) {
            Log::channel('audit')->warning('qr_generation_failed_inactive_schedule', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'is_active' => $schedule->is_active,
            ]);
            
            throw new AttendanceException('Sesi ini tidak aktif dan tidak dapat digunakan untuk absensi.');
        }

        // ============================================================
        // STEP 6: REDIS ATOMIC OPERATION - ENSURE ONLY 1 ACTIVE QR
        // ============================================================
        // Key format: qr_active:{school_id}:{schedule_id}
        // This ensures only ONE active QR per schedule at any time
        // ============================================================
        
        $redisKey = "qr_active:{$teacher->school_id}:{$sessionId}";
        
        // Check Redis availability
        try {
            Redis::ping();
        } catch (\Exception $e) {
            Log::channel('audit')->critical('qr_generation_redis_unavailable', [
                'session_id' => $sessionId,
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'error' => $e->getMessage(),
            ]);
            
            // Return 503 Service Unavailable if Redis is down
            throw new AttendanceException(
                'Layanan QR code sedang tidak tersedia. Silakan hubungi administrator.',
                503
            );
        }

        // ============================================================
        // STEP 7: CHECK FOR EXISTING ACTIVE QR (IDEMPOTENT)
        // ============================================================
        
        try {
            $existingQR = Redis::get($redisKey);
            
            if ($existingQR) {
                // Active QR already exists - return it (idempotent behavior)
                $existingPayload = json_decode($existingQR, true);
                
                Log::channel('audit')->info('qr_reused', [
                    'teacher_id' => $teacher->id,
                    'session_id' => $sessionId,
                    'school_id' => $teacher->school_id,
                    'class' => $schedule->class->name ?? null,
                    'subject' => $schedule->subject->name ?? null,
                    'created_at' => $existingPayload['created_at'] ?? null,
                    'ttl_remaining' => Redis::ttl($redisKey),
                ]);
                
                return [
                    'data' => $existingPayload['data'] ?? [],
                    'signature' => $existingPayload['signature'] ?? '',
                    'expires_at' => $existingPayload['expires_at'] ?? null,
                    'valid_for_seconds' => Redis::ttl($redisKey),
                    'session_info' => [
                        'class' => $schedule->class->name ?? null,
                        'subject' => $schedule->subject->name ?? null,
                        'start_time' => $schedule->start_time,
                        'end_time' => $schedule->end_time,
                    ],
                    'reused' => true,
                ];
            }

            // ============================================================
            // STEP 8: GENERATE NEW QR PAYLOAD
            // ============================================================
            
            $now = now();
            $sessionToken = Str::uuid()->toString();
            $expiresAt = $now->copy()->addSeconds($expirySeconds);
            
            $payload = [
                'id' => $sessionId, // schedule_id for backward compatibility
                'schedule_id' => $sessionId,
                'school_id' => $teacher->school_id,
                'teacher_id' => $teacher->id,
                'class_id' => $schedule->class_id,
                'subject_id' => $schedule->subject_id,
                'token' => $sessionToken,
                'generated_at' => $now->timestamp,
                'exp' => $expiresAt->timestamp,
                'expires_at' => $expiresAt->toIso8601String(),
            ];

            // Generate HMAC signature for security
            $signature = hash_hmac('sha256', json_encode($payload), config('app.key'));
            
            // Prepare full QR data for Redis storage
            $qrData = [
                'data' => $payload,
                'signature' => $signature,
                'expires_at' => $expiresAt->toIso8601String(),
                'created_at' => $now->toIso8601String(),
                'teacher_id' => $teacher->id,
                'session_id' => $sessionId,
                'school_id' => $teacher->school_id,
            ];
            
            // ============================================================
            // STEP 9: ATOMIC SET WITH NX (SET IF NOT EXISTS)
            // ============================================================
            // This is the CRITICAL atomic operation
            // SET key value EX 60 NX
            // - Returns true if key was set (we won the race)
            // - Returns false/null if key already exists (lost the race)
            // ============================================================
            
            $setResult = Redis::set(
                $redisKey,
                json_encode($qrData),
                'EX',
                $expirySeconds,
                'NX'
            );
            
            if ($setResult === false || $setResult === null) {
                // ============================================================
                // RACE CONDITION DETECTED
                // ============================================================
                // Another request set the key between our GET and SET
                // This is expected behavior under high concurrency
                // Return the winning QR (idempotent behavior)
                
                Log::channel('audit')->warning('qr_race_condition', [
                    'teacher_id' => $teacher->id,
                    'session_id' => $sessionId,
                    'school_id' => $teacher->school_id,
                    'attempted_token' => $sessionToken,
                    'reason' => 'Another request created QR first',
                ]);
                
                // Retrieve the winning QR
                $winningQR = Redis::get($redisKey);
                
                if ($winningQR) {
                    $winningPayload = json_decode($winningQR, true);
                    
                    return [
                        'data' => $winningPayload['data'] ?? [],
                        'signature' => $winningPayload['signature'] ?? '',
                        'expires_at' => $winningPayload['expires_at'] ?? null,
                        'valid_for_seconds' => Redis::ttl($redisKey),
                        'session_info' => [
                            'class' => $schedule->class->name ?? null,
                            'subject' => $schedule->subject->name ?? null,
                            'start_time' => $schedule->start_time,
                            'end_time' => $schedule->end_time,
                        ],
                        'reused' => true,
                        'race_resolved' => true,
                    ];
                }
                
                // Edge case: winning QR disappeared (extremely rare)
                throw new AttendanceException('Gagal membuat QR code. Silakan coba lagi.');
            }
            
            // ============================================================
            // STEP 10: SUCCESS - LOG AND RETURN NEW QR
            // ============================================================
            
            Log::channel('audit')->info('qr_generated', [
                'teacher_id' => $teacher->id,
                'session_id' => $sessionId,
                'school_id' => $teacher->school_id,
                'class' => $schedule->class->name ?? null,
                'subject' => $schedule->subject->name ?? null,
                'token' => $sessionToken,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'expires_at' => $expiresAt->toIso8601String(),
                'expiry_seconds' => $expirySeconds,
            ]);

            return [
                'data' => $payload,
                'signature' => $signature,
                'expires_at' => $expiresAt->toIso8601String(),
                'valid_for_seconds' => $expirySeconds,
                'session_info' => [
                    'class' => $schedule->class->name ?? null,
                    'subject' => $schedule->subject->name ?? null,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                ],
                'reused' => false,
            ];

        } catch (AttendanceException $e) {
            // Re-throw attendance exceptions as-is
            throw $e;
        } catch (\Exception $e) {
            // Catch Redis errors and return 503
            Log::channel('audit')->error('qr_generation_redis_error', [
                'teacher_id' => $teacher->id,
                'session_id' => $sessionId,
                'school_id' => $teacher->school_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw new AttendanceException(
                'Layanan QR code sedang tidak tersedia. Silakan coba lagi.',
                503
            );
        }
    }
}
