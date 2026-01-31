<?php

namespace App\Observers;

use App\Models\ImmutableSecurityLog;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\ImmutableSecurityLogService;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        \App\Models\AuditLog::create([
            'user_id' => auth()->id(), // Admin who created the user
            'school_id' => $user->school_id, // Associated school
            'action' => 'create_user',
            'description' => "Created new user: {$user->name} ({$user->role_type})",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Handle the User "updating" event.
     * Triggered BEFORE the update is persisted - for token revocation.
     */
    public function updating(User $user): void
    {
        // Check if password is being changed
        if ($user->isDirty('password')) {
            $this->handlePasswordChange($user);
        }

        // Check if account is being deactivated
        if ($user->isDirty('is_active') && !$user->is_active) {
            $this->handleAccountDeactivation($user);
        }
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged()) {
            \App\Models\AuditLog::create([
                'user_id' => auth()->id(),
                'school_id' => $user->school_id,
                'action' => 'update_user',
                'description' => "Updated user: {$user->name}",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'school_id' => $user->school_id,
            'action' => 'delete_user',
            'description' => "Deleted user: {$user->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Handle password change - revoke all tokens for security.
     */
    protected function handlePasswordChange(User $user): void
    {
        Log::channel('security')->info('Password change detected, revoking all tokens', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        // Revoke all refresh tokens
        $refreshCount = RefreshToken::revokeAllForUser($user->id, RefreshToken::REVOKED_PASSWORD_CHANGE);

        // Revoke all Sanctum access tokens
        $accessCount = $user->tokens()->count();
        $user->tokens()->delete();

        // Log to immutable security trail
        try {
            $logService = app(ImmutableSecurityLogService::class);
            $logService->write(
                ImmutableSecurityLog::TYPE_ADMIN_ACTION,
                "Password changed - all tokens revoked ({$accessCount} access, {$refreshCount} refresh)",
                $user->id,
                $user->school_id,
                [
                    'action' => 'password_change_token_revoke',
                    'access_tokens_revoked' => $accessCount,
                    'refresh_tokens_revoked' => $refreshCount,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log password change to immutable log', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle account deactivation - revoke all tokens.
     */
    protected function handleAccountDeactivation(User $user): void
    {
        Log::channel('security')->info('Account deactivated, revoking all tokens', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        // Revoke all refresh tokens
        RefreshToken::revokeAllForUser($user->id, RefreshToken::REVOKED_ADMIN);

        // Revoke all Sanctum access tokens
        $user->tokens()->delete();

        // Log to immutable security trail
        try {
            $logService = app(ImmutableSecurityLogService::class);
            $logService->write(
                ImmutableSecurityLog::TYPE_ADMIN_ACTION,
                "Account deactivated - all tokens revoked",
                $user->id,
                $user->school_id,
                [
                    'action' => 'account_deactivation_token_revoke',
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log account deactivation to immutable log', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
