<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $guarded = ['id'];

    const SEVERITY_INFO = 'info';

    const SEVERITY_WARNING = 'warning';

    const SEVERITY_CRITICAL = 'critical';

    protected $casts = [
        'created_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
