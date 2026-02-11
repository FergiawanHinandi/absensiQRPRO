<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Feature flags for zero-downtime migrations and gradual rollouts.
    | These can be toggled via environment variables or Redis for instant changes.
    |
    */

    /**
     * Use new 'state' column instead of legacy 'status'
     * 
     * Rollout Strategy:
     * - Phase 1: false (all traffic uses old column)
     * - Phase 2: true for 10% of schools
     * - Phase 3: true for 50% of schools
     * - Phase 4: true for 100% of schools
     */
    'use_state_column' => env('FEATURE_USE_STATE_COLUMN', false),

    /**
     * Gradual rollout percentage (0-100)
     * 
     * Example: 25 means 25% of schools use the new feature
     */
    'state_column_rollout_percent' => env('FEATURE_STATE_ROLLOUT_PERCENT', 0),

    /**
     * Enable dual-write mode (write to both old and new columns)
     * 
     * This should ALWAYS be true during migration phases 1-3
     */
    'dual_write_attendance_state' => env('FEATURE_DUAL_WRITE_STATE', true),

    /**
     * Enable migration monitoring dashboard
     */
    'migration_monitoring_enabled' => env('FEATURE_MIGRATION_MONITORING', true),

    /**
     * Throttle migration job (milliseconds between chunks)
     */
    'migration_throttle_ms' => env('MIGRATION_THROTTLE_MS', 100),

    /**
     * Migration chunk size
     */
    'migration_chunk_size' => env('MIGRATION_CHUNK_SIZE', 1000),
];
