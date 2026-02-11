<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Report Export Completed Notification
 * 
 * Notifies user when their attendance report export is ready
 * 
 * CHANNELS:
 * - Database (in-app notification)
 * - Mail (optional)
 */
class ReportExportCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    protected $exportData;

    /**
     * Create a new notification instance.
     */
    public function __construct(array $exportData)
    {
        $this->exportData = $exportData;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database']; // Add 'mail' if you want email notifications
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Laporan Absensi Siap Diunduh')
            ->greeting('Halo ' . $notifiable->name . '!')
            ->line('Laporan absensi yang Anda minta sudah siap.')
            ->line('Tipe: ' . $this->getReportTypeName())
            ->line('Waktu proses: ' . $this->exportData['execution_time'] . ' detik')
            ->action('Unduh Laporan', $this->exportData['download_url'])
            ->line('Link download akan kedaluwarsa dalam 7 hari.')
            ->line('Terima kasih telah menggunakan sistem kami!');
    }

    /**
     * Get the array representation of the notification (for database).
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report_export_completed',
            'title' => 'Laporan Siap Diunduh',
            'message' => 'Laporan absensi ' . $this->getReportTypeName() . ' sudah siap diunduh.',
            'report_type' => $this->exportData['type'],
            'filename' => $this->exportData['filename'],
            'download_url' => $this->exportData['download_url'],
            'execution_time' => $this->exportData['execution_time'],
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ];
    }

    /**
     * Get human-readable report type name
     */
    protected function getReportTypeName(): string
    {
        return match ($this->exportData['type']) {
            'daily' => 'Harian',
            'monthly' => 'Bulanan',
            'student' => 'Per Siswa',
            'school' => 'Sekolah',
            default => 'Absensi',
        };
    }
}
