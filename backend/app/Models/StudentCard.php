<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentCard extends Model
{
    use HasFactory, BelongsToSchool;

    protected $table = 'student_cards';

    protected $fillable = [
        'student_id',
        'school_id',
        'qr_hash',
        'qr_token_encrypted',
        'issued_by',
        'issued_at',
        'distributed_at',
        'distributed_by',
        'revoked_at',
        'revoked_reason',
        'is_active',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'distributed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'is_active' => 'boolean',
        'qr_token_encrypted' => 'encrypted',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}

    public function distributedBy()
    {
        return $this->belongsTo(User::class, 'distributed_by');
    }
}
