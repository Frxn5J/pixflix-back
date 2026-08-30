<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL') ?: env('DATABASE_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL') ?: env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL') ?: env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => extension_loaded('pdo_pgsql') ? array_filter([
                PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5),
                PDO::ATTR_EMULATE_PREPARES => false,
            ], static fn (mixed $value): bool => $value !== null) : [],
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL') ?: env('DATABASE_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
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
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Dragonfly Databases (Redis-compatible protocol)
    |--------------------------------------------------------------------------
    |
    | Dragonfly provides the cache, session, lock and queue backend. Laravel's
    | connector is named Redis because Dragonfly speaks the same RESP protocol.
    |
    */

    'redis' => [

        'client' => env('DRAGONFLY_CLIENT', env('REDIS_CLIENT', 'phpredis')),

        'options' => [
            'cluster' => env('DRAGONFLY_CLUSTER', env('REDIS_CLUSTER', 'redis')),
            'prefix' => env('DRAGONFLY_PREFIX', env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_')),
        ],

        'default' => [
            'url' => env('DRAGONFLY_URL') ?: env('REDIS_URL'),
            'host' => env('DRAGONFLY_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username' => env('DRAGONFLY_USERNAME', env('REDIS_USERNAME')),
            'password' => env('DRAGONFLY_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('DRAGONFLY_PORT', env('REDIS_PORT', '6379')),
            'database' => env('DRAGONFLY_DB', env('REDIS_DB', '0')),
            'read_timeout' => (float) env('DRAGONFLY_READ_TIMEOUT', 60),
            'timeout' => (float) env('DRAGONFLY_CONNECT_TIMEOUT', 5),
            'retry_interval' => (int) env('DRAGONFLY_RETRY_INTERVAL', 100),
        ],

        'cache' => [
            'url' => env('DRAGONFLY_URL') ?: env('REDIS_URL'),
            'host' => env('DRAGONFLY_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username' => env('DRAGONFLY_USERNAME', env('REDIS_USERNAME')),
            'password' => env('DRAGONFLY_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('DRAGONFLY_PORT', env('REDIS_PORT', '6379')),
            'database' => env('DRAGONFLY_CACHE_DB', env('REDIS_CACHE_DB', '1')),
            'read_timeout' => (float) env('DRAGONFLY_READ_TIMEOUT', 60),
            'timeout' => (float) env('DRAGONFLY_CONNECT_TIMEOUT', 5),
            'retry_interval' => (int) env('DRAGONFLY_RETRY_INTERVAL', 100),
        ],

    ],

];
