<?php

namespace App\Services;

use App\Http\Requests\SecureFileUploadRequest;
use App\Models\SecureFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SecureFileUploadService
{
    /**
     * Upload file securely and record ownership.
     *
     * @param  array{user_id: int, school_id: int}  $owner
     */
    public function uploadFile(SecureFileUploadRequest $request, array $owner): array
    {
        $file = $request->file('file');
        $secureFilename = $request->getSecureFilename();
        $storagePath = $request->getStoragePath();
        $fullPath = $request->getFullFilePath();

        // Store file securely outside public root
        $storedPath = $file->storeAs($storagePath, $secureFilename, 'secure_uploads');

        if (! $storedPath) {
            throw new \RuntimeException('Failed to store file securely.');
        }

        try {
            $record = SecureFile::create([
                'user_id' => $owner['user_id'],
                'school_id' => $owner['school_id'],
                'storage_path' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'category' => $request->input('category', 'general'),
                'description' => $request->input('description'),
            ]);
        } catch (\Throwable $e) {
            // Never leave an orphan file without an ownership record.
            Storage::disk('secure_uploads')->delete($storedPath);

            throw $e;
        }

        $metadata = $this->getFileMetadata($file, $fullPath);

        Log::channel('security')->info('Secure file upload', [
            'user_id' => $owner['user_id'],
            'school_id' => $owner['school_id'],
            'secure_file_id' => $record->id,
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
            'file_id' => $record->id,
            'file_path' => $storedPath,
            'original_name' => $file->getClientOriginalName(),
            'secure_name' => $secureFilename,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'category' => $request->input('category'),
            'description' => $request->input('description'),
            'metadata' => $metadata,
        ];
    }

    /**
     * Serve file metadata only if the caller owns it.
     *
     * @param  array{user_id: int, school_id: int}  $owner
     */
    public function serveFile(string $filePath, array $owner): array
    {
        $record = $this->findOwnedFile($filePath, $owner);

        Log::channel('security')->info('Secure file access', [
            'user_id' => $owner['user_id'],
            'school_id' => $owner['school_id'],
            'file_id' => $record->id,
            'file_path' => $filePath,
            'ip_address' => request()->ip(),
        ]);

        return [
            'success' => true,
            'file_id' => $record->id,
            'file_path' => $filePath,
            'metadata' => $this->getStoredFileMetadata($filePath),
            'download_url' => $this->generateSecureUrl($filePath),
        ];
    }

    /**
     * Delete file securely, only if the caller owns it.
     *
     * @param  array{user_id: int, school_id: int}  $owner
     */
    public function deleteFile(string $filePath, array $owner): bool
    {
        $record = $this->findOwnedFile($filePath, $owner);

        $deleted = Storage::disk('secure_uploads')->delete($filePath);

        if ($deleted) {
            $record->delete();

            Log::channel('security')->info('Secure file deletion', [
                'user_id' => $owner['user_id'],
                'school_id' => $owner['school_id'],
                'file_id' => $record->id,
                'file_path' => $filePath,
                'ip_address' => request()->ip(),
            ]);
        }

        return $deleted;
    }

    /**
     * Validate file integrity, only for files owned by the caller.
     *
     * @param  array{user_id: int, school_id: int}  $owner
     */
    public function validateFileIntegrity(string $filePath, string $expectedHash, array $owner): bool
    {
        $record = $this->findOwnedFile($filePath, $owner);

        try {
            $fullPath = Storage::disk('secure_uploads')->path($filePath);

            if (! file_exists($fullPath)) {
                return false;
            }

            return hash_equals($expectedHash, hash_file('sha256', $fullPath));
        } catch (\Exception $e) {
            Log::channel('security')->error('File integrity validation failed', [
                'file_id' => $record->id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * List files owned by the given user within their school.
     *
     * @param  array{user_id: int, school_id: int}  $owner
     */
    public function getUserFiles(array $owner, ?string $category = null): array
    {
        $query = SecureFile::query()
            ->where('user_id', $owner['user_id'])
            ->where('school_id', $owner['school_id']);

        if ($category) {
            $query->where('category', $category);
        }

        return $query->orderByDesc('created_at')->get()
            ->map(fn (SecureFile $file) => [
                'file_id' => $file->id,
                'path' => $file->storage_path,
                'original_name' => $file->original_name,
                'category' => $file->category,
                'size' => $file->size,
                'mime_type' => $file->mime_type,
                'uploaded_at' => $file->created_at?->toISOString(),
                'metadata' => $this->getStoredFileMetadata($file->storage_path),
                'download_url' => $this->generateSecureUrl($file->storage_path),
            ])
            ->all();
    }

    /**
     * Find an owned file record, or 404 if it does not exist / is not owned.
     *
     * @param  array{user_id: int, school_id: int}  $owner
     */
    private function findOwnedFile(string $filePath, array $owner): SecureFile
    {
        $record = SecureFile::query()
            ->where('storage_path', $filePath)
            ->where('user_id', $owner['user_id'])
            ->where('school_id', $owner['school_id'])
            ->first();

        // 404 (not 403) to avoid disclosing that another user's file exists.
        if (! $record) {
            throw new NotFoundHttpException('File not found.');
        }

        if (! Storage::disk('secure_uploads')->exists($filePath)) {
            throw new NotFoundHttpException('File not found.');
        }

        return $record;
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

        if (! file_exists($fullPath)) {
            throw new NotFoundHttpException('File not found.');
        }

        return [
            'size' => filesize($fullPath),
            'modified_at' => date('Y-m-d H:i:s', filemtime($fullPath)),
            'mime_type' => mime_content_type($fullPath),
            'hash' => hash_file('sha256', $fullPath),
        ];
    }

    /**
     * Generate secure download URL
     */
    public function generateSecureUrl(string $filePath, int $expiresInMinutes = 60): string
    {
        $expiresAt = now()->addMinutes($expiresInMinutes);

        try {
            return Storage::disk('secure_uploads')->temporaryUrl($filePath, $expiresAt);
        } catch (\RuntimeException $e) {
            // Local disks do not provide temporary URLs natively; fall back to
            // a signed route that still enforces ownership on access.
            return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'files.serve',
                $expiresAt,
                ['filePath' => $filePath]
            );
        }
    }
}
