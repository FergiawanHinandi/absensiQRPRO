<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Announcement — Platform-wide or school-targeted announcements
 *
 * @property int $id
 * @property string $title
 * @property string $content
 * @property string $type (info|warning|critical|success)
 * @property string $target_role (all|admin|school_admin|teacher|student)
 * @property string $target_type (global|school|user)
 * @property array|null $target_ids
 * @property int|null $created_by
 * @property bool $is_active
 * @property \Carbon\Carbon|null $expires_at
 */
class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'type',
        'target_role',
        'target_type',
        'target_ids',
        'created_by',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'target_ids' => 'array',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─────────────────────────────────────────────────────────────────────
    // QUERY SCOPES
    // ─────────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForRole($query, string $role)
    {
        return $query->where(function ($q) use ($role) {
            $q->where('target_role', 'all')
                ->orWhere('target_role', $role);
        });
    }

    public function scopeGlobal($query)
    {
        return $query->where('target_type', 'global');
    }

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where(function ($q) use ($schoolId) {
            $q->where('target_type', 'global')
                ->orWhere(function ($q2) use ($schoolId) {
                    $q2->where('target_type', 'school')
                        ->whereJsonContains('target_ids', $schoolId);
                });
        });
    }
}
