<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Export Progress Model
 * 
 * Tracks the progress of large export operations for user feedback.
 * 
 * @property int $id
 * @property int $user_id
 * @property int $school_id
 * @property string $export_type
 * @property string $status
 * @property int $total_records
 * @property int $processed_records
 * @property int $progress_percentage
 * @property string|null $filename
 * @property string|null $file_path
 * @property string|null $error_message
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ExportProgress extends Model
{
    use HasFactory;

    protected $table = 'export_progress';

    protected $fillable = [
        'user_id',
        'school_id',
        'export_type',
        'status',
        'total_records',
        'processed_records',
        'progress_percentage',
        'filename',
        'file_path',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total_records' => 'integer',
        'processed_records' => 'integer',
        'progress_percentage' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Get the user that owns the export.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the school that owns the export.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Update progress with processed count.
     */
    public function updateProgress(int $processedRecords): void
    {
        $this->processed_records = $processedRecords;
        
        if ($this->total_records > 0) {
            $this->progress_percentage = min(100, (int) round(($processedRecords / $this->total_records) * 100));
        }
        
        $this->save();
    }

    /**
     * Mark export as processing.
     */
    public function markAsProcessing(int $totalRecords): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'total_records' => $totalRecords,
            'started_at' => now(),
        ]);
    }

    /**
     * Mark export as completed.
     */
    public function markAsCompleted(string $filename, string $filePath): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'filename' => $filename,
            'file_path' => $filePath,
            'progress_percentage' => 100,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark export as failed.
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
     * Check if export is still processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if export is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if export has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
