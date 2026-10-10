# Endurance Activity PostgreSQL

Doctrine DBAL adapters and migrations for Endurance Activity storage. Supports
aggregate persistence, staged streaming imports and activity, lap and track
reads. Tested with PostgreSQL 18.

## Installation

Requires PHP `^8.5` and `ext-pdo_pgsql`.

```bash
composer require youmad/endurance-activity-postgresql
```

## Database setup

Register and execute the migrations in `migrations/` through your application's
Doctrine Migrations configuration.

For Symfony with DoctrineMigrationsBundle:

```yaml
doctrine_migrations:
    migrations_paths:
        'Youmad\Endurance\ActivityPostgresql\Migrations': '%kernel.project_dir%/vendor/youmad/endurance-activity-postgresql/migrations'
```

The schema stores the Activity aggregate, import lifecycle and generations,
with generation-owned observations, laps, sessions, details and devices.
Read imported child data through the `active_activity_import_*` views, which
expose only the activated generation.

## Aggregate persistence

```php
use Youmad\Endurance\ActivityPostgresql\Activity\ActivityRowMapper;
use Youmad\Endurance\ActivityPostgresql\Activity\DoctrineDbalActivityRepository;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;

$transaction = new DoctrineDbalActivityTransaction($connection);
$activities = new DoctrineDbalActivityRepository(
    transaction: $transaction,
    rows: new ActivityRowMapper(),
);

$transaction->run(static function () use ($activities, $activity): void {
    $activities->save($activity);
});
```

`$connection` is a Doctrine DBAL connection and `$activity` is an Activity
aggregate. Repository saves use optimistic locking. Discard an aggregate involved
in a rolled-back transaction and reload it before retrying.

## Streaming imports

Use the same `DoctrineDbalActivityTransaction` for the aggregate repository and
`DoctrineDbalActivityImportGenerationRepository`. Create an
`ActivityImportGenerationCoordinator` with the generation repository and that
transaction. Pass the coordinator and aggregate repository to
`ImportActivityStream`.

Create a `DoctrineDbalStagedWriteExecutor` from that transaction and use it for
the staged writers under `Import`. Observation and device-status batch writers
can be wrapped by the Activity package's batching adapters. Configure the item
handlers and `ImportActivityStream` in the consuming application.

Staging batches commit independently and must run outside an outer transaction.
Activation persists the aggregate and exposes the completed generation in one
transaction. Failed generations stay invisible and are retained until retry or
cleanup; the application defines their retention policy.

Import processing claims, heartbeats and source-file cleanup claims are also
provided. Completion, failure and release require the claim that owns the
current processing attempt.

## Reading activities

Adapters under `Read` implement the Activity read ports. Track pages preserve
all scalar readings, units, origins and sources in payload order. Convenience
fields such as `heartRate` are null when a measurement type is ambiguous.
Source-specific measurement metadata remains stored in the JSON payload and is
not exposed by the scalar track projection.

## Development

From a source checkout:

```bash
composer install
composer check
```

By default, integration tests use Docker to start a disposable PostgreSQL 18
database. To use a dedicated existing test database, set
`ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_MODE=external` and
`ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_HOST`. Optional connection settings
use the same prefix: `PORT`, `NAME`, `USER`, `PASSWORD` and `PASSWORD_FILE`;
see [bin/test-integration](bin/test-integration) for defaults. The runner
applies migrations, and the suite truncates application tables between tests.

## License

[MPL-2.0](LICENSE).
