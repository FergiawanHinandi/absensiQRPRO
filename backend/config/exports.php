<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Export Chunk Size
    |--------------------------------------------------------------------------
    |
    | This value determines the number of records to process in each chunk
    | when exporting large datasets. A smaller chunk size uses less memory
    | but may take longer. Default is 1000 records per chunk.
    |
    | Recommended values:
    | - Small datasets (< 10K rows): 1000-2000
    | - Medium datasets (10K-50K rows): 500-1000
    | - Large datasets (> 50K rows): 100-500
    |
    */
    'chunk_size' => env('EXPORT_CHUNK_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Export Memory Limit
    |--------------------------------------------------------------------------
    |
    | Maximum memory usage allowed for export operations (in MB).
    | If exports exceed this limit, consider reducing chunk_size.
    |
    */
    'memory_limit' => env('EXPORT_MEMORY_LIMIT', 256),

    /*
    |--------------------------------------------------------------------------
    | Export Progress Tracking
    |--------------------------------------------------------------------------
    |
    | Enable progress tracking for large exports. When enabled, export
    | progress will be stored in the database and can be queried by
    | the frontend to show real-time progress to users.
    |
    */
    'enable_progress_tracking' => env('EXPORT_ENABLE_PROGRESS', true),

    /*
    |--------------------------------------------------------------------------
    | Export Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time (in seconds) allowed for export operations.
    | Increase this value for very large datasets.
    |
    */
    'timeout' => env('EXPORT_TIMEOUT', 600), // 10 minutes
];
