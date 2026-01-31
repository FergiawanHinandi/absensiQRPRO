<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Report Export Model
 * 
 * Tracks async report generation jobs with status, progress, and file info.
 */
class ReportExport extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'school_id',
        'type',
        'format',
        'parameters',
        'status',
        'error_message',
        'progress',
        'file_path',
        'file_name',
        'file_size',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'parameters' => 'array',
        'progress' => 'integer',
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Format constants
     */
    const FORMAT_EXCEL = 'excel';
    const FORMAT_PDF = 'pdf';

    /**
     * Type constants
     */
    const TYPE_ATTENDANCE = 'attendance';
    const TYPE_SUMMARY = 'summary';

    /**
     * Get the user that requested this export
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the school this export belongs to
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Scope to get pending exports
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get completed exports
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get expired exports
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    /**
     * Scope to get exports for a specific user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Mark export as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    /**
     * Update progress
     */
    public function updateProgress(int $progress): void
    {
        $this->update(['progress' => min(100, max(0, $progress))]);
    }

    /**
     * Mark export as completed
     */
    public function markAsCompleted(string $filePath, string $fileName, int $fileSize): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'progress' => 100,
            'completed_at' => now(),
            'expires_at' => now()->addHours(24), // Auto-delete after 24 hours
        ]);
    }

    /**
     * Mark export as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    /**
     * Check if export is ready for download
     */
    public function isReady(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->file_path;
    }

    /**
     * Check if export is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get download URL (temporary signed URL for S3, or direct path for local)
     */
    public function getDownloadUrl(): ?string
    {
        if (!$this->isReady() || $this->isExpired()) {
            return null;
        }

        $disk = config('filesystems.default');

        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl(
                $this->file_path,
                now()->addMinutes(30)
            );
        }

        // For local storage, return the path (controller will handle streaming)
        return $this->file_path;
    }

    /**
     * Delete the associated file
     */
    public function deleteFile(): bool
    {
        if ($this->file_path && Storage::exists($this->file_path)) {
            return Storage::delete($this->file_path);
        }
        return true;
    }

    /**
     * Clean up expired exports (to be called by scheduler)
     */
    public static function cleanupExpired(): int
    {
        $expired = self::expired()->get();
        $count = 0;

        foreach ($expired as $export) {
            $export->deleteFile();
            $export->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Get human-readable status
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Menunggu',
            self::STATUS_PROCESSING => 'Sedang Diproses',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_FAILED => 'Gagal',
            default => 'Unknown',
        };
    }

    /**
     * Get human-readable file size
     */
    public function getFileSizeHumanAttribute(): ?string
    {
        if (!$this->file_size) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }
}
