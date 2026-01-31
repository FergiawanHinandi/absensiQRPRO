<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class ParentEarlyWarningService
{
    /**
     * Trigger early warning for parents if student risk is medium or high
     * @param User $student
     * @param string $riskLevel ('medium'|'high')
     */
    public function triggerEarlyWarning(User $student, string $riskLevel)
    {
        if (!in_array($riskLevel, ['medium', 'high'])) {
            return;
        }
        $message = "Anak Anda menunjukkan penurunan kehadiran. Mohon pantau kehadiran minggu ini.";
        $payload = $this->buildNotificationPayload($student, $riskLevel, $message);
        $this->sendToParent($student, $payload);
    }

    /**
     * Build notification payload for parent
     */
    public function buildNotificationPayload(User $student, string $riskLevel, string $message): array
    {
        return [
            'to_parent_id' => $student->parent_id ?? null,
            'student_id' => $student->id,
            'student_name' => $student->name,
            'risk_level' => $riskLevel,
            'type' => 'attendance_early_warning',
            'title' => 'Peringatan Dini Kehadiran',
            'body' => $message,
            'data' => [
                'student_id' => $student->id,
                'risk_level' => $riskLevel,
            ],
        ];
    }

    /**
     * Send notification to parent (stub/log only)
     */
    private function sendToParent(User $student, array $payload)
    {
        // In production, integrate with FCM/WA/Email
        Log::channel('single')->info('[ParentEarlyWarning] Notif to parent of student '.$student->id, $payload);
    }
}
