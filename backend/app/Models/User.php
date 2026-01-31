<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use BelongsToSchool, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $guard_name = 'sanctum';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'school_id',
        'username',
        'name',
        'email',
        'password',
        'role_type',
        'is_active',
        'device_token',
        'device_id',
        'last_login_at',
        'current_streak',
        'longest_streak',
        'last_streak_date',
        'total_points',
        'photo_path',
        'photo_review_status',
        'photo_reviewed_at',
        'photo_reviewed_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'device_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_streak_date' => 'date',
            'photo_reviewed_at' => 'datetime',
        ];
    }

    public function getLevelAttribute()
    {
        $points = $this->total_points ?? 0;

        if ($points > 1000) {
            return 'Platinum';
        } elseif ($points >= 501) {
            return 'Gold';
        } elseif ($points >= 201) {
            return 'Silver';
        } else {
            return 'Bronze';
        }
    }

    /**
     * Check if user is eligible for rewards
     * Criteria: Streak >= 30 AND Attendance >= 95% in current active semester
     */
    public function getRewardEligibleAttribute(): bool
    {
        // 1. Check Streak (Fastest check first)
        if ($this->current_streak < 30) {
            return false;
        }

        // 2. Check Attendance Percentage in Active Semester
        // Note: This involves DB queries. Be careful with N+1.
        $schoolId = $this->school_id;
        
        // Find active academic year for this school
        $academicYear = \App\Models\AcademicYear::where('school_id', $schoolId)
            ->where('is_active', true)
            ->first();

        if (!$academicYear) {
            return false;
        }

        $startDate = $academicYear->start_date;
        $endDate = now()->min($academicYear->end_date)->toDateString();

        // Calculate Attendance Rate
        // We count distinct days to handle multiple subject check-ins per day
        $stats = $this->attendances()
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->selectRaw("
                count(distinct attendance_date) as total_days,
                count(distinct case when status in ('present', 'late') then attendance_date end) as present_days
            ")
            ->first();

        if (!$stats || $stats->total_days == 0) {
            return false;
        }

        $percentage = ($stats->present_days / $stats->total_days) * 100;

        return $percentage >= 95;
    }

    // Relationships
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'student_id');
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function classStudents()
    {
        return $this->hasMany(ClassStudent::class, 'student_id');
    }

    public function teacherDevices()
    {
        return $this->hasMany(TeacherDevice::class, 'teacher_id');
    }

    public function teacherAttendances()
    {
        return $this->hasMany(TeacherAttendance::class, 'teacher_id');
    }

    // Relationships
    public function parents()
    {
        return $this->belongsToMany(User::class, 'student_parents', 'student_id', 'parent_id')
            ->withPivot('relationship', 'is_primary')
            ->withTimestamps();
    }

    public function children()
    {
        return $this->belongsToMany(User::class, 'student_parents', 'parent_id', 'student_id')
            ->withPivot('relationship', 'is_primary')
            ->withTimestamps();
    }

    public function badges()
    {
        return $this->belongsToMany(Badge::class, 'student_badges', 'student_id', 'badge_id')
            ->withPivot('awarded_at');
    }

    public function photoReviewer()
    {
        return $this->belongsTo(User::class, 'photo_reviewed_by');
    }

    // Scopes
    public function scopeStudents($query)
    {
        return $query->where('role_type', 'student');
    }

    public function scopeTeachers($query)
    {
        return $query->whereIn('role_type', ['teacher', 'homeroom_teacher']);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
