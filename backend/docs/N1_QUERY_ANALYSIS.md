# N+1 Query Analysis Guide

## Laravel Debugbar Installation

Laravel Debugbar has been installed for development environment analysis.

### Installation
```bash
composer require barryvdh/laravel-debugbar --dev --ignore-platform-req=ext-gd
```

### Configuration

Add to your `.env` file for development:
```env
# Laravel Debugbar Configuration
DEBUGBAR_ENABLED=true
DEBUGBAR_COLLECT_JOBS=true
DEBUGBAR_COLLECTORS_DB=true
DEBUGBAR_COLLECTORS_QUERIES=true
```

### Usage

1. **Start Development Server**
   ```bash
   php artisan serve
   ```

2. **Access Any Endpoint**
   - The debugbar will appear at the bottom of the page
   - Click on the "Queries" tab to see all database queries
   - Look for repeated queries with similar patterns (N+1 indicator)

3. **Identify N+1 Patterns**
   - Multiple queries for the same table with different IDs
   - Example: 
     ```
     SELECT * FROM students WHERE id = 1
     SELECT * FROM students WHERE id = 2
     SELECT * FROM students WHERE id = 3
     ...
     ```

### Key Collectors for N+1 Detection

- **db**: Shows all database queries with execution time
- **time**: Shows total execution time
- **memory**: Shows memory usage

### Alternative: Query Logging

If you prefer console-based analysis:

```php
// Add to AppServiceProvider::boot()
if (app()->environment('local')) {
    DB::listen(function ($query) {
        Log::info('Query: ' . $query->sql, [
            'bindings' => $query->bindings,
            'time' => $query->time
        ]);
    });
}
```

## Next Steps

1. Audit all controllers for N+1 queries
2. Document controllers with N+1 issues
3. Add eager loading to fix N+1 patterns
