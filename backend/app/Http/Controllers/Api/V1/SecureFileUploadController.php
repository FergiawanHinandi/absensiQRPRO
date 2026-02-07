<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SecureFileUploadRequest;
use App\Services\SecureFileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecureFileUploadController extends Controller
{
    private $fileUploadService;

    public function __construct(SecureFileUploadService $fileUploadService)
    {
        $this->fileUploadService = $fileUploadService;
    }

    /**
     * Upload file securely
     */
    public function upload(SecureFileUploadRequest $request): JsonResponse
    {
        try {
            $result = $this->fileUploadService->uploadFile($request);

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'File upload failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get secure download URL
     */
    public function download(Request $request, string $filePath): JsonResponse
    {
        try {
            // Validate file path
            if ($this->isPathTraversal($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid file path',
                ], 400);
            }

            $result = $this->fileUploadService->serveFile($filePath);

            // Generate secure download URL
            $downloadUrl = $this->fileUploadService->generateSecureUrl($filePath);

            return response()->json([
                'success' => true,
                'message' => 'Download URL generated',
                'data' => array_merge($result, [
                    'download_url' => $downloadUrl,
                    'expires_in_minutes' => 60,
                ]),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate download URL: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete file
     */
    public function destroy(Request $request, string $filePath): JsonResponse
    {
        try {
            // Validate file path
            if ($this->isPathTraversal($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid file path',
                ], 400);
            }

            $deleted = $this->fileUploadService->deleteFile($filePath);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'File deleted successfully',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found or could not be deleted',
                ], 404);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'File deletion failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's uploaded files
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $category = $request->input('category');
            $files = $this->fileUploadService->getUserFiles(null, $category);

            return response()->json([
                'success' => true,
                'data' => [
                    'files' => $files,
                    'total' => count($files),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve files: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get file metadata
     */
    public function show(Request $request, string $filePath): JsonResponse
    {
        try {
            // Validate file path
            if ($this->isPathTraversal($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid file path',
                ], 400);
            }

            $result = $this->fileUploadService->serveFile($filePath);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve file metadata: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate file integrity
     */
    public function validate(Request $request, string $filePath): JsonResponse
    {
        try {
            $expectedHash = $request->input('hash');
            
            if (!$expectedHash) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hash is required for validation',
                ], 400);
            }

            // Validate file path
            if ($this->isPathTraversal($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid file path',
                ], 400);
            }

            $isValid = $this->fileUploadService->validateFileIntegrity($filePath, $expectedHash);

            return response()->json([
                'success' => true,
                'data' => [
                    'valid' => $isValid,
                    'file_path' => $filePath,
                    'validated_at' => now()->toISOString(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'File validation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check for path traversal attempts
     */
    private function isPathTraversal(string $path): bool
    {
        $traversalPatterns = [
            '../',
            '..\\',
            '%2e%2e%2f',
            '%2e%2e%5c',
            '..%2f',
            '..%5c',
            '%2e%2e/',
            '%2e%2e\\',
            '....//',
            '....\\\\',
        ];

        foreach ($traversalPatterns as $pattern) {
            if (str_contains(strtolower($path), $pattern)) {
                return true;
            }
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        if (preg_match('/^[a-zA-Z]:/', $path)) {
            return true;
        }

        return false;
    }
}
