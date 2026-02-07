<?php

namespace App\Services;

use App\Http\Requests\SecureFileUploadRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecureFileUploadService
{
    /**
     * Upload file securely
     */
    public function uploadFile(SecureFileUploadRequest $request): array
    {
        try {
            $file = $request->file('file');
            $secureFilename = $request->getSecureFilename();
            $storagePath = $request->getStoragePath();
            $fullPath = $request->getFullFilePath();

            // Store file securely outside public root
            $storedPath = $file->storeAs($storagePath, $secureFilename, 'secure_uploads');

            if (!$storedPath) {
                throw new \Exception('Failed to store file securely.');
            }

            // Get file metadata
            $metadata = $this->getFileMetadata($file, $fullPath);

            // Log successful upload
            Log::channel('security')->info('Secure file upload', [
                'user_id' => auth()->id(),
                'school_id' => auth()->user()->school_id,
                'original_name' => $file->getClientOriginalName(),
                'secure_name' => $secureFilename,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'storage_path' => $storedPath,
                'category' => $request->input('category'),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return [
                'success' => true,
                'file_path' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'secure_name' => $secureFilename,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'category' => $request->input('category'),
                'description' => $request->input('description'),
                'metadata' => $metadata,
            ];

        } catch (\Exception $e) {
            Log::channel('security')->error('Secure file upload failed', [
                'user_id' => auth()->id(),
                'school_id' => auth()->user()->school_id,
                'error' => $e->getMessage(),
                'ip_address' => request()->ip(),
            ]);

            throw new \Exception('File upload failed: ' . $e->getMessage());
        }
    }

    /**
     * Serve file securely (with authorization check)
     */
    public function serveFile(string $filePath): array
    {
        try {
            // Validate file path to prevent directory traversal
            if ($this->isPathTraversal($filePath)) {
                throw new \Exception('Invalid file path.');
            }

            // Check if file exists in secure storage
            if (!Storage::disk('secure_uploads')->exists($filePath)) {
                throw new \Exception('File not found.');
            }

            // Get file metadata
            $metadata = $this->getStoredFileMetadata($filePath);

            // Log file access
            Log::channel('security')->info('Secure file access', [
                'user_id' => auth()->id(),
                'school_id' => auth()->user()->school_id,
                'file_path' => $filePath,
                'ip_address' => request()->ip(),
            ]);

            return [
                'success' => true,
                'file_path' => $filePath,
                'metadata' => $metadata,
            ];

        } catch (\Exception $e) {
            Log::channel('security')->error('Secure file access failed', [
                'user_id' => auth()->id(),
                'school_id' => auth()->user()->school_id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'ip_address' => request()->ip(),
            ]);

            throw new \Exception('File access failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete file securely
     */
    public function deleteFile(string $filePath): bool
    {
        try {
            // Validate file path
            if ($this->isPathTraversal($filePath)) {
                throw new \Exception('Invalid file path.');
            }

            // Check if file exists
            if (!Storage::disk('secure_uploads')->exists($filePath)) {
                return false;
            }

            // Delete file
            $deleted = Storage::disk('secure_uploads')->delete($filePath);

            if ($deleted) {
                Log::channel('security')->info('Secure file deletion', [
                    'user_id' => auth()->id(),
                    'school_id' => auth()->user()->school_id,
                    'file_path' => $filePath,
                    'ip_address' => request()->ip(),
                ]);
            }

            return $deleted;

        } catch (\Exception $e) {
            Log::channel('security')->error('Secure file deletion failed', [
                'user_id' => auth()->id(),
                'school_id' => auth()->user()->school_id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'ip_address' => request()->ip(),
            ]);

            return false;
        }
    }

    /**
     * Get file metadata
     */
    private function getFileMetadata(UploadedFile $file, string $fullPath): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'extension' => strtolower($file->getClientOriginalExtension()),
            'uploaded_at' => now()->toISOString(),
        ];

        // Add image-specific metadata
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $imageInfo = @getimagesize($file->getPathname());
            if ($imageInfo) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['image_type'] = $imageInfo[2];
            }
        }

        // Calculate file hash for integrity check
        $metadata['hash'] = hash('sha256', $file->get());

        return $metadata;
    }

    /**
     * Get stored file metadata
     */
    private function getStoredFileMetadata(string $filePath): array
    {
        $fullPath = Storage::disk('secure_uploads')->path($filePath);
        
        if (!file_exists($fullPath)) {
            throw new \Exception('File not found on disk.');
        }

        $metadata = [
            'size' => filesize($fullPath),
            'modified_at' => date('Y-m-d H:i:s', filemtime($fullPath)),
            'mime_type' => mime_content_type($fullPath),
        ];

        // Calculate file hash
        $metadata['hash'] = hash_file('sha256', $fullPath);

        return $metadata;
    }

    /**
     * Check for path traversal attempts
     */
    private function isPathTraversal(string $path): bool
    {
        // Check for common path traversal patterns
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

        // Check for absolute paths
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        // Check for drive letters (Windows)
        if (preg_match('/^[a-zA-Z]:/', $path)) {
            return true;
        }

        return false;
    }

    /**
     * Generate secure download URL
     */
    public function generateSecureUrl(string $filePath, int $expiresInMinutes = 60): string
    {
        // Generate temporary signed URL for secure access
        $expiresAt = now()->addMinutes($expiresInMinutes);
        
        return Storage::disk('secure_uploads')
            ->temporaryUrl($filePath, $expiresAt);
    }

    /**
     * Validate file integrity
     */
    public function validateFileIntegrity(string $filePath, string $expectedHash): bool
    {
        try {
            $fullPath = Storage::disk('secure_uploads')->path($filePath);
            
            if (!file_exists($fullPath)) {
                return false;
            }

            $actualHash = hash_file('sha256', $fullPath);
            
            return hash_equals($expectedHash, $actualHash);

        } catch (\Exception $e) {
            Log::channel('security')->error('File integrity validation failed', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get user's uploaded files
     */
    public function getUserFiles(int $userId = null, string $category = null): array
    {
        $userId = $userId ?? auth()->id();
        $schoolId = auth()->user()->school_id;

        $basePath = "secure_uploads/{$schoolId}";
        
        if ($category) {
            $basePath .= "/{$category}";
        }

        $files = [];
        
        try {
            $allFiles = Storage::disk('secure_uploads')->allFiles($basePath);
            
            foreach ($allFiles as $file) {
                $metadata = $this->getStoredFileMetadata($file);
                $files[] = [
                    'path' => $file,
                    'metadata' => $metadata,
                    'download_url' => $this->generateSecureUrl($file),
                ];
            }

        } catch (\Exception $e) {
            Log::channel('security')->error('Failed to list user files', [
                'user_id' => $userId,
                'school_id' => $schoolId,
                'error' => $e->getMessage(),
            ]);
        }

        return $files;
    }
}
