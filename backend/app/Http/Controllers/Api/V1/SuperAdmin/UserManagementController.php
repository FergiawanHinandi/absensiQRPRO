<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreUserRequest;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserManagementController extends Controller
{
    /**
     * Get all school admins
     */
    public function getAdmins(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');

        $query = User::role('school_admin') // Use spatie scope if possible, or whereHas('roles', ...)
            ->with(['school' => function ($q) {
                $q->select('id', 'name');
            }]);

        // Fallback if role scope fails or direct column use prefered
        // $query = User::where('role_type', 'school_admin')->with('school');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('email', 'ILIKE', "%{$search}%")
                    ->orWhere('username', 'ILIKE', "%{$search}%");
            });
        }

        $admins = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $admins,
        ]);
    }

    /**
     * Store new School Admin
     */
    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();

        // Auto generate username from email if not provided (or just use email part)
        $username = $validated['username'] ?? explode('@', $validated['email'])[0].rand(100, 999);

        // Create User
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $username,
            'password' => Hash::make($validated['password']),
            'school_id' => $validated['school_id'],
            'role_type' => $validated['role_type'], // Use from request
            'is_active' => true,
        ]);

        // Assign Role (Spatie)
        $user->assignRole('school_admin');

        return response()->json([
            'success' => true,
            'message' => 'Admin sekolah berhasil ditambahkan',
            'data' => $user,
        ]);
    }

    /**
     * Reset admin access (password/2fa)
     */
    public function resetAccess(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'type' => 'required|in:password',
            'new_password' => 'required_if:type,password|min:6',
        ]);

        $user = User::findOrFail($validated['user_id']);

        // Security check: CANNOT reset Super Admin unless Self
        if ($user->hasRole('super_admin') && auth()->id() !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Cannot reset Super Admin'], 403);
        }

        // Limit reset to School Admins only as per requirement
        if (! $user->hasRole('school_admin') && ! $user->hasRole('admin')) {
            return response()->json(['success' => false, 'message' => 'Hanya akun Admin Sekolah yang dapat direset melalui menu ini.'], 403);
        }

        $emailSent = false;

        if ($validated['type'] === 'password') {
            $user->password = Hash::make($validated['new_password']);
            $user->save();

            // Send Email Notification
            try {
                \Illuminate\Support\Facades\Log::info('Mencoba mengirim email reset password ke: '.$user->email);

                // Gunakan send() biasa, tapi pastikan queue worker jalan atau ubah Mailable agar tidak ShouldQueue sementara
                // Atau kita gunakan trik: menghapus implementasi ShouldQueue dari Mailable jika ingin instan di local

                \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\SchoolAdminResetPasswordMail($user, $validated['new_password']));

                \Illuminate\Support\Facades\Log::info('Email berhasil dikirim ke: '.$user->email);
                $emailSent = true;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Gagal mengirim email reset password ke '.$user->email.': '.$e->getMessage());
                // Don't fail the request, just log it
            }

            // Log to Audit Logs Table
            \App\Models\AuditLog::create([
                'user_id' => auth()->id(), // Super Admin who performed the action
                'school_id' => $user->school_id, // Target user's school
                'action' => 'reset_password_school_admin',
                'description' => "Reset password for user: {$user->name} ({$user->email})",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $emailSent
                ? 'Akses berhasil direset dan notifikasi telah dikirim ke email.'
                : 'Akses berhasil direset, namun gagal mengirim notifikasi email.',
            'email_sent' => $emailSent,
        ]);
    }

    /**
     * Get activity logs
     */
    public function activityLogs(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');
        $actionFilter = $request->input('action');

        $query = \App\Models\AuditLog::with(['user', 'school']);

        // Search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ILIKE', "%{$search}%")
                    ->orWhere('action', 'ILIKE', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'ILIKE', "%{$search}%");
                    });
            });
        }

        // Action Filter
        if ($actionFilter && $actionFilter !== 'all') {
            $query->where('action', $actionFilter);
        }

        $logsPagination = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Transform collection to match frontend interface
        $logsPagination->getCollection()->transform(function ($log) {
            return [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'user_name' => $log->user->name ?? 'Unknown User',
                'school_name' => $log->school ? $log->school->name : ($log->user && $log->user->school ? $log->user->school->name : '-'),
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $logsPagination,
        ]);
    }

    /**
     * Toggle user active status
     */
    public function toggleStatus(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        // Security: Super Admin cannot deactivate own account
        if ($user->id === auth()->id()) {
             return response()->json(['message' => 'Cannot deactivate your own account.'], 403);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => $user->is_active ? 'User diaktifkan' : 'User dinonaktifkan',
            'data' => [
                'is_active' => $user->is_active,
            ],
        ]);
    }
}
