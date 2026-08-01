<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $filePath;
    public $format;
    public $month;

    public function __construct(string $filePath, string $format, string $month)
    {
        $this->filePath = $filePath;
        $this->format = strtoupper($format);
        $this->month = $month;
    }

    public function via($notifiable)
    {
        return ['database']; // For in-app notifications
    }

    public function toDatabase($notifiable)
    {
        // Using storage url for frontend download
        return [
            'type' => 'report_ready',
            'title' => 'Laporan Selesai',
            'message' => "Laporan Absensi bulan {$this->month} format {$this->format} sudah siap diunduh.",
            'file_url' => url("storage/{$this->filePath}"),
            'file_path' => $this->filePath,
        ];
    }
}
