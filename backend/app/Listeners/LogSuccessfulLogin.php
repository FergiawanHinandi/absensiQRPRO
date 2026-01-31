<?php

namespace App\Listeners;

class LogSuccessfulLogin
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(\Illuminate\Auth\Events\Login $event): void
    {
        $user = $event->user;
        \App\Models\AuditLog::create([
            'user_id' => $user->id,
            'school_id' => $user->school_id,
            'action' => 'user_login',
            'description' => "User logged in: {$user->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
