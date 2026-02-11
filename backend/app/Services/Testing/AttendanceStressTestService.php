<?php

namespace App\Services\Testing;

use App\Models\User;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Attendance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Pool\Pool;

class AttendanceStressTestService
{
    protected $school;
    protected $schedule;
    protected $students;
    protected $tokens = [];

    /**
     * Setup test data
     */
    public function setup(int $count): array
    {
        $setupStart = microtime(true);
        
        DB::transaction(function () use ($count) {
            $this->school = School::factory()->create(['name' => 'Stress Test School ' . Str::random(5)]);
            
            $user = User::factory()->create(['school_id' => $this->school->id, 'role_type' => 'teacher']); 
            $subject = \App\Models\Subject::factory()->create(['school_id' => $this->school->id]);
            $class = \App\Models\ClassModel::factory()->create(['school_id' => $this->school->id]);
            
            $this->schedule = Schedule::factory()->create([
                'school_id' => $this->school->id,
                'class_id' => $class->id,
                'subject_id' => $subject->id,
                'teacher_id' => $user->id,
                'start_time' => Carbon::now()->subHour()->format('H:i:s'),
                'end_time' => Carbon::now()->addHour()->format('H:i:s'),
                'day_of_week' => strtolower(Carbon::now()->englishDayOfWeek),
            ]);

            // Create Students
            $studentsData = [];
            $now = now();
            for ($i = 0; $i < $count; $i++) {
                $studentsData[] = [
                    'name' => "Stress Student {$i}",
                    'email' => "stress{$i}_" . Str::random(5) . "@test.com",
                    'username' => "stress{$i}_" . Str::random(5),
                    // Use a simple password hash for speed
                    'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
                    'role_type' => 'student',
                    'school_id' => $this->school->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            User::insert($studentsData);
            
            $this->students = User::where('school_id', $this->school->id)
                ->where('role_type', 'student')
                ->where('email', 'like', 'stress%@test.com')
                ->limit($count) // get newly created ones
                ->get();
            
            // Generate Tokens
            $this->tokens = [];
            foreach ($this->students as $student) {
                $this->tokens[] = $student->createToken('stress-test')->plainTextToken;
            }
        });

        return [
            'duration' => microtime(true) - $setupStart,
            'schedule_id' => $this->schedule->id,
            'students_count' => count($this->students)
        ];
    }

    /**
     * Execute stress test
     */
    public function execute(string $baseUrl, int $concurrency, callable $progressCallback = null): array
    {
        $payload = $this->generateQrPayload();
        
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'times' => [],
            'status_codes' => []
        ];

        $chunks = array_chunk($this->tokens, $concurrency);
        $startTime = microtime(true);

        foreach ($chunks as $chunkTokens) {
            $responses = Http::pool(function (Pool $pool) use ($baseUrl, $payload, $chunkTokens) {
                foreach ($chunkTokens as $token) {
                    $pool->withToken($token)
                         ->withHeaders([
                             'X-Device-Id' => 'stress-test-device',
                             'Accept' => 'application/json'
                         ])
                         ->post("{$baseUrl}/api/v1/attendance/scan", $payload);
                }
            });

            foreach ($responses as $response) {
                if ($response instanceof \Exception) {
                    $results['failed']++;
                    $results['errors'][] = $response->getMessage();
                } else {
                    $code = $response->status();
                    $results['status_codes'][$code] = ($results['status_codes'][$code] ?? 0) + 1;
                    
                    if ($response->successful()) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                        $body = $response->json();
                        $msg = $body['message'] ?? 'Unknown Error';
                        $results['errors'][$msg] = ($results['errors'][$msg] ?? 0) + 1;
                    }
                }
                
                if ($progressCallback) {
                    $progressCallback();
                }
            }
        }

        $results['duration'] = microtime(true) - $startTime;
        $results['integrity'] = $this->checkIntegrity();

        return $results;
    }

    /**
     * Generate common payload
     */
    protected function generateQrPayload()
    {
        $data = [
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
            'expires_at' => now()->addHours(1)->toIso8601String(),
            'idempotency_key' => Str::uuid()->toString(), 
            'nonce' => Str::random(16),
        ];

        $signature = hash_hmac('sha256', json_encode($data), config('app.key'));

        return [
            'qr_payload' => [
                'data' => $data,
                'signature' => $signature
            ]
        ];
    }

    /**
     * Verify database state
     */
    protected function checkIntegrity(): array
    {
        $dbCount = Attendance::where('schedule_id', $this->schedule->id)
            ->whereIn('student_id', $this->students->pluck('id'))
            ->count();
            
        $distinctCount = Attendance::where('schedule_id', $this->schedule->id)
            ->whereIn('student_id', $this->students->pluck('id'))
            ->distinct('student_id')
            ->count();

        return [
            'db_records' => $dbCount,
            'distinct_students' => $distinctCount,
            'duplicates' => $dbCount - $distinctCount
        ];
    }
}
