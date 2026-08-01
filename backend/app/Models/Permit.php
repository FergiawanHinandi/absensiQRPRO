<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Permit Model
 *
 * Mewakili pengajuan izin/sakit siswa dengan workflow approval.
 * - Siswa/orang tua/guru mengajukan permit
 * - Guru wali kelas atau kepala sekolah menyetujui/menolak
 * - Status: Pending -> Approved/Rejected
 */
class Permit extends Model
{
    use BelongsToSchool, HasFactory, SoftDeletes;

    protected $fillable = [
        'school_id',
        'student_id',
        'date',
        'end_date',
        'type',
        'notes',
        'proof_file',
        'status',
        'submitted_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
    ];

    /**
     * Siswa yang mengajukan permit.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * User yang mengajukan permit (guru/orang tua).
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * User yang menyetujui permit (kepala sekolah/guru).
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ─────────────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Scope untuk permit yang masih pending.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'Pending');
    }

    /**
     * Scope untuk permit yang sudah disetujui.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'Approved');
    }

    /**
     * Scope untuk permit yang ditolak.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'Rejected');
    }

    /**
     * Scope untuk permit hari ini.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('date', today());
    }

    /**
     * Scope untuk permit berdasarkan rentang tanggal.
     */
    public function scopeBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Approve permit ini.
     */
    public function approve(int $userId): bool
    {
        $this->status = 'Approved';
        $this->approved_by = $userId;
        $this->approved_at = now();
        return $this->save();
    }

    /**
     * Reject permit ini.
     */
    public function reject(int $userId, string $reason = null): bool
    {
        $this->status = 'Rejected';
        $this->approved_by = $userId;
        $this->approved_at = now();
        $this->rejection_reason = $reason;
        return $this->save();
    }

    /**
     * Cek apakah permit masih bisa diedit/dibatalkan.
     */
    public function isEditable(): bool
    {
        return $this->status === 'Pending';
    }

    /**
     * Dapatkan label status dalam Bahasa Indonesia.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'Pending' => 'Menunggu',
            'Approved' => 'Disetujui',
            'Rejected' => 'Ditolak',
            default => $this->status,
        };
    }

    /**
     * Dapatkan warna badge untuk status.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'Pending' => 'warning',
            'Approved' => 'success',
            'Rejected' => 'danger',
            default => 'secondary',
        };
    }
}
