<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Badge — Gamification badges awarded to students
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 */
class Badge extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Students who have earned this badge.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'student_badges', 'badge_id', 'student_id')
            ->withPivot('awarded_at');
    }

    // ─────────────────────────────────────────────────────────────────────
    // QUERY SCOPES
    // ─────────────────────────────────────────────────────────────────────

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }
}
