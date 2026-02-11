<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TenantContextMiddleware
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $roleType = $user->role_type ?? $user->role ?? null;

        if ($roleType === 'super_admin') {
            $this->tenantContext->setSuperAdmin($user->id);

            // If super admin targets a specific school via header or param
            $targetSchoolId = $request->header('X-School-Id')
                ?? $request->input('school_id');

            if ($targetSchoolId) {
                $this->tenantContext->set(
                    schoolId: (int) $targetSchoolId,
                    userId: $user->id,
                    isSuperAdmin: true,
                );
            }
        } elseif ($user->school_id) {
            $this->tenantContext->set(
                schoolId: (int) $user->school_id,
                userId: $user->id,
            );
        } else {
            Log::warning('User has no school_id and is not super_admin', [
                'user_id' => $user->id,
                'role' => $roleType,
            ]);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->reset();
    }
}
