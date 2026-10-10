<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportId;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Import\ActivityImportRowMapper;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportDiagnosticsRepository;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportRepository;
use Youmad\Endurance\ActivityPostgresql\Tests\Support\PostgreSqlTestDatabase;

final class DoctrineDbalActivityImportDiagnosticsRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $host = getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_HOST');

        if (false === $host || '' === $host) {
            self::markTestSkipped(
                'Set ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_HOST to run PostgreSQL integration tests.',
            );
        }

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $host,
            'port' => (int) (getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_PORT') ?: 5432),
            'dbname' => getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_NAME') ?: 'endurance_activity_test',
            'user' => getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_USER') ?: 'endurance',
            'password' => getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_PASSWORD') ?: 'endurance',
        ]);

        PostgreSqlTestDatabase::truncate($this->connection);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->connection)) {
                $this->connection->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testFindsOnlyStaleProcessingImportsWithTheirGeneration(): void
    {
        $staleImportId = ActivityImportId::generate();
        $staleActivityId = ActivityId::fromString(
            $staleImportId->toString(),
        );
        $generationId = ActivityImportGenerationId::generate();

        $this->insertActivity($staleActivityId);

        $imports = new DoctrineDbalActivityImportRepository(
            new DoctrineDbalActivityTransaction($this->connection),
            new ActivityImportRowMapper(),
        );
        $imports->enqueue($staleImportId, $staleActivityId);
        $imports->start($staleImportId);

        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET created_at = clock_timestamp() - interval '30 minutes',
                processing_started_at = clock_timestamp() - interval '25 minutes',
                processing_heartbeat_at = clock_timestamp() - interval '20 minutes',
                updated_at = clock_timestamp() - interval '1 minute'
            WHERE id = :id
            SQL,
            ['id' => $staleImportId->toString()],
        );

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO activity_import_generations (
                id,
                activity_id,
                idempotency_key,
                status,
                attempt_count,
                created_at,
                staging_started_at,
                updated_at
            ) VALUES (
                :id,
                :activity_id,
                :idempotency_key,
                'staging',
                2,
                clock_timestamp() - interval '20 minutes',
                clock_timestamp() - interval '20 minutes',
                clock_timestamp() - interval '18 minutes'
            )
            SQL,
            [
                'id' => $generationId->toString(),
                'activity_id' => $staleActivityId->toString(),
                'idempotency_key' => $staleImportId->toString(),
            ],
        );

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO activity_import_observations (
                import_generation_id,
                activity_id,
                observed_at,
                payload
            ) VALUES (
                :generation_id,
                :activity_id,
                clock_timestamp() - interval '19 minutes',
                '{}'::jsonb
            )
            SQL,
            [
                'generation_id' => $generationId->toString(),
                'activity_id' => $staleActivityId->toString(),
            ],
        );
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO activity_import_sessions (
                import_generation_id,
                activity_id,
                started_at,
                finished_at,
                timer_duration_microseconds,
                payload
            ) VALUES (
                :generation_id,
                :activity_id,
                clock_timestamp() - interval '19 minutes',
                clock_timestamp() - interval '18 minutes',
                60000000,
                '{}'::jsonb
            )
            SQL,
            [
                'generation_id' => $generationId->toString(),
                'activity_id' => $staleActivityId->toString(),
            ],
        );

        $freshImportId = ActivityImportId::generate();
        $imports->enqueue(
            $freshImportId,
            ActivityId::fromString($freshImportId->toString()),
        );
        $imports->start($freshImportId);

        $failedImportId = ActivityImportId::generate();
        $imports->enqueue(
            $failedImportId,
            ActivityId::fromString($failedImportId->toString()),
        );
        self::assertTrue($imports->failQueued(
            $failedImportId,
            'failed_for_test',
            'Failed for test.',
        ));
        $diagnostics = new DoctrineDbalActivityImportDiagnosticsRepository(
            $this->connection,
        );
        $candidates = $diagnostics->findStaleProcessing(900, 100);

        self::assertCount(1, $candidates);
        $candidate = $candidates[0];
        self::assertTrue($candidate->importId->equals($staleImportId));
        self::assertTrue($candidate->activityId->equals($staleActivityId));
        self::assertSame(1, $candidate->attemptCount);
        self::assertGreaterThanOrEqual(1_190, $candidate->idleAgeSeconds);
        self::assertLessThan(1_230, $candidate->idleAgeSeconds);
        self::assertNotNull($candidate->generationId);
        self::assertTrue($candidate->generationId->equals($generationId));
        self::assertSame('staging', $candidate->generationStatus);
        self::assertSame(2, $candidate->generationAttemptCount);
        self::assertNotNull($candidate->generationStagingStartedAt);
        self::assertNotNull($candidate->generationUpdatedAt);
        self::assertNotNull($candidate->generationIdleAgeSeconds);
        self::assertGreaterThanOrEqual(
            1_070,
            $candidate->generationIdleAgeSeconds,
        );
        self::assertLessThan(
            1_110,
            $candidate->generationIdleAgeSeconds,
        );
        self::assertSame(2, $candidate->generationRowCount);
    }

    private function insertActivity(ActivityId $activityId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO activities (
                id,
                started_at,
                latest_timestamp
            ) VALUES (
                :id,
                clock_timestamp() - interval '30 minutes',
                clock_timestamp() - interval '30 minutes'
            )
            SQL,
            ['id' => $activityId->toString()],
        );
    }
}
