<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassStudent extends Model
{
    protected $fillable = [
        'student_id',
        'class_id',
        'status',
        'enrollment_date',
    ];
}
