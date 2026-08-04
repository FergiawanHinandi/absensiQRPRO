<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEC-4: Ownership record for files uploaded via the secure upload API.
 */
class SecureFile extends Model
{
    protected $fillable = [
        'user_id',
        'school_id',
        'storage_path',
        'original_name',
        'mime_type',
        'size',
        'category',
        'description',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
