<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    /**
     * Register a new student/parent account.
     *
     * Only student and parent roles can self-register.
     * Teacher/admin accounts must be created by school admin.
     *
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'role_type' => ['required', 'in:student,parent'],
            'school_code' => ['required', 'string', 'exists:schools,school_code'],
        ]);

        $school = School::where('school_code', $validated['school_code'])->firstOrFail();

        if (! $school->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Sekolah tidak aktif. Hubungi administrator.',
            ], 422);
        }

        $user = DB::transaction(function () use ($validated, $school) {
            $user = User::create([
                'school_id' => $school->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'username' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'role_type' => $validated['role_type'],
                'is_active' => false, // Requires admin approval
            ]);

            $user->assignRole($validated['role_type']);

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => 'Registrasi berhasil. Akun Anda memerlukan persetujuan admin sekolah sebelum dapat digunakan.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'role_type' => $user->role_type,
                'school' => $school->name,
                'requires_approval' => true,
            ],
        ], 201);
    }
}
