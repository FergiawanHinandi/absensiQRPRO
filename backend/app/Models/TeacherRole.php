<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_id',
        'is_homeroom_teacher',
        'homeroom_class_id',
        'academic_year_id',
    ];

    protected $casts = [
        'is_homeroom_teacher' => 'boolean',
    ];

    /**
     * Relasi ke User (Guru)
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Relasi ke Kelas (untuk wali kelas)
     */
    public function homeroomClass(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'homeroom_class_id');
    }

    /**
     * Relasi ke Tahun Ajaran
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
