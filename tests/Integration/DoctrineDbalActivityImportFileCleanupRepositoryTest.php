<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportId;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Import\ActivityImportRowMapper;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportFileCleanupRepository;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportRepository;
use Youmad\Endurance\ActivityPostgresql\Tests\Support\PostgreSqlTestDatabase;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class DoctrineDbalActivityImportFileCleanupRepositoryTest extends TestCase
{
    private Connection $connection;

    private DoctrineDbalActivityImportRepository $imports;

    private DoctrineDbalActivityImportFileCleanupRepository $cleanup;

    public function testClaimsTerminalImportsAndCoordinatesRetry(): void
    {
        $completedId = $this->completedImport('2026-07-20T10:00:00Z');
        $failedId = $this->failedImport('2026-07-20T11:00:00Z');
        $this->completedImport('2026-08-05T09:30:00Z');
        $processingId = ActivityImportId::generate();
        $this->imports->enqueue(
            $processingId,
            ActivityId::fromString($processingId->toString()),
        );
        $this->imports->start($processingId);

        $claims = $this->cleanup->claimEligibleFiles(
            completedBefore: $this->instant('2026-08-04T10:00:00Z'),
            failedBefore: $this->instant('2026-07-29T10:00:00Z'),
            staleClaimBefore: $this->instant('2026-08-05T09:45:00Z'),
            limit: 10,
        );

        self::assertCount(2, $claims);
        $claimsById = [];

        foreach ($claims as $claim) {
            $claimsById[$claim->importId->toString()] = $claim;
        }

        self::assertArrayHasKey($completedId->toString(), $claimsById);
        self::assertArrayHasKey($failedId->toString(), $claimsById);
        self::assertNull($this->imports->start($failedId));

        $this->cleanup->markFileDeleted(
            $claimsById[$completedId->toString()],
        );
        $this->cleanup->releaseFile(
            $claimsById[$failedId->toString()],
        );
        self::assertNotNull($this->imports->start($failedId));

        self::assertNotFalse(
            $this->connection->fetchOne(
                <<<'SQL'
            SELECT source_file_deleted_at
            FROM activity_imports
            WHERE id = :id
            SQL,
                ['id' => $completedId->toString()],
            ),
        );
        self::assertNull(
            $this->connection->fetchOne(
                <<<'SQL'
            SELECT source_file_cleanup_claim_id
            FROM activity_imports
            WHERE id = :id
            SQL,
                ['id' => $failedId->toString()],
            ),
        );
        self::assertTrue($this->cleanup->importExists($processingId));
        self::assertFalse(
            $this->cleanup->importExists(ActivityImportId::generate()),
        );
    }

    private function completedImport(string $completedAt): ActivityImportId
    {
        $id = ActivityImportId::generate();
        $this->imports->enqueue(
            $id,
            ActivityId::fromString($id->toString()),
        );
        $claim = $this->imports->start($id);
        self::assertNotNull($claim);
        $this->imports->complete($claim);
        $this->setTerminalTime($id, 'completed_at', $completedAt);

        return $id;
    }

    private function setTerminalTime(
        ActivityImportId $id,
        string $column,
        string $time,
    ): void {
        self::assertContains($column, ['completed_at', 'failed_at']);

        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
            UPDATE activity_imports
            SET created_at = CAST(:time AS TIMESTAMPTZ),
                processing_started_at = CASE
                    WHEN processing_started_at IS NULL THEN NULL
                    ELSE CAST(:time AS TIMESTAMPTZ)
                END,
                processing_heartbeat_at = CASE
                    WHEN processing_heartbeat_at IS NULL THEN NULL
                    ELSE CAST(:time AS TIMESTAMPTZ)
                END,
                %s = CAST(:time AS TIMESTAMPTZ),
                updated_at = CAST(:time AS TIMESTAMPTZ)
            WHERE id = :id
            SQL,
                $column,
            ),
            [
                'id' => $id->toString(),
                'time' => $time,
            ],
        );
    }

    private function failedImport(string $failedAt): ActivityImportId
    {
        $id = ActivityImportId::generate();
        $this->imports->enqueue(
            $id,
            ActivityId::fromString($id->toString()),
        );
        self::assertTrue($this->imports->failQueued(
            $id,
            'invalid_fit',
            'The FIT file is invalid or unsupported.',
        ));
        $this->setTerminalTime($id, 'failed_at', $failedAt);

        return $id;
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }

    public function testDeletedFailedImportCannotBeRetried(): void
    {
        $failedId = $this->failedImport('2026-07-20T11:00:00Z');
        $claims = $this->cleanup->claimEligibleFiles(
            completedBefore: $this->instant('2026-08-04T10:00:00Z'),
            failedBefore: $this->instant('2026-07-29T10:00:00Z'),
            staleClaimBefore: $this->instant('2026-08-05T09:45:00Z'),
            limit: 10,
        );

        self::assertCount(1, $claims);
        $this->cleanup->markFileDeleted($claims[0]);

        self::assertNull($this->imports->start($failedId));
        self::assertNotFalse(
            $this->connection->fetchOne(
                <<<'SQL'
            SELECT source_file_deleted_at
            FROM activity_imports
            WHERE id = :id
            SQL,
                ['id' => $failedId->toString()],
            ),
        );
        self::assertSame(
            'failed',
            $this->connection->fetchOne(
                'SELECT status FROM activity_imports WHERE id = :id',
                ['id' => $failedId->toString()],
            ),
        );
    }

    protected function setUp(): void
    {
        $host = getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_HOST');

        if (false === $host || '' === $host) {
            self::markTestSkipped(
                'Set TRACKER_ACTIVITY_POSTGRES_DOCTRINE_HOST to run PostgreSQL integration tests.',
            );
        }

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $host,
            'port' => (int) (getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_PORT') ?: 5432),
            'dbname' => getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_DB') ?: 'tracker_activity_test',
            'user' => getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_USER') ?: 'tracker',
            'password' => getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_PASSWORD') ?: 'tracker',
        ]);
        PostgreSqlTestDatabase::truncate($this->connection);

        $transaction = new DoctrineDbalActivityTransaction($this->connection);
        $this->imports = new DoctrineDbalActivityImportRepository(
            $transaction,
            new ActivityImportRowMapper(),
        );
        $this->cleanup = new DoctrineDbalActivityImportFileCleanupRepository(
            $transaction,
        );
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
}
