<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    /**
     * SEC-02: Explicit fillable untuk mencegah mass assignment vulnerability.
     * Hanya field yang aman untuk diisi via request yang dimasukkan.
     *
     * @see database/migrations/2026_01_21_055802_create_audit_logs_table.php
     */
    protected $fillable = [
        'user_id',
        'school_id',
        'action',
        'description',
        'ip_address',
        'user_agent',
    ];

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
