<?php

namespace App\Policies;

use App\Models\QrCode;
use App\Models\User;

class QrCodePolicy
{
    /**
     * Determine if user can view QR code
     */
    public function view(User $user, QrCode $qrCode): bool
    {
        // Same school only
        return $user->school_id === $qrCode->school_id;
    }

    /**
     * Determine if user can generate QR code
     */
    public function create(User $user): bool
    {
        return in_array($user->role_type, ['teacher', 'homeroom_teacher']) && $user->is_active;
    }

    /**
     * Determine if user can close/deactivate QR code
     */
    public function close(User $user, QrCode $qrCode): bool
    {
        // Same school only
        if ($user->school_id !== $qrCode->school_id) {
            return false;
        }

        // Only teachers can close
        return in_array($user->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin']);
    }

    /**
     * Determine if user can delete QR code
     */
    public function delete(User $user, QrCode $qrCode): bool
    {
        // Same school only
        if ($user->school_id !== $qrCode->school_id) {
            return false;
        }

        // Only admins can delete
        return in_array($user->role_type, ['admin', 'school_admin', 'super_admin']);
    }
}
