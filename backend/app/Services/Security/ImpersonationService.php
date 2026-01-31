<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class ImpersonationService
{
    /**
     * Start impersonating a user.
     *
     * @param User $impersonator The admin user
     * @param User $targetUser The user to impersonate
     * @return bool
     */
    public function impersonate(User $impersonator, User $targetUser): bool
    {
        // Prevent recursive impersonation
        if (Session::has('impersonator_id')) {
            return false; 
        }

        // Only Super Admin can impersonate (or maybe School Admin for their teachers?) 
        // Requirement says "Super Admin has highest privileges... can impersonate school admins or teachers"
        if (!$impersonator->hasRole('super_admin')) {
             // For now restrict to super admin based on prompt scope
            return false;
        }

        // Prevent impersonating another Super Admin
        if ($targetUser->hasRole('super_admin')) {
            return false;
        }

        // Log the start of impersonation
        Log::channel('superadmin')->info('Impersonation Started', [
            'impersonator_id' => $impersonator->id,
            'target_user_id' => $targetUser->id,
            'timestamp' => now()
        ]);

        // Login as the user
        Auth::login($targetUser);

        // Store original ID in session
        Session::put('impersonator_id', $impersonator->id);
        Session::put('impersonation_start', now());

        return true;
    }

    /**
     * Stop impersonating.
     *
     * @return bool
     */
    public function stopImpersonating(): bool
    {
        if (!Session::has('impersonator_id')) {
            return false;
        }

        $impersonatorId = Session::get('impersonator_id');
        $impersonator = User::find($impersonatorId);

        // Log end
        Log::channel('superadmin')->info('Impersonation Ended', [
            'impersonator_id' => $impersonatorId,
            'impersonated_user_id' => Auth::id(), // The user we were just pretending to be
            'duration' => now()->diffInSeconds(Session::get('impersonation_start')),
            'timestamp' => now()
        ]);

        // Flush session keys
        Session::forget(['impersonator_id', 'impersonation_start']);

        // Login back as admin
        if ($impersonator) {
            Auth::login($impersonator);
            return true;
        }

        // Fallback if admin user deleted?
        Auth::logout();
        return false;
    }

    /**
     * Check if currently impersonating.
     */
    public function isImpersonating(): bool
    {
        return Session::has('impersonator_id');
    }

    public function getImpersonatorId()
    {
        return Session::get('impersonator_id');
    }
}
