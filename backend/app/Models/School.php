<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class School extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'npsn',
        'school_level',
        'address',
        'city',
        'province',
        'postal_code',
        'start_time',
        'end_time',
        'latitude',
        'longitude',
        'radius_meters',
        'settings',
        'is_active',
        'package_type',
        'max_students',
        'max_teachers',
        'max_classes',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_meters' => 'integer',
        'settings' => 'array',
        'is_active' => 'boolean',
        'max_students' => 'integer',
        'start_time' => 'datetime:H:i:s',
        'end_time' => 'datetime:H:i:s',
    ];

    protected static function booted(): void
    {
        static::creating(function (School $school) {
            if (! $school->npsn) {
                $school->npsn = (string) random_int(10000000, 99999999);
            }
            if (! $school->school_level) {
                $school->school_level = 'SMP';
            }
        });
    }

    // Relationships
    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function academicYears()
    {
        return $this->hasMany(AcademicYear::class);
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function classes()
    {
        return $this->hasMany(ClassModel::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['settings'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "School settings have been {$eventName}")
            ->useLogName('school_settings');
    }
}
