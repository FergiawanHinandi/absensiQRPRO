<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AttendanceCheckInService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttendanceStressWorkerCommand extends Command
{
    protected $signature = 'attendance:stress-worker 
                            {--student_id= : The ID of the student} 
                            {--token= : The QR Token}
                            {--lat= : Latitude}
                            {--lng= : Longitude}';

    protected $description = 'Worker process for attendance stress test';

    public function handle(AttendanceCheckInService $service)
    {
        // Suppress standard output to keep JSON clean
        $studentId = $this->option('student_id');
        $token = $this->option('token');
        $lat = $this->option('lat');
        $lng = $this->option('lng');

        $startTime = microtime(true);

        try {
            $student = User::find($studentId);
            
            if (!$student) {
                $this->outputJson('error', 'Student not found', 0);
                return 1;
            }

            // Mock Request
            $request = Request::create('/api/v1/attendance/scan', 'POST', [
                'qr_token' => $token,
                'lat' => $lat,
                'lng' => $lng,
                'device_id' => 'stress-test-' . Str::random(8),
            ]);
            $request->setUserResolver(fn () => $student);

            // Execute Service
            // We use checkIn for Student Scan simulation
            $data = [
                'qr_token' => $token,
                'lat' => $lat,
                'lng' => $lng,
                'device_id' => 'stress-test-' . Str::random(8),
                'request_id' => (string) Str::uuid(),
            ];

            $result = $service->checkIn($student, $data, $request);

            $duration = microtime(true) - $startTime;

            $this->outputJson('success', 'Check-in successful', $duration, [
                'attendance_id' => $result->attendance->id,
                'status' => $result->status
            ]);

            return 0;

        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            $this->outputJson('error', $e->getMessage(), $duration);
            return 1;
        }
    }

    private function outputJson($status, $message, $duration, $data = [])
    {
        echo json_encode([
            'status' => $status,
            'message' => $message,
            'duration' => $duration,
            'data' => $data,
            'pid' => getmypid()
        ]);
    }
}
