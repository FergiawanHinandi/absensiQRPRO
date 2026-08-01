<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReportFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $month;

    public function __construct($month = null)
    {
        $this->month = $month;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $monthText = $this->month ? " bulan {$this->month}" : '';
        return [
            'type' => 'report_failed',
            'title' => 'Laporan Gagal',
            'message' => "Maaf, proses generate laporan absensi{$monthText} gagal. Silakan coba lagi nanti.",
        ];
    }
}
