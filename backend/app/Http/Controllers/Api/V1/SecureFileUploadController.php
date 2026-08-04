<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SecureFileUploadRequest;
use App\Services\SecureFileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            $result = $this->fileUploadService->uploadFile($request, $this->owner($request));

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('Secure file upload failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'File upload gagal. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * Get secure download URL
     */
    public function download(Request $request, string $filePath): JsonResponse
    {
        try {
            $result = $this->fileUploadService->serveFile($filePath, $this->owner($request));

            return response()->json([
                'success' => true,
                'message' => 'Download URL generated',
                'data' => array_merge($result, [
                    'expires_in_minutes' => 60,
                ]),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Secure file download failed', [
                'user_id' => $request->user()?->id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat URL download.',
            ], 500);
        }
    }

    /**
     * Delete file
     */
    public function destroy(Request $request, string $filePath): JsonResponse
    {
        try {
            $deleted = $this->fileUploadService->deleteFile($filePath, $this->owner($request));

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'File deleted successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan atau gagal dihapus.',
            ], 404);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Secure file deletion failed', [
                'user_id' => $request->user()?->id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus file.',
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
            $files = $this->fileUploadService->getUserFiles($this->owner($request), $category);

            return response()->json([
                'success' => true,
                'data' => [
                    'files' => $files,
                    'total' => count($files),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Secure file listing failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar file.',
            ], 500);
        }
    }

    /**
     * Get file metadata
     */
    public function show(Request $request, string $filePath): JsonResponse
    {
        try {
            $result = $this->fileUploadService->serveFile($filePath, $this->owner($request));

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Secure file metadata failed', [
                'user_id' => $request->user()?->id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil metadata file.',
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

            if (! $expectedHash) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hash is required for validation',
                ], 400);
            }

            $isValid = $this->fileUploadService->validateFileIntegrity(
                $filePath,
                $expectedHash,
                $this->owner($request)
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'valid' => $isValid,
                    'file_path' => $filePath,
                    'validated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Secure file validation failed', [
                'user_id' => $request->user()?->id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memvalidasi file.',
            ], 500);
        }
    }

    /**
     * Stream an owned file's content (signed URL target).
     */
    public function serve(Request $request, string $filePath): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        try {
            $this->fileUploadService->serveFile($filePath, $this->owner($request));

            return \Illuminate\Support\Facades\Storage::disk('secure_uploads')->download($filePath);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Secure file serve failed', [
                'user_id' => $request->user()?->id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengunduh file.',
            ], 500);
        }
    }

    /**
     * Resolve the authenticated owner (user_id + school_id) for scoping.
     *
     * @return array{user_id: int, school_id: int|null}
     */
    private function owner(Request $request): array
    {
        $user = $request->user();

        return [
            'user_id' => $user->id,
            'school_id' => $user->school_id,
        ];
    }
}
