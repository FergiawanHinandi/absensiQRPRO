<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrCode extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'schedule_id',
        'qr_type',
        'token',
        'generated_by',
        'valid_from',
        'valid_until',
        'max_scans',
        'scan_count',
        'is_active',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'max_scans' => 'integer',
        'scan_count' => 'integer',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('valid_until', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('valid_until', '<=', now());
    }
}
