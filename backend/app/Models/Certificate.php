<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'certificate_code',
        'type',
        'file_path',
        'semester',
        'academic_year',
        'attendance_rate',
        'metadata',
        'issued_at',
        'is_redeemed',
        'redeemed_at',
        'redeemed_by',
        'redemption_notes',
    ];

    protected $casts = [
        'metadata' => 'array',
        'attendance_rate' => 'decimal:2',
        'issued_at' => 'datetime',
        'academic_year' => 'integer',
        'is_redeemed' => 'boolean',
        'redeemed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }
}
