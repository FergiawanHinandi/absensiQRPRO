<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class StudentPoint extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'student_id',
        'school_id',
        'points',
        'source',
        'reference_id',
        'date',
        'description',
    ];

    protected $casts = [
        'date' => 'date',
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
