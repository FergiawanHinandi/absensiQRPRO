<?php

namespace App\Core\Services\RateLimit;

use Illuminate\Http\Request;

/**
 * Tenant-Aware Redis Key Builder for Rate Limiting
 *
 * Generates school-scoped Redis keys ensuring multi-tenant isolation.
 * Different key types: IP, user, school, device, composite.
 *
 * Spec: critical-rate-limiting / tasks.md Task 3
 */
class TenantKeyBuilder
{
    private string $prefix;

    public function __construct()
    {
        $this->prefix = config('rate-limiting.key_prefix', 'rl');
    }

    /**
     * Build a rate limit key for a given request and endpoint type.
     */
    public function build(Request $request, string $endpointKey): string
    {
        $config   = config("rate-limiting.endpoints.{$endpointKey}");
        $keyType  = $config['key_type'] ?? 'ip';

        return match ($keyType) {
            'ip'        => $this->buildIpKey($request, $endpointKey),
            'user'      => $this->buildUserKey($request, $endpointKey),
            'school'    => $this->buildSchoolKey($request, $endpointKey),
            'device'    => $this->buildDeviceKey($request, $endpointKey),
            'composite' => $this->buildCompositeKey($request, $endpointKey),
            default     => $this->buildIpKey($request, $endpointKey),
        };
    }

    /** IP-based key — for login, password reset. */
    public function buildIpKey(Request $request, string $endpoint): string
    {
        $ip = $this->sanitizeIp($request->ip());
        return "{$this->prefix}:{$endpoint}:ip:{$ip}";
    }

    /** User-based key — for general API. Falls back to IP if unauthenticated. */
    public function buildUserKey(Request $request, string $endpoint): string
    {
        $user = $request->user();
        if ($user) {
            return "{$this->prefix}:{$endpoint}:user:{$user->id}:school:{$user->school_id}";
        }
        return $this->buildIpKey($request, $endpoint);
    }

    /** School-based key — for exports (resource protection per school). */
    public function buildSchoolKey(Request $request, string $endpoint): string
    {
        $user     = $request->user();
        $schoolId = $user?->school_id ?? 'unknown';
        return "{$this->prefix}:{$endpoint}:school:{$schoolId}";
    }

    /** Device-based key — for mobile app specific limits. */
    public function buildDeviceKey(Request $request, string $endpoint): string
    {
        $deviceId = $this->extractDeviceId($request);
        return "{$this->prefix}:{$endpoint}:device:{$deviceId}";
    }

    /** Composite key — for QR scan (user + device + school). */
    public function buildCompositeKey(Request $request, string $endpoint): string
    {
        $user     = $request->user();
        $userId   = $user?->id ?? 'guest';
        $schoolId = $user?->school_id ?? 'unknown';
        $deviceId = $this->extractDeviceId($request);
        return "{$this->prefix}:{$endpoint}:composite:u{$userId}:d{$deviceId}:s{$schoolId}";
    }

    /** Parse a rate limit key back to its components. */
    public function parse(string $key): array
    {
        $parts = explode(':', $key);
        return [
            'prefix'   => $parts[0] ?? null,
            'endpoint' => $parts[1] ?? null,
            'type'     => $parts[2] ?? null,
            'value'    => implode(':', array_slice($parts, 3)),
            'raw'      => $key,
        ];
    }

    /** Validate that a key belongs to a specific school (tenant isolation). */
    public function belongsToSchool(string $key, int $schoolId): bool
    {
        return str_contains($key, ":school:{$schoolId}") ||
               str_contains($key, ":s{$schoolId}:");
    }

    private function extractDeviceId(Request $request): string
    {
        $deviceId = $request->header('X-Device-ID')
            ?? $request->input('device_info.device_id')
            ?? $request->input('device_id')
            ?? $request->input('device_fingerprint');

        if ($deviceId) {
            return $this->sanitize($deviceId);
        }

        return 'fp_' . substr(md5(implode('|', array_filter([
            $request->userAgent(),
            $request->header('Accept-Language'),
            $request->ip(),
        ]))), 0, 16);
    }

    private function sanitizeIp(string $ip): string
    {
        return str_replace([':', '.'], '_', $ip);
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $value);
    }
}
