<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountGeneratorController extends Controller
{
    /**
     * Generate accounts for multiple users (bulk)
     */
    public function generateBulk(Request $request)
    {
        $request->validate([
            'type' => 'required|in:student,teacher,parent',
            'accounts' => 'required|array',
            'accounts.*.id' => 'required|exists:users,id',
            'accounts.*.password' => 'required|string|min:6',
        ]);

        $schoolId = $request->user()->school_id;
        $type = $request->validate(['type' => 'required|in:student,teacher,parent'])['type'];

        $results = [];

        foreach ($request->input('accounts') as $accountData) {
            $user = User::where('school_id', $schoolId)
                ->where('role_type', $type)
                ->find($accountData['id']);

            if ($user) {
                // Ensure they don't already have an active password login (if desired)
                // For now, if "has_account" implies they have a username, 
                // we can just update their password.
                $user->password = Hash::make($accountData['password']);
                if (empty($user->username)) {
                    // Generate a default username if empty
                    $baseUsername = strtolower(explode(' ', $user->name)[0] . $user->id);
                    $user->username = $baseUsername;
                }
                $user->save();

                $results[] = [
                    'id' => $user->id,
                    'username' => $user->username,
                    'password' => $accountData['password'],
                    'has_account' => true,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Accounts generated successfully',
            'data' => $results,
        ]);
    }

    /**
     * Reset password for a single user
     */
    public function resetPassword(Request $request, $id)
    {
        $request->validate([
            'type' => 'required|in:student,teacher,parent',
            'password' => 'required|string|min:6',
        ]);

        $schoolId = $request->user()->school_id;
        $type = $request->input('type');

        $user = User::where('school_id', $schoolId)
            ->where('role_type', $type)
            ->findOrFail($id);

        $user->password = Hash::make($request->input('password'));
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully',
            'data' => [
                'id' => $user->id,
                'username' => $user->username,
            ],
        ]);
    }
}
