<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tenant Resolver
 * 
 * Determines which database shard to use for a given school
 * 
 * @package App\Services
 */
class TenantResolver
{
    /**
     * Shard mapping cache key
     */
    private const CACHE_KEY = 'shard_mapping';

    /**
     * Cache TTL (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Default connection (fallback)
     */
    private const DEFAULT_CONNECTION = 'mysql';

    /**
     * Resolve database connection for a school
     * 
     * @param int $schoolId
     * @return string Connection name
     */
    public static function resolveConnection(int $schoolId): string
    {
        // Get shard mapping
        $mapping = self::getShardMapping();

        // Find appropriate shard
        foreach ($mapping as $shard) {
            if ($schoolId >= $shard->school_id_min 
                && $schoolId <= $shard->school_id_max
                && $shard->status === 'active') {
                
                Log::debug('Resolved shard for school', [
                    'school_id' => $schoolId,
                    'shard' => $shard->shard_name,
                    'range' => "{$shard->school_id_min}-{$shard->school_id_max}",
                ]);

                return $shard->shard_name;
            }
        }

        // Fallback to default (should not happen in production)
        Log::warning('No shard found for school, using default', [
            'school_id' => $schoolId,
            'mapping_count' => count($mapping),
        ]);

        return self::DEFAULT_CONNECTION;
    }

