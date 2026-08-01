<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    /**
     * SEC-02: Explicit fillable untuk mencegah mass assignment vulnerability.
     * Hanya field yang aman untuk diisi via request yang dimasukkan.
     *
     * @see database/migrations/2026_02_07_220000_create_activity_logs_table.php
     */
    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'school_id',
        'ip_address',
        'user_agent',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the owning model.
     */
    public function model()
    {
        return $this->morphTo();
    }
}
