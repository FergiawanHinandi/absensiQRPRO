<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentCard extends Model
{
    use HasFactory;

    protected $table = 'student_cards';

    protected $fillable = [
        'student_id',
        'qr_hash',
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
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function distributedBy()
    {
        return $this->belongsTo(User::class, 'distributed_by');
    }
}
