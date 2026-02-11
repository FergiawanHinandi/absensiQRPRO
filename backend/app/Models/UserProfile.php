<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'user_id',
        'full_name',
        'nisn',
        'nip',
        'gender',
        'birth_date',
        'phone',
        'address',
        'school_id',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
