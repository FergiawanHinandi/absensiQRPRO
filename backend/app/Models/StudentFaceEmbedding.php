<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentFaceEmbedding extends Model
{
    use HasFactory, BelongsToSchool;

    protected $fillable = [
        'student_id',
        'school_id',
        'embedding',
    ];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
