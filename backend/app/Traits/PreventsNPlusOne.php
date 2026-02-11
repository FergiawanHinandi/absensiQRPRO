<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * N+1 Query Prevention Trait
 *
 * Provides helper methods to ensure proper eager loading and prevent N+1 queries.
 * Use this trait in controllers and services that deal with related models.
 *
 * USAGE:
 * - Use scopeWithRelations() for chainable eager loading
 * - Use ensureLoaded() to verify relations are loaded
 * - Use batchLoad() for post-query relation loading
 * - Use selectOnly() to limit columns and reduce memory
 */
trait PreventsNPlusOne
{
    /**
     * Default relations to eager load (override in using class)
     */
    protected array $defaultRelations = [];

    /**
     * Optimized select columns per model (override in using class)
     */
    protected array $optimizedSelects = [];

    /**
     * Apply eager loading with optimized column selection
     *
     * @param  Builder  $query  The query builder
     * @param  array    $relations  Relations to load (uses $defaultRelations if empty)
     * @return Builder
     */
    protected function withOptimized(Builder $query, array $relations = []): Builder
    {
        $relations = $relations ?: $this->defaultRelations;

        // Build optimized with array
        $withArray = [];
        foreach ($relations as $relation => $columns) {
            if (is_numeric($relation)) {
                // Simple relation without column selection
                $withArray[] = $columns;
            } else {
                // Relation with specific columns
                $withArray[$relation] = function ($q) use ($columns) {
                    if (is_array($columns)) {
                        $q->select($columns);
                    }
                };
            }
        }

        return $query->with($withArray);
    }

    /**
     * Ensure relations are loaded on a model/collection
     * Throws exception in development if relation not loaded
     *
     * @param  Model|Collection  $modelOrCollection
     * @param  array  $relations  Relations that should be loaded
     * @return void
     * @throws \RuntimeException In local environment if relation not loaded
     */
    protected function ensureLoaded(Model|Collection $modelOrCollection, array $relations): void
    {
        if (!app()->environment('local', 'development', 'testing')) {
            return; // Skip check in production for performance
        }

        $modelsToCheck = $modelOrCollection instanceof Collection
            ? $modelOrCollection
            : collect([$modelOrCollection]);

        foreach ($modelsToCheck as $model) {
            foreach ($relations as $relation) {
                if (!$model->relationLoaded($relation)) {
                    $modelClass = get_class($model);
                    throw new \RuntimeException(
                        "N+1 Query Detected: Relation '{$relation}' is not loaded on {$modelClass}. " .
                        "Use eager loading with ->with('{$relation}') to prevent N+1 queries."
                    );
                }
            }
        }
    }

    /**
     * Batch load relations after initial query
     * Useful when you receive a collection and need additional relations
     *
     * @param  Collection  $collection
     * @param  array  $relations
     * @return Collection
     */
    protected function batchLoad(Collection $collection, array $relations): Collection
    {
        if ($collection->isEmpty()) {
            return $collection;
        }

        // Get the model class from first item
        $model = $collection->first();
        if (!$model instanceof Model) {
            return $collection;
        }

        // Load missing relations
        $collection->load($relations);

        return $collection;
    }

    /**
     * Create optimized query with only needed columns
     *
     * @param  string  $model  Model class name
     * @param  array   $columns  Columns to select (uses $optimizedSelects if empty)
     * @return Builder
     */
    protected function selectOptimized(string $model, array $columns = []): Builder
    {
        $columns = $columns ?: ($this->optimizedSelects[$model] ?? ['*']);

        return $model::query()->select($columns);
    }

    /**
     * Paginate with eager loading and cursor for better performance
     *
     * @param  Builder  $query
     * @param  array    $relations
     * @param  int      $perPage
     * @return \Illuminate\Contracts\Pagination\CursorPaginator
     */
    protected function cursorPaginateWith(Builder $query, array $relations = [], int $perPage = 15)
    {
        return $query->with($relations)->cursorPaginate($perPage);
    }

    /**
     * Standard paginate with eager loading
     *
     * @param  Builder  $query
     * @param  array    $relations
     * @param  int      $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    protected function paginateWith(Builder $query, array $relations = [], int $perPage = 15)
    {
        return $query->with($relations)->paginate($perPage);
    }

    /**
     * Get counts for relations without loading full records
     * Much more efficient than loading relations just to count
     *
     * @param  Builder  $query
     * @param  array    $relations
     * @return Builder
     */
    protected function withCountOnly(Builder $query, array $relations): Builder
    {
        return $query->withCount($relations);
    }

    /**
     * Apply common attendance eager loading
     */
    protected function withAttendanceRelations(Builder $query): Builder
    {
        return $query->with([
            'student:id,name,username',
            'schedule:id,class_id,subject_id,teacher_id,start_time,end_time',
            'schedule.class:id,name,grade_level',
            'schedule.subject:id,name,code',
        ]);
    }

    /**
     * Apply common user eager loading
     */
    protected function withUserRelations(Builder $query): Builder
    {
        return $query->with([
            'school:id,name',
            'profile:id,user_id,phone,address',
            'roles:id,name',
        ]);
    }

    /**
     * Apply common schedule eager loading
     */
    protected function withScheduleRelations(Builder $query): Builder
    {
        return $query->with([
            'class:id,name,grade_level',
            'subject:id,name,code',
            'teacher:id,name',
        ]);
    }

    /**
     * Build subquery for aggregations without loading related records
     * Useful for counts, sums, etc. that don't need full records
     *
     * @param  string  $relation
     * @param  string  $aggregate  e.g., 'COUNT(*)', 'SUM(amount)'
     * @param  string  $alias
     * @return \Illuminate\Database\Query\Expression
     */
    protected function subqueryAggregate(string $relation, string $aggregate, string $alias)
    {
        return \DB::raw("({$aggregate}) as {$alias}");
    }
}
