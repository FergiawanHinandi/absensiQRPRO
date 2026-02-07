<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrNonce extends Model
{
    use HasFactory, BelongsToSchool;

    protected $fillable = [
        'nonce',
        'school_id',
        'student_id',
        'qr_code_id',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function qrCode()
    {
        return $this->belongsTo(QrCode::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Check if nonce is valid and not used
     */
    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at > now();
    }

    /**
     * Mark nonce as used
     */
    public function markAsUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
}
