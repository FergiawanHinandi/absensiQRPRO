<?php

namespace App\Http\Middleware;

use App\Services\MultiTenantRestoreValidationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ValidateMultiTenantRestore
{
    private $validationService;

    public function __construct(MultiTenantRestoreValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    public function handle(Request $request, Closure $next)
    {
        // Only validate restore endpoints
        if (!$this->isRestoreEndpoint($request)) {
            return $next($request);
        }

        $backupIdentifier = $request->input('backup_identifier');
        $schoolId = $this->extractSchoolId($request);

        if (!$backupIdentifier || !$schoolId) {
            return response()->json([
                'success' => false,
                'message' => 'Missing backup identifier or school ID for multi-tenant validation'
            ], 400);
        }

        // Construct backup path
        $backupPath = storage_path("app/backups/database/{$backupIdentifier}.sql");

        // Perform validation
        $validationResult = $this->validationService->validateBackupForRestore($backupPath, $schoolId);

        // Log validation attempt
        Log::channel('security')->info('Multi-tenant restore validation attempt', [
            'user' => auth()->user()->id,
            'backup_identifier' => $backupIdentifier,
            'school_id' => $schoolId,
            'restore_id' => $validationResult['restore_id'],
            'valid' => $validationResult['valid'],
            'error_count' => $validationResult['error_count'],
            'warning_count' => $validationResult['warning_count']
        ]);

        // Abort if validation fails
        if (!$validationResult['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Multi-tenant validation failed - restore aborted for safety',
                'validation_result' => $validationResult
            ], 422);
        }

        // Add validation result to request for potential use in controller
        $request->merge(['multi_tenant_validation' => $validationResult]);

        return $next($request);
    }

    private function isRestoreEndpoint(Request $request): bool
    {
        $path = $request->path();
        $method = $request->method();

        // Check if this is a restore endpoint
        return (
            ($method === 'POST' && str_contains($path, '/rollback/execute')) ||
            ($method === 'POST' && str_contains($path, '/restore/execute')) ||
            ($method === 'POST' && str_contains($path, '/backup/restore'))
        );
    }

    private function extractSchoolId(Request $request): ?int
    {
        // Try to get school_id from various sources
        $schoolId = null;

        // From request body
        if ($request->has('school_id')) {
            $schoolId = $request->input('school_id');
        }

        // From user's current school context (if applicable)
        if (!$schoolId && auth()->user() && auth()->user()->current_school_id) {
            $schoolId = auth()->user()->current_school_id;
        }

        // From user's school (if single school admin)
        if (!$schoolId && auth()->user() && auth()->user()->school_id) {
            $schoolId = auth()->user()->school_id;
        }

        return $schoolId ? (int) $schoolId : null;
    }
}