    /**
     * Get shard mapping from cache or database
     * 
     * @return array
     */
    private static function getShardMapping(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                return DB::connection('mysql_global')
                    ->table('shard_mapping')
                    ->where('status', 'active')
                    ->orderBy('school_id_min')
                    ->get()
                    ->toArray();
            } catch (\Exception $e) {
                Log::error('Failed to get shard mapping', [
                    'error' => $e->getMessage(),
                ]);

                // Return empty array on error (will use default connection)
                return [];
            }
        });
    }

    /**
     * Get shard info for a specific school
     * 
     * @param int $schoolId
     * @return array|null
     */
    public static function getShardInfo(int $schoolId): ?array
    {
        $mapping = self::getShardMapping();

        foreach ($mapping as $shard) {
            if ($schoolId >= $shard->school_id_min 
                && $schoolId <= $shard->school_id_max) {
                return [
                    'shard_id' => $shard->shard_id,
                    'shard_name' => $shard->shard_name,
                    'school_id_min' => $shard->school_id_min,
                    'school_id_max' => $shard->school_id_max,
                    'status' => $shard->status,
                ];
            }
        }

        return null;
    }

    /**
     * Assign new school to appropriate shard
     * 
     * Finds the latest active shard with capacity
     * 
     * @param int $schoolId
     * @return string Shard name
     */
    public static function assignShard(int $schoolId): string
    {
        $mapping = self::getShardMapping();

        // Find shard that can accommodate this school_id
        foreach (array_reverse($mapping) as $shard) {
            if ($shard->status === 'active' 
                && $schoolId >= $shard->school_id_min
                && $schoolId <= $shard->school_id_max) {
                
                Log::info('Assigned school to shard', [
                    'school_id' => $schoolId,
                    'shard' => $shard->shard_name,
                    'range' => "{$shard->school_id_min}-{$shard->school_id_max}",
                ]);

                return $shard->shard_name;
            }
        }

        // If no shard found, need to create new one
        Log::critical('No shard available for school, need to create new shard', [
            'school_id' => $schoolId,
            'available_shards' => count($mapping),
        ]);

        // Return default as fallback
        return self::DEFAULT_CONNECTION;
    }

    /**
     * Clear shard mapping cache
     * 
     * Call this after updating shard_mapping table
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        
        Log::info('Shard mapping cache cleared');
    }

    /**
     * Get all active shards
     * 
     * @return array
     */
    public static function getAllShards(): array
    {
        return self::getShardMapping();
    }

    /**
     * Get shard statistics
     * 
     * Returns row counts and metrics for each shard
     * 
     * @return array
     */
    public static function getShardStatistics(): array
    {
        $mapping = self::getShardMapping();
        $stats = [];

        foreach ($mapping as $shard) {
            $connection = $shard->shard_name;

            try {
                // Get row counts
                $attendanceCount = DB::connection($connection)
                    ->table('attendances')
                    ->count();

                $schoolCount = DB::connection($connection)
                    ->table('schools')
                    ->count();

                $studentCount = DB::connection($connection)
                    ->table('students')
                    ->count();

                // Get database size
                $dbSize = DB::connection($connection)
                    ->select("
                        SELECT 
                            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                        FROM information_schema.TABLES
                        WHERE table_schema = DATABASE()
                    ")[0]->size_mb ?? 0;

                $stats[] = [
                    'shard_id' => $shard->shard_id,
                    'shard_name' => $shard->shard_name,
                    'school_id_range' => "{$shard->school_id_min}-{$shard->school_id_max}",
                    'school_count' => $schoolCount,
                    'student_count' => $studentCount,
                    'attendance_count' => $attendanceCount,
                    'size_mb' => $dbSize,
                    'status' => $shard->status,
                ];
            } catch (\Exception $e) {
                $stats[] = [
                    'shard_id' => $shard->shard_id,
                    'shard_name' => $shard->shard_name,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $stats;
    }

    /**
     * Validate shard configuration
     * 
     * Checks for overlaps, gaps, and configuration issues
     * 
     * @return array Validation results
     */
    public static function validateShardConfiguration(): array
    {
        $mapping = self::getShardMapping();
        $issues = [];

        // Check for overlaps
        for ($i = 0; $i < count($mapping) - 1; $i++) {
            $current = $mapping[$i];
            $next = $mapping[$i + 1];

            if ($current->school_id_max >= $next->school_id_min) {
                $issues[] = [
                    'type' => 'overlap',
                    'message' => "Shard {$current->shard_name} overlaps with {$next->shard_name}",
                    'current_range' => "{$current->school_id_min}-{$current->school_id_max}",
                    'next_range' => "{$next->school_id_min}-{$next->school_id_max}",
                ];
            }

            // Check for gaps
            if ($current->school_id_max + 1 < $next->school_id_min) {
                $issues[] = [
                    'type' => 'gap',
                    'message' => "Gap between {$current->shard_name} and {$next->shard_name}",
                    'gap_range' => ($current->school_id_max + 1) . '-' . ($next->school_id_min - 1),
                ];
            }
        }

        // Check for duplicate shard names
        $shardNames = array_map(fn($s) => $s->shard_name, $mapping);
        $duplicates = array_diff_assoc($shardNames, array_unique($shardNames));
        
        if (!empty($duplicates)) {
            $issues[] = [
                'type' => 'duplicate_name',
                'message' => 'Duplicate shard names found',
                'duplicates' => array_values(array_unique($duplicates)),
            ];
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'shard_count' => count($mapping),
        ];
    }

    /**
     * Get connection for multiple schools
     * 
     * Returns array of [school_id => connection_name]
     * 
     * @param array $schoolIds
     * @return array
     */
    public static function resolveMultiple(array $schoolIds): array
    {
        $connections = [];

        foreach ($schoolIds as $schoolId) {
            $connections[$schoolId] = self::resolveConnection($schoolId);
        }

        return $connections;
    }

    /**
     * Group schools by shard
     * 
     * Returns array of [shard_name => [school_ids]]
     * 
     * @param array $schoolIds
     * @return array
     */
    public static function groupByShards(array $schoolIds): array
    {
        $grouped = [];

        foreach ($schoolIds as $schoolId) {
            $shard = self::resolveConnection($schoolId);
            
            if (!isset($grouped[$shard])) {
                $grouped[$shard] = [];
            }
            
            $grouped[$shard][] = $schoolId;
        }

        return $grouped;
    }
}
