<?php

namespace App\Models\DR;

use Illuminate\Database\Eloquent\Model;

/**
 * BackupOperation Model
 *
 * Tracks each backup/restore operation with progress.
 * Spec: disaster-recovery-audit-improvements / tasks.md Task 12.1
 */
class BackupOperation extends Model
{
    protected $table = 'backup_operations';

    protected $fillable = [
        'school_id',
        'type',
        'status',
        'scenario',
        'progress_percent',
        'progress_message',
        'bytes_processed',
        'bytes_total',
        'backup_path',
        'storage_disk',
        'error_message',
        'initiated_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
        'bytes_processed'  => 'integer',
        'bytes_total'      => 'integer',
        'progress_percent' => 'integer',
    ];

    // Scopes
    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // Update progress
    public function updateProgress(int $percent, string $message = ''): void
    {
        $this->update([
            'progress_percent' => min(100, max(0, $percent)),
            'progress_message' => $message,
        ]);
    }

    public function markCompleted(string $backupPath = ''): void
    {
        $this->update([
            'status'           => 'completed',
            'progress_percent' => 100,
            'backup_path'      => $backupPath,
            'completed_at'     => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status'        => 'failed',
            'error_message' => $error,
            'completed_at'  => now(),
        ]);
    }
}
