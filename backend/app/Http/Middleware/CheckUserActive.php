<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Log for debugging
        \Log::info('CheckUserActive middleware running', [
            'has_user' => $request->user() !== null,
            'user_id' => $request->user()?->id,
            'is_active' => $request->user()?->is_active,
        ]);

        // Check if user is authenticated
        if ($request->user()) {
            $user = $request->user();

            // Check if user account is active
            if (! $user->is_active) {
                \Log::warning('User account is inactive', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                // Revoke all tokens for this user
                $user->tokens()->delete();

                // Return error response
                return response()->json([
                    'success' => false,
                    'message' => 'Akun Anda telah dinonaktifkan oleh Administrator.',
                    'error' => 'ACCOUNT_INACTIVE',
                    'details' => [
                        'reason' => 'Akun Anda tidak aktif',
                        'action' => 'Silakan hubungi Super Admin untuk mengaktifkan kembali akun Anda.',
                        'contact' => 'Email: amhyer21091993@gmail.com atau Telepon: 082352538105',
                    ],
                ], 403);
            }

            // Check if user's school is active (except for super_admin)
            if ($user->school_id && $user->school) {
                if (! $user->school->is_active) {
                    \Log::warning('User school is inactive', [
                        'user_id' => $user->id,
                        'school_id' => $user->school_id,
                        'school_name' => $user->school->name,
                    ]);

                    // Revoke all tokens for this user
                    $user->tokens()->delete();

                    // Return error response
                    return response()->json([
                        'success' => false,
                        'message' => 'Sekolah Anda sedang tidak aktif.',
                        'error' => 'SCHOOL_INACTIVE',
                        'details' => [
                            'reason' => 'Sekolah Anda sedang dalam status nonaktif',
                            'action' => 'Silakan hubungi administrator platform untuk informasi lebih lanjut.',
                            'contact' => 'Email: amhyer21091993@gmail.com atau Telepon: 082352538105',
                        ],
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
