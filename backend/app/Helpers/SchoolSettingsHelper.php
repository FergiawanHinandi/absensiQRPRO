<?php

namespace App\Helpers;

class SchoolSettingsHelper
{
    /**
     * Get the full settings schema from config
     */
    public static function getSchema(): array
    {
        return config('school_settings', []);
    }

    /**
     * Get default values defined in schema
     */
    public static function getDefaults(): array
    {
        $schema = self::getSchema();
        $defaults = [];
        foreach ($schema as $key => $meta) {
            $defaults[$key] = $meta['default'] ?? null;
        }

        return $defaults;
    }

    /**
     * Generate Laravel validation rules dynamically
     */
    public static function getValidationRules(): array
    {
        $schema = self::getSchema();
        $rules = [];
        foreach ($schema as $key => $meta) {
            $type = $meta['type'] ?? 'string';
            $r = ['nullable'];

            if ($type === 'int') {
                $r[] = 'integer';
                if (isset($meta['min'])) {
                    $r[] = 'min:'.$meta['min'];
                }
                if (isset($meta['max'])) {
                    $r[] = 'max:'.$meta['max'];
                }
            } elseif ($type === 'bool') {
                $r[] = 'boolean';
            }

            $rules[$key] = $r;
        }

        return $rules;
    }

    /**
     * Cast input values to correct types based on schema
     */
    public static function castSettings(array $input): array
    {
        $schema = self::getSchema();
        $casted = [];

        foreach ($input as $key => $value) {
            if (! isset($schema[$key])) {
                continue;
            } // Ignore unknown keys (strict mode)

            $type = $schema[$key]['type'] ?? 'string';

            if ($value === null) {
                $casted[$key] = null;

                continue;
            }

            if ($type === 'int') {
                $casted[$key] = (int) $value;
            } elseif ($type === 'bool') {
                $casted[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } else {
                $casted[$key] = $value;
            }
        }

        return $casted;
    }

    /**
     * Merge current settings with defaults
     */
    public static function mergeWithDefaults(?array $currentSettings): array
    {
        return array_merge(self::getDefaults(), $currentSettings ?? []);
    }

    /**
     * Get settings with Cache (1 hour)
     */
    public static function getSettingsWithCache(int $schoolId): array
    {
        $cacheKey = "school_settings_{$schoolId}";
        $ttl = config('cache.school_settings_ttl', 3600);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, $ttl, function () use ($schoolId) {
            $school = \App\Models\School::find($schoolId);
            if (! $school) {
                return self::getDefaults();
            }

            return self::mergeWithDefaults($school->settings);
        });
    }

    /**
     * Clear settings cache
     */
    public static function clearCache(int $schoolId): void
    {
        \Illuminate\Support\Facades\Cache::forget("school_settings_{$schoolId}");
    }
}
