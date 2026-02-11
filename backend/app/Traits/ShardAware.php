<?php

declare(strict_types=1);

namespace App\Traits;

use App\Services\TenantResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shard Aware Trait
 * 
 * Adds shard routing capabilities to Eloquent models
 * 
 * @package App\Traits
 */
trait ShardAware
{
    /**
     * Boot the trait
     * 
     * @return void
     */
    public static function bootShardAware(): void
    {
        // Automatically set connection based on school_id when creating
        static::creating(function ($model) {
            if (isset($model->school_id) && !$model->getConnectionName()) {
                $connection = TenantResolver::resolveConnection($model->school_id);
                $model->setConnection($connection);
            }
        });
    }

    /**
     * Set connection based on school_id
     * 
     * @param int $schoolId
     * @return self
     */
    public function onShard(int $schoolId): self
    {
        $connection = TenantResolver::resolveConnection($schoolId);
        $this->setConnection($connection);

        return $this;
    }

    /**
     * Scope to specific school (with automatic shard routing)
     * 
     * @param Builder $query
     * @param int $schoolId
     * @return Builder
     */
    public function scopeForSchool(Builder $query, int $schoolId): Builder
    {
        // Resolve shard
        $connection = TenantResolver::resolveConnection($schoolId);
        
        // Switch connection
        $query->getModel()->setConnection($connection);

        // Filter by school_id
        return $query->where('school_id', $schoolId);
    }

    /**
     * Scope to multiple schools (groups by shard)
     * 
     * Note: This returns a collection of queries, one per shard
     * 
     * @param Builder $query
     * @param array $schoolIds
     * @return array Array of [shard_name => Builder]
     */
    public function scopeForSchools(Builder $query, array $schoolIds): array
    {
        // Group schools by shard
        $grouped = TenantResolver::groupByShards($schoolIds);
        $queries = [];

        foreach ($grouped as $shard => $shardSchoolIds) {
            // Clone query for each shard
            $shardQuery = clone $query;
            $shardQuery->getModel()->setConnection($shard);
            $shardQuery->whereIn('school_id', $shardSchoolIds);
            
            $queries[$shard] = $shardQuery;
        }

        return $queries;
    }

    /**
     * Get shard info for this model instance
     * 
     * @return array|null
     */
    public function getShardInfo(): ?array
    {
        if (!isset($this->school_id)) {
            return null;
        }

        return TenantResolver::getShardInfo($this->school_id);
    }

    /**
     * Check if model is on correct shard
     * 
     * @return bool
     */
    public function isOnCorrectShard(): bool
    {
        if (!isset($this->school_id)) {
            return true; // No school_id, can't verify
        }

        $expectedConnection = TenantResolver::resolveConnection($this->school_id);
        $actualConnection = $this->getConnectionName();

        return $expectedConnection === $actualConnection;
    }

    /**
     * Move model to correct shard if needed
     * 
     * @return bool True if moved, false if already on correct shard
     */
    public function ensureCorrectShard(): bool
    {
        if ($this->isOnCorrectShard()) {
            return false;
        }

        $correctConnection = TenantResolver::resolveConnection($this->school_id);
        $this->setConnection($correctConnection);

        return true;
    }
}
