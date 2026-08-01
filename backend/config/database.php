<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            
            /*
            |------------------------------------------------------------------
            | Read/Write Split Configuration (Primary + Replica)
            |------------------------------------------------------------------
            |
            | When DB_READ_HOST is set, Laravel will automatically route:
            | - SELECT queries → Replica (read)
            | - INSERT/UPDATE/DELETE → Primary (write)
            |
            | If DB_READ_HOST is null, all queries use primary (fallback).
            | This ensures zero downtime if replica is unavailable.
            |
            */
            'read' => env('DB_READ_HOST') ? [
                'host' => [
                    env('DB_READ_HOST'),
                    // Add more read replicas here for load balancing
                    // env('DB_READ_HOST_2'),
                    // env('DB_READ_HOST_3'),
                ],
                'port' => env('DB_READ_PORT', env('DB_PORT', '3306')),
            ] : null,
            
            'write' => [
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
            ],
            
            // Sticky connections: after write, subsequent reads use write connection
            // Prevents reading stale data immediately after writing
            'sticky' => env('DB_STICKY', true),
            
            // Shared configuration for both read and write
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),

            /*
            |------------------------------------------------------------------
            | Read/Write Split Configuration (Primary + Replica)
            |------------------------------------------------------------------
            |
            | When DB_REPLICA_HOST is set, Laravel will automatically route:
            | - SELECT queries → Replica (read)
            | - INSERT/UPDATE/DELETE → Primary (write)
            |
            | Set DB_REPLICA_ENABLED=true to enable read/write splitting.
            | In failover scenario, set DB_REPLICA_HOST to primary host.
            |
            */
            'read' => env('DB_REPLICA_ENABLED', false) ? [
                'host' => [
                    env('DB_REPLICA_HOST', env('DB_HOST', '127.0.0.1')),
                    // Add more replicas here for load balancing
                    // env('DB_REPLICA_HOST_2'),
                ],
                'port' => env('DB_REPLICA_PORT', env('DB_PORT', '5432')),
            ] : null,

            'write' => [
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
            ],

            // Sticky connections: after write, subsequent reads use write connection
            // Prevents reading stale data immediately after writing
            'sticky' => env('DB_STICKY', true),

            // Shared configuration for both read and write
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),

            // Connection pool settings for high availability
            'options' => [
                PDO::ATTR_TIMEOUT => env('DB_TIMEOUT', 5),
                PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false),
            ],

            // Retry configuration for transient failures
            'retry_after' => env('DB_RETRY_AFTER', 100), // milliseconds
            'max_retries' => env('DB_MAX_RETRIES', 3),

            // Path to pg_dump binary for backups (Windows: C:\Program Files\PostgreSQL\XX\bin)
            'dump' => [
                'dump_binary_path' => env('PG_DUMP_PATH', 'C:\\Program Files\\PostgreSQL\\18\\bin'),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Failover Connection (Manual Failover Target)
        |----------------------------------------------------------------------
        |
        | Use this connection when primary fails and you need to promote replica.
        | Switch by setting DB_CONNECTION=pgsql_failover in .env
        |
        */
        'pgsql_failover' => [
            'driver' => 'pgsql',
            'host' => env('DB_FAILOVER_HOST', env('DB_REPLICA_HOST', '127.0.0.1')),
            'port' => env('DB_FAILOVER_PORT', env('DB_REPLICA_PORT', '5432')),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_FAILOVER_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_FAILOVER_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        // Resilient connection configuration
        'max_retries' => env('REDIS_MAX_RETRIES', 3),
        'health_check_interval' => env('REDIS_HEALTH_CHECK_INTERVAL', 30),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Redis Connection Configuration
        |--------------------------------------------------------------------------
        |
        | Supports both standard single-instance connection and Sentinel HA.
        | To enable Sentinel: set REDIS_SENTINELS=true in .env
        |
        | Database allocation:
        | - Database 0: Default/General purpose
        | - Database 1: Cache data
        | - Database 2: Session data
        | - Database 3: Queue data
        |
        */

        'default' => env('REDIS_SENTINELS', false) ? [
            env('REDIS_SENTINEL_1', 'tcp://sentinel-1:26379?timeout=0.1'),
            env('REDIS_SENTINEL_2', 'tcp://sentinel-2:26379?timeout=0.1'),
            env('REDIS_SENTINEL_3', 'tcp://sentinel-3:26379?timeout=0.1'),
            'options' => [
                'replication' => 'sentinel',
                'service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
                'parameters' => [
                    'database' => 0,
                    'password' => env('REDIS_PASSWORD'),
                ],
            ],
        ] : [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => 0,
        ],

        'cache' => env('REDIS_SENTINELS', false) ? [
            env('REDIS_SENTINEL_1', 'tcp://sentinel-1:26379?timeout=0.1'),
            env('REDIS_SENTINEL_2', 'tcp://sentinel-2:26379?timeout=0.1'),
            env('REDIS_SENTINEL_3', 'tcp://sentinel-3:26379?timeout=0.1'),
            'options' => [
                'replication' => 'sentinel',
                'service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
                'parameters' => [
                    'database' => 1,
                    'password' => env('REDIS_PASSWORD'),
                ],
            ],
        ] : [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => 1,
        ],

        'session' => env('REDIS_SENTINELS', false) ? [
            env('REDIS_SENTINEL_1', 'tcp://sentinel-1:26379?timeout=0.1'),
            env('REDIS_SENTINEL_2', 'tcp://sentinel-2:26379?timeout=0.1'),
            env('REDIS_SENTINEL_3', 'tcp://sentinel-3:26379?timeout=0.1'),
            'options' => [
                'replication' => 'sentinel',
                'service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
                'parameters' => [
                    'database' => 2,
                    'password' => env('REDIS_PASSWORD'),
                ],
            ],
        ] : [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => 2,
        ],

        'queue' => env('REDIS_SENTINELS', false) ? [
            env('REDIS_SENTINEL_1', 'tcp://sentinel-1:26379?timeout=0.1'),
            env('REDIS_SENTINEL_2', 'tcp://sentinel-2:26379?timeout=0.1'),
            env('REDIS_SENTINEL_3', 'tcp://sentinel-3:26379?timeout=0.1'),
            'options' => [
                'replication' => 'sentinel',
                'service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
                'parameters' => [
                    'database' => 3,
                    'password' => env('REDIS_PASSWORD'),
                ],
            ],
        ] : [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => 3,
        ],

    ],

];
