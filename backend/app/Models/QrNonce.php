<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrNonce extends Model
{
    use HasFactory;

    protected $fillable = [
        'nonce',
        'school_id',
        'student_id',
        'schedule_id',
    ];
}
