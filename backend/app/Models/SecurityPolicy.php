<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Security Policy Model
 *
 * Stores dynamic security policies for multi-tenant system.
 * Policies can be scoped globally or per-school.
 */
class SecurityPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope_type',
        'scope_id',
        'key',
        'value',
        'description',
        'updated_by',
    ];

    protected $casts = [
        'scope_id' => 'integer',
        'updated_by' => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function school()
    {
        return $this->belongsTo(School::class, 'scope_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeGlobal($query)
    {
        return $query->where('scope_type', 'global')->whereNull('scope_id');
    }

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('scope_type', 'school')->where('scope_id', $schoolId);
    }

    public function scopeByKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Decode the JSON value
     */
    public function getDecodedValue(): mixed
    {
        $decoded = json_decode($this->value, true);
        return $decoded ?? $this->value;
    }

    /**
     * Set value with JSON encoding
     */
    public function setEncodedValue(mixed $value): void
    {
        $this->value = json_encode($value);
    }
}
