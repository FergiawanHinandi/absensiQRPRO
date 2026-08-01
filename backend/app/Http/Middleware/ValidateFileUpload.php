<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * ValidateFileUpload Middleware
 *
 * Secures file upload endpoints by validating:
 * - Allowed MIME types per context (images, documents)
 * - Maximum file size
 * - Actual file content signature (magic bytes)
 * - Filename sanitization
 *
 * OWASP A05: Security Misconfiguration
 * OWASP A03: Injection (via malicious file uploads)
 */
class ValidateFileUpload
{
    /**
     * Allowed MIME types for image uploads.
     */
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Allowed MIME types for document uploads.
     */
    private const ALLOWED_DOCUMENT_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain',
    ];

    /**
     * Maximum file size per context (in MB).
     */
    private const MAX_IMAGE_SIZE_MB = 5;
    private const MAX_DOCUMENT_SIZE_MB = 10;
    private const MAX_PHOTO_SIZE_MB = 2;

    /**
     * Dangerous filename patterns to block.
     */
    private const DANGEROUS_FILENAME_PATTERNS = [
        '/\.php\d*$/i',
        '/\.phtml$/i',
        '/\.shtml$/i',
        '/\.cgi$/i',
        '/\.pl$/i',
        '/\.py$/i',
        '/\.asp$/i',
        '/\.aspx$/i',
        '/\.exe$/i',
        '/\.sh$/i',
        '/\.bat$/i',
        '/\.cmd$/i',
        '/\.htaccess/i',
        '/\.htpasswd/i',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $context = 'image'): Response
    {
        $files = $request->allFiles();

        if (empty($files)) {
            return $next($request);
        }

        foreach ($files as $key => $file) {
            if (is_array($file)) {
                foreach ($file as $singleFile) {
                    $validation = $this->validateFile($singleFile, $context);
                    if ($validation !== null) {
                        return $validation;
                    }
                }
            } elseif ($file instanceof UploadedFile) {
                $validation = $this->validateFile($file, $context);
                if ($validation !== null) {
                    return $validation;
                }
            }
        }

        return $next($request);
    }

    /**
     * Validate a single uploaded file.
     */
    private function validateFile(UploadedFile $file, string $context): ?Response
    {
        // 1. Check for upload errors
        if (!$file->isValid()) {
            return response()->json([
                'success' => false,
                'error' => 'UPLOAD_ERROR',
                'message' => 'File upload failed due to server error.',
            ], 422);
        }

        // 2. Validate filename (no path traversal, no dangerous extensions)
        $filename = $file->getClientOriginalName();
        if ($this->hasDangerousFilename($filename)) {
            \Illuminate\Support\Facades\Log::channel('security')->warning('File upload blocked - dangerous filename', [
                'filename' => $filename,
                'context' => $context,
                'ip' => request()->ip(),
                'user_id' => request()->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'INVALID_FILENAME',
                'message' => 'Nama file tidak valid.',
            ], 422);
        }

        // 3. Validate MIME type
        $allowedMimes = $this->getAllowedMimes($context);
        $mimeType = $file->getMimeType();

        if (!in_array($mimeType, $allowedMimes)) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_FILE_TYPE',
                'message' => 'Tipe file tidak diizinkan untuk ' . $context . '.',
            ], 422);
        }

        // 4. Validate file size
        $maxSize = $this->getMaxSize($context);
        $maxBytes = $maxSize * 1024 * 1024;

        if ($file->getSize() > $maxBytes) {
            return response()->json([
                'success' => false,
                'error' => 'FILE_TOO_LARGE',
                'message' => 'Ukuran file melebihi batas ' . $maxSize . 'MB.',
            ], 422);
        }

        // 5. Validate content signature (magic bytes) for images
        if ($context === 'photo' || $context === 'image') {
            if (!$this->validateImageContent($file)) {
                \Illuminate\Support\Facades\Log::channel('security')->warning('File upload blocked - invalid image content', [
                    'filename' => $filename,
                    'claimed_mime' => $mimeType,
                    'ip' => request()->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'INVALID_IMAGE_CONTENT',
                    'message' => 'File bukan gambar yang valid.',
                ], 422);
            }
        }

        return null;
    }

    /**
     * Check if filename contains dangerous patterns.
     */
    private function hasDangerousFilename(string $filename): bool
    {
        // Block path traversal
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return true;
        }

        // Block dangerous extensions
        foreach (self::DANGEROUS_FILENAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get allowed MIME types for context.
     */
    private function getAllowedMimes(string $context): array
    {
        return match ($context) {
            'photo' => ['image/jpeg', 'image/png', 'image/webp'],
            'image' => self::ALLOWED_IMAGE_MIMES,
            'document' => self::ALLOWED_DOCUMENT_MIMES,
            'avatar' => ['image/jpeg', 'image/png', 'image/webp'],
            'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
            default => self::ALLOWED_IMAGE_MIMES,
        };
    }

    /**
     * Get max file size in MB for context.
     */
    private function getMaxSize(string $context): int
    {
        return match ($context) {
            'photo' => self::MAX_PHOTO_SIZE_MB,
            'image' => self::MAX_IMAGE_SIZE_MB,
            'document' => self::MAX_DOCUMENT_SIZE_MB,
            'avatar' => self::MAX_PHOTO_SIZE_MB,
            default => self::MAX_IMAGE_SIZE_MB,
        };
    }

    /**
     * Validate actual image content via magic bytes.
     */
    private function validateImageContent(UploadedFile $file): bool
    {
        $handle = fopen($file->getPathname(), 'rb');
        if (!$handle) {
            return false;
        }

        $bytes = fread($handle, 8);
        fclose($handle);

        if ($bytes === false || strlen($bytes) < 8) {
            return false;
        }

        // Check magic bytes for common image formats
        // JPEG: FF D8 FF
        if (bin2hex(substr($bytes, 0, 3)) === 'ffd8ff') {
            return true;
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (bin2hex(substr($bytes, 0, 8)) === '89504e470d0a1a0a') {
            return true;
        }

        // GIF: 47 49 46 38 (GIF8)
        if (in_array(bin2hex(substr($bytes, 0, 4)), ['47494638', '474946383961', '474946383761'])) {
            return true;
        }

        // WebP: 52 49 46 46 (RIFF) + WEBP marker at bytes 8-11
        if (bin2hex(substr($bytes, 0, 4)) === '52494646' &&
            strlen($bytes) >= 12 &&
            substr($bytes, 8, 4) === 'WEBP') {
            return true;
        }

        return false;
    }
}
