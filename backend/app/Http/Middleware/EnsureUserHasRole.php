<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * BE-08 FIX: Role 'admin' dianggap sama dengan 'school_admin' untuk kompatibilitas
     * antara frontend (yang menggunakan 'admin') dan backend (yang menggunakan 'school_admin').
     *
     * Mapping alias role:
     * - 'admin' → 'school_admin' (backward compatibility)
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $userRole = $request->user()->role_type;

        // BE-08 FIX: Normalize alias roles untuk kompatibilitas
        $roleAliases = [
            'admin' => 'school_admin',  // 'admin' adalah alias untuk 'school_admin'
        ];

        // Normalize user's role (jika user punya role alias, gunakan canonical name)
        $normalizedUserRole = $roleAliases[$userRole] ?? $userRole;

        // Normalize roles yang diizinkan (expand aliases)
        $normalizedAllowedRoles = [];
        foreach ($roles as $role) {
            $normalizedAllowedRoles[] = $role;
            // Tambahkan juga alias dari role ini
            $reversedAliases = array_flip($roleAliases);
            if (isset($reversedAliases[$role])) {
                $normalizedAllowedRoles[] = $reversedAliases[$role];
            }
        }

        // Cek apakah user's role (atau normalized-nya) ada dalam daftar roles yang diizinkan
        if (! in_array($userRole, $normalizedAllowedRoles) && ! in_array($normalizedUserRole, $normalizedAllowedRoles)) {
            return response()->json([
                'message' => 'Akses ditolak. Role Anda tidak memiliki izin.',
                'required_roles' => $roles,
                'your_role' => $userRole,
            ], 403);
        }

        return $next($request);
    }
}
