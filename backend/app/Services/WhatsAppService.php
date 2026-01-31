<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private $apiUrl;

    private $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.whatsapp.api_url');
        $this->apiKey = config('services.whatsapp.api_key');
    }

    /**
     * Send WhatsApp notification
     *
     * @param  string  $phoneNumber  Phone number with country code (e.g., 628123456789)
     * @param  string  $message  Message content
     * @return array Response from WhatsApp API
     */
    public function sendNotification(string $phoneNumber, string $message): array
    {
        if (! $this->apiUrl || ! $this->apiKey) {
            Log::warning('WhatsApp API configuration missing');

            return ['success' => false, 'message' => 'WhatsApp API not configured'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl.'/send-message', [
                'phone' => $phoneNumber,
                'message' => $message,
            ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['success' => false, 'message' => 'Failed to send WhatsApp message'];
        } catch (\Exception $e) {
            Log::error('WhatsApp send error: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send attendance notification to parent
     */
    public function sendAttendanceNotification($student, $attendance): array
    {
        // Check if student has parent with phone number
        $parent = $student->parents()->first();

        if (! $parent || ! $parent->phone) {
            return ['success' => false, 'message' => 'Parent phone number not available'];
        }

        $statusLabel = match ($attendance->status) {
            'present' => 'HADIR',
            'late' => 'TERLAMBAT',
            'sick' => 'SAKIT',
            'permit' => 'IZIN',
            default => 'ALPHA',
        };

        $message = "*[AbsensiQR Pro - {$student->school->name}]*\n\n";
        $message .= "Kepada Yth. Orang Tua/Wali\n";
        $message .= "{$parent->name}\n\n";
        $message .= "Kami informasikan bahwa:\n";
        $message .= "Nama: *{$student->name}*\n";
        $message .= 'Kelas: '.($student->activeClass && $student->activeClass->class ? $student->activeClass->class->name : '-')."\n";
        $message .= "Status: *{$statusLabel}*\n";
        $message .= "Waktu: {$attendance->check_in_time}\n";
        $message .= "Tanggal: {$attendance->attendance_date}\n\n";
        $message .= "Terima kasih atas perhatian Anda.\n";
        $message .= 'Sistem AbsensiQR Pro';

        return $this->sendNotification($parent->phone, $message);
    }

    /**
     * Send bulk notification (for testing or announcements)
     */
    public function sendBulkNotification(array $recipients, string $message): array
    {
        $results = [];

        foreach ($recipients as $recipient) {
            $result = $this->sendNotification($recipient['phone'], $message);
            $results[] = [
                'phone' => $recipient['phone'],
                'name' => $recipient['name'] ?? 'Unknown',
                'success' => $result['success'],
            ];
        }

        return [
            'total' => count($recipients),
            'sent' => collect($results)->where('success', true)->count(),
            'failed' => collect($results)->where('success', false)->count(),
            'details' => $results,
        ];
    }
}
