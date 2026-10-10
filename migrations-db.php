<?php

declare(strict_types=1);

// This configuration is used only by the package's integration test runner.
$database = getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_NAME');
$host = getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_HOST');

if (false === $database
    || !preg_match('/(^test([_-]|$)|[_-]test$)/', $database)
    || false === $host
    || '' === $host) {
    throw new RuntimeException('The migration test runner requires a dedicated PostgreSQL test database and host.');
}

return [
    'driver' => 'pdo_pgsql',
    'host' => $host,
    'port' => (int) (getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_PORT') ?: 5432),
    'dbname' => $database,
    'user' => getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_USER') ?: 'endurance',
    'password' => getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_PASSWORD') ?: 'endurance',
];
