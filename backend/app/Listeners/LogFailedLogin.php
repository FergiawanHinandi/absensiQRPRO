<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Request;

class LogFailedLogin
{
    /**
     * Handle the event.
     */
    public function handle(Failed $event): void
    {
        // Try to identify user if available
        $userId = $event->user ? $event->user->id : null;
        $schoolId = $event->user ? $event->user->school_id : null;
        
        // Log sensitive credentials (like password) MUST BE AVOIDED.
        // We log email/username.
        $credentials = $event->credentials;
        if (isset($credentials['password'])) {
            unset($credentials['password']);
        }

        ActivityLog::create([
            'user_id' => $userId,
            'action' => 'login_failed',
            'model_type' => $userId ? \App\Models\User::class : null,
            'model_id' => $userId,
            'school_id' => $schoolId,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'payload' => [
                'attempted_credentials' => $credentials,
                'is_user_found' => !is_null($userId),
                'guard' => $event->guard,
            ]
        ]);
    }
}
