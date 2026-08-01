<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-App Notification
 *
 * Simple notification for in-app display via database channel.
 * Used for real-time updates within the application UI.
 */
class InAppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $type;
    protected string $title;
    protected string $body;
    protected array $data;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $type, string $title, string $body, array $data = [])
    {
        $this->type = $type;
        $this->title = $title;
        $this->body = $body;
        $this->data = $data;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->body,
            'data' => $this->data,
        ];
    }
}
