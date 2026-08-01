<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Device Model
 *
 * Mewakili perangkat keras Solution X606-S Fingerprint & RFID Reader
 * yang terhubung ke sistem via ADMS Push Server Protocol.
 * Juga dapat digunakan untuk tipe perangkat lain seperti QR scanner.
 */
class Device extends Model
{
    use BelongsToSchool, HasFactory, SoftDeletes;

    protected $fillable = [
        'school_id',
        'sn',
        'name',
        'type',
        'model',
        'ip_address',
        'port',
        'location',
        'last_ping_at',
        'is_online',
        'is_active',
        'settings',
        'notes',
    ];

    protected $casts = [
        'last_ping_at' => 'datetime',
        'is_online' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Guru yang terdaftar menggunakan perangkat ini.
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'device_teacher', 'device_id', 'teacher_id')
            ->withPivot('assigned_at', 'unassigned_at', 'is_active')
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    /**
     * Scope untuk perangkat yang online.
     */
    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    /**
     * Scope untuk perangkat fingerprint/RFID.
     */
    public function scopeFingerprintRfid($query)
    {
        return $query->where('type', 'fingerprint_rfid');
    }

    /**
     * Scope untuk perangkat yang aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Update status online berdasarkan last_ping.
     * Dianggap offline jika tidak ada ping dalam 5 menit.
     */
    public function updateOnlineStatus(): bool
    {
        // Jika belum pernah ping, anggap offline
        if (! $this->last_ping_at) {
            if ($this->is_online) {
                $this->is_online = false;
                return $this->save();
            }
            return false;
        }

        // Jika ping terakhir lebih dari 5 menit, anggap offline
        if ($this->last_ping_at->diffInMinutes(now()) > 5) {
            $this->is_online = false;
            return $this->save();
        }

        return true;
    }
}
