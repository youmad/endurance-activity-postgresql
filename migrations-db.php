<?php

declare(strict_types=1);

// This configuration is used only by the package's integration test runner.
$database = getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_DB');
$host = getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_HOST');

if (false === $database
    || !preg_match('/(^test([_-]|$)|[_-]test$)/', $database)
    || false === $host
    || '' === $host) {
    throw new RuntimeException('The migration test runner requires a dedicated PostgreSQL test database and host.');
}

return [
    'driver' => 'pdo_pgsql',
    'host' => $host,
    'port' => (int) (getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_PORT') ?: 5432),
    'dbname' => $database,
    'user' => getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_USER') ?: 'tracker',
    'password' => getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_PASSWORD') ?: 'tracker',
];
