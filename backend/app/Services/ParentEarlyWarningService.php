<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ParentEarlyWarningService
{
    /**
     * Trigger early warning for parents if student risk is medium or high
     *
     * @param  string  $riskLevel  ('medium'|'high')
     */
    public function triggerEarlyWarning(User $student, string $riskLevel)
    {
        if (! in_array($riskLevel, ['medium', 'high'])) {
            return;
        }
        $message = 'Anak Anda menunjukkan penurunan kehadiran. Mohon pantau kehadiran minggu ini.';
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
     * Send notification to parent
     *
     * ARCH-02 FIX: Sebelumnya hanya stub/log. Sekarang membuat record
     * Notification in-app untuk orang tua (bisa ditampilkan di menu
     * notifikasi). Channel FCM/WA/Email tetap bisa ditambahkan di masa depan.
     */
    private function sendToParent(User $student, array $payload)
    {
        $parentId = $payload['to_parent_id'] ?? $student->parent_id;

        if ($parentId) {
            try {
                Notification::create([
                    'user_id' => $parentId,
                    'school_id' => $student->school_id,
                    'title' => $payload['title'] ?? 'Peringatan Dini Kehadiran',
                    'message' => $payload['body'] ?? $payload['message'] ?? '',
                    'type' => 'risk',
                ]);
            } catch (\Throwable $e) {
                // Notification DB gagal — jangan blokir alur, cukup log
                Log::channel('single')->warning('[ParentEarlyWarning] Gagal simpan Notification', [
                    'parent_id' => $parentId,
                    'student_id' => $student->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // In production, integrate with FCM/WA/Email
        Log::channel('single')->info('[ParentEarlyWarning] Notif to parent of student '.$student->id, $payload);
    }
}
