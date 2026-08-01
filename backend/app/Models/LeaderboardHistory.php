<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToSchool;

class LeaderboardHistory extends Model
{
    use HasFactory;
    use BelongsToSchool; // Multi-tenancy: otomatis filter berdasarkan school_id

    protected $table = 'leaderboard_history';

    protected $fillable = [
        'school_id',
        'period_key',
        'category',
        'rank',
        'entity_id',
        'entity_type',
        'entity_name',
        'score',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'score' => 'decimal:2',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
