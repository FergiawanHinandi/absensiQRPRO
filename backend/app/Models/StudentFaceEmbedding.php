<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentFaceEmbedding extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'embedding',
    ];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
