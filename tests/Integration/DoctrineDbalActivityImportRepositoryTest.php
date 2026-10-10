<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportStatus;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Exception\ActivityImportProcessingClaimLost;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportId;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Import\ActivityImportRowMapper;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportRepository;
use Youmad\Endurance\ActivityPostgresql\Tests\Support\PostgreSqlTestDatabase;

final class DoctrineDbalActivityImportRepositoryTest extends TestCase
{
    private Connection $connection;

    private DoctrineDbalActivityImportRepository $imports;

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

        $this->imports = new DoctrineDbalActivityImportRepository(
            new DoctrineDbalActivityTransaction($this->connection),
            new ActivityImportRowMapper(),
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

    public function testTracksClaimHeartbeatAndCompletedImport(): void
    {
        $importId = ActivityImportId::generate();
        $activityId = ActivityId::fromString($importId->toString());
        $this->imports->enqueue($importId, $activityId);

        $claim = $this->imports->start($importId);
        self::assertNotNull($claim);
        self::assertSame(1, $claim->attemptCount);
        self::assertSame(
            $claim->claimId->toString(),
            $this->connection->fetchOne(
                'SELECT processing_claim_id::text FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );

        $beforeHeartbeat = (string) $this->connection->fetchOne(
            'SELECT processing_heartbeat_at::text FROM activity_imports WHERE id = :id',
            ['id' => $importId->toString()],
        );
        usleep(1_000);
        $this->imports->heartbeat($claim);
        $afterHeartbeat = (string) $this->connection->fetchOne(
            'SELECT processing_heartbeat_at::text FROM activity_imports WHERE id = :id',
            ['id' => $importId->toString()],
        );
        self::assertGreaterThan($beforeHeartbeat, $afterHeartbeat);

        $this->imports->complete(
            $claim,
            [
                new ActivityImportWarning(
                    code: ActivityImportWarningCode::ActivityTimerMismatch,
                    message: 'Activity timer differs from session timing.',
                    context: [
                        'activityTimerMicroseconds' => 100,
                        'sessionTimerMicroseconds' => 90,
                    ],
                ),
            ],
        );

        $completed = $this->imports->find($importId);
        self::assertNotNull($completed);
        self::assertSame(ActivityImportStatus::Completed, $completed->status);
        self::assertSame(1, $completed->attemptCount);
        self::assertNotNull($completed->processingStartedAt);
        self::assertNotNull($completed->completedAt);
        self::assertCount(1, $completed->warnings);
        self::assertNull(
            $this->connection->fetchOne(
                'SELECT processing_claim_id::text FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );
        self::assertNull($this->imports->start($importId));
    }

    public function testDoesNotStealLiveProcessingClaim(): void
    {
        $importId = ActivityImportId::generate();
        $this->imports->enqueue(
            $importId,
            ActivityId::fromString($importId->toString()),
        );
        $claim = $this->imports->start($importId);
        self::assertNotNull($claim);

        self::assertNull($this->imports->start($importId));
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT attempt_count FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );
        self::assertSame(
            $claim->claimId->toString(),
            $this->connection->fetchOne(
                'SELECT processing_claim_id::text FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );
    }

    public function testFailQueuedDoesNotOverwriteLiveProcessingClaim(): void
    {
        $importId = ActivityImportId::generate();
        $this->imports->enqueue(
            $importId,
            ActivityId::fromString($importId->toString()),
        );
        $claim = $this->imports->start($importId);
        self::assertNotNull($claim);

        self::assertFalse($this->imports->failQueued(
            $importId,
            'processing_failed',
            'The import could not be completed.',
        ));
        self::assertSame(
            ActivityImportStatus::Processing,
            $this->imports->find($importId)?->status,
        );
        self::assertSame(
            $claim->claimId->toString(),
            $this->connection->fetchOne(
                'SELECT processing_claim_id::text FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );
    }

    public function testReleaseAllowsRetryAndFencesOldClaim(): void
    {
        $importId = ActivityImportId::generate();
        $this->imports->enqueue(
            $importId,
            ActivityId::fromString($importId->toString()),
        );
        $first = $this->imports->start($importId);
        self::assertNotNull($first);
        $this->imports->release($first);

        $queued = $this->imports->find($importId);
        self::assertNotNull($queued);
        self::assertSame(ActivityImportStatus::Queued, $queued->status);
        self::assertSame(1, $queued->attemptCount);
        self::assertNull($queued->processingStartedAt);
        self::assertNull(
            $this->connection->fetchOne(
                'SELECT processing_claim_id::text FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );
        self::assertNull(
            $this->connection->fetchOne(
                'SELECT processing_heartbeat_at::text FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );

        $second = $this->imports->start($importId);
        self::assertNotNull($second);
        self::assertSame(2, $second->attemptCount);
        self::assertFalse($first->claimId->equals($second->claimId));

        try {
            $this->imports->complete($first);
            self::fail('Expected old processing claim to be fenced.');
        } catch (ActivityImportProcessingClaimLost) {
        }

        $this->imports->fail(
            $second,
            'processing_failed',
            'The import could not be completed.',
        );
        self::assertSame(
            ActivityImportStatus::Failed,
            $this->imports->find($importId)?->status,
        );
    }

    public function testDoesNotRestartTerminalProcessedFailure(): void
    {
        $importId = ActivityImportId::generate();
        $this->imports->enqueue(
            $importId,
            ActivityId::fromString($importId->toString()),
        );
        $claim = $this->imports->start($importId);
        self::assertNotNull($claim);
        $this->imports->fail(
            $claim,
            'processing_failed',
            'The import could not be completed.',
        );

        self::assertNull($this->imports->start($importId));
        self::assertSame(
            ActivityImportStatus::Failed,
            $this->imports->find($importId)?->status,
        );
    }

    public function testRetriesQueuedFailureAndClearsPublicError(): void
    {
        $importId = ActivityImportId::generate();
        $this->imports->enqueue(
            $importId,
            ActivityId::fromString($importId->toString()),
        );
        self::assertTrue($this->imports->failQueued(
            $importId,
            'dispatch_failed',
            'The import could not be queued.',
        ));

        $failed = $this->imports->find($importId);
        self::assertNotNull($failed);
        self::assertSame(ActivityImportStatus::Failed, $failed->status);
        self::assertSame(0, $failed->attemptCount);
        self::assertSame('dispatch_failed', $failed->errorCode);

        $claim = $this->imports->start($importId);
        self::assertNotNull($claim);

        $retried = $this->imports->find($importId);
        self::assertNotNull($retried);
        self::assertSame(ActivityImportStatus::Processing, $retried->status);
        self::assertSame(1, $retried->attemptCount);
        self::assertNull($retried->failedAt);
        self::assertNull($retried->errorCode);
        self::assertNull($retried->errorMessage);
        self::assertSame([], $retried->warnings);
    }
}
