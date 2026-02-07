<?php

namespace App\Listeners;

use App\Events\RiskLevelUpdated;
use App\Models\AuditLog;
use App\Models\ClassModel;
use App\Models\ClassStudent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendRiskNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(RiskLevelUpdated $event): void
    {
        try {
            $riskProfile = $event->riskProfile;
            $student = User::find($riskProfile->student_id);
            if (! $student) {
                return;
            }

            // Get Parent and Homeroom Teacher
            $parents = $this->getParents($student);
            $homeroomTeacher = $this->getHomeroomTeacher($student);

            $message = "Kehadiran anak Anda menurun. Mohon perhatian. Level Risiko: {$riskProfile->risk_level}.";

            // Notify Parent
            foreach ($parents as $parent) {
                $this->createNotification($parent, 'PERINGATAN RISIKO', $message, 'risk');
            }

            // Notify Homeroom
            if ($homeroomTeacher) {
                $this->createNotification($homeroomTeacher, 'PERINGATAN RISIKO SISWA', "Siswa {$student->name} memiliki risiko kehadiran: {$riskProfile->risk_level}.", 'risk');
            }

            AuditLog::create([
                'school_id' => $student->school_id ?? 1,
                'user_id' => null,
                'action' => 'notification_sent',
                'module' => 'notification',
                'severity' => 'warning',
                'description' => "Sent risk notification for {$student->name}",
                'ip_address' => 'system',
                'user_agent' => 'system',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send risk notification: '.$e->getMessage());
        }
    }

    private function createNotification($user, $title, $message, $type)
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
        ]);
    }

    private function getParents($student)
    {
        return $student->parents;
    }

    private function getHomeroomTeacher($student)
    {
        $classStudent = ClassStudent::where('student_id', $student->id)->latest()->first();
        if ($classStudent) {
            $class = ClassModel::find($classStudent->class_id);
            if ($class && $class->homeroom_teacher_id) {
                return User::find($class->homeroom_teacher_id);
            }
        }

        return null;
    }
}
