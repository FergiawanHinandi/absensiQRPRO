<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToSchool;

class BackupJob extends Model
{
    use HasFactory;
    use BelongsToSchool; // Multi-tenancy: otomatis filter berdasarkan school_id

    protected $fillable = [
        'job_id',
        'job_type',
        'status',
        'started_at',
        'completed_at',
        'duration_seconds',
        'backup_size_bytes',
        'progress_percentage',
        'status_message',
        'error_message',
        'metadata',
        'metrics',
        'school_id',
        'user_id'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
        'metrics' => 'array',
        'backup_size_bytes' => 'integer',
        'duration_seconds' => 'integer',
        'progress_percentage' => 'integer'
    ];

    /**
     * Get the school that owns the backup job.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the user that owns the backup job.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get running jobs
     */
    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    /**
     * Scope to get successful jobs
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope to get failed jobs
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to get backup jobs
     */
    public function scopeBackups($query)
    {
        return $query->where('job_type', 'backup');
    }

    /**
     * Scope to get restore jobs
     */
    public function scopeRestores($query)
    {
        return $query->where('job_type', 'restore');
    }

    /**
     * Scope to get rollback jobs
     */
    public function scopeRollbacks($query)
    {
        return $query->where('job_type', 'rollback');
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_seconds) {
            return 'N/A';
        }

        $hours = floor($this->duration_seconds / 3600);
        $minutes = floor(($this->duration_seconds % 3600) / 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        } else {
            return sprintf('%ds', $seconds);
        }
    }

    /**
     * Get formatted backup size
     */
    public function getFormattedSizeAttribute(): string
    {
        if (!$this->backup_size_bytes) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($this->backup_size_bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get status color for UI
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'running' => 'blue',
            'success' => 'green',
            'failed' => 'red',
            'cancelled' => 'yellow',
            default => 'gray'
        };
    }

    /**
     * Get job type icon
     */
    public function getJobTypeIconAttribute(): string
    {
        return match($this->job_type) {
            'backup' => '💾',
            'restore' => '🔄',
            'rollback' => '⏪',
            default => '📋'
        };
    }

    /**
     * Check if job is currently running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if job completed successfully
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Check if job failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get job completion percentage
     */
    public function getCompletionPercentage(): int
    {
        if ($this->isSuccessful()) {
            return 100;
        }

        if ($this->isFailed()) {
            return 0;
        }

        return $this->progress_percentage ?? 0;
    }
}
