<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    protected $impersonationService;

    public function __construct(ImpersonationService $impersonationService)
    {
        $this->impersonationService = $impersonationService;
    }

    public function start(Request $request, $userId)
    {
        $admin = Auth::user();
        $targetUser = User::findOrFail($userId);

        if ($this->impersonationService->impersonate($admin, $targetUser)) {
            return response()->json([
                'success' => true,
                'message' => 'Impersonation started',
                'redirect' => $targetUser->role_type === 'teacher' ? '/teacher/dashboard' : '/student/dashboard',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Impersonation failed or not allowed',
        ], 403);
    }

    public function stop()
    {
        if ($this->impersonationService->stopImpersonating()) {
            return response()->json([
                'success' => true,
                'message' => 'Impersonation ended',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Not currently impersonating',
        ], 400);
    }
}
