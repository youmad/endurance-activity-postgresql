<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportStatus;
use Youmad\Endurance\Activity\Application\Recovery\ActivityImportRecoveryAction;
use Youmad\Endurance\Activity\Application\UseCase\ActivityImportLifecycle;
use Youmad\Endurance\Activity\Application\UseCase\ActivityImportRecoveryCoordinator;
use Youmad\Endurance\Activity\Exception\ActivityImportProcessingClaimLost;
use Youmad\Endurance\Activity\Exception\ActivityImportRecoveryClaimLost;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Import\ActivityImportRowMapper;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportGenerationRepository;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportRecoveryRepository;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportRepository;
use Youmad\Endurance\ActivityPostgresql\Tests\Support\PostgreSqlTestDatabase;

final class DoctrineDbalActivityImportRecoveryRepositoryTest extends TestCase
{
    private Connection $connection;

    private ActivityImportLifecycle $imports;

    private DoctrineDbalActivityImportGenerationRepository $generations;

    private ActivityImportRecoveryCoordinator $recoveries;

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
            'port' => (int) (
                getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_PORT') ?: 5432
            ),
            'dbname' => getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_DB')
                ?: 'tracker_activity_test',
            'user' => getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_USER')
                ?: 'tracker',
            'password' => getenv('TRACKER_ACTIVITY_POSTGRES_DOCTRINE_PASSWORD')
                ?: 'tracker',
        ]);
        PostgreSqlTestDatabase::truncate($this->connection);

        $transaction = new DoctrineDbalActivityTransaction($this->connection);
        $this->imports = new ActivityImportLifecycle(
            new DoctrineDbalActivityImportRepository(
                $transaction,
                new ActivityImportRowMapper(),
            ),
        );
        $this->generations =
            new DoctrineDbalActivityImportGenerationRepository($transaction);
        $this->recoveries = new ActivityImportRecoveryCoordinator(
            recoveries: new DoctrineDbalActivityImportRecoveryRepository(
                $transaction,
            ),
            transaction: $transaction,
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

    public function testClaimNextSkipsLockedImportAndClaimsAnotherCandidate(): void
    {
        [$lockedImportId] = $this->queuedImport();
        self::assertNotNull($this->imports->start($lockedImportId));
        $this->makeProcessingStale($lockedImportId);

        [$availableImportId] = $this->queuedImport();
        self::assertNotNull($this->imports->start($availableImportId));
        $this->makeProcessingStale($availableImportId);

        $locker = DriverManager::getConnection($this->connection->getParams());
        $locker->beginTransaction();

        try {
            self::assertSame(
                $lockedImportId->toString(),
                $locker->fetchOne(
                    <<<'SQL'
                    SELECT id::text
                    FROM activity_imports
                    WHERE id = :id
                    FOR UPDATE
                    SQL,
                    ['id' => $lockedImportId->toString()],
                ),
            );

            $claim = $this->recoveries->claimNext(900, 300);
            self::assertNotNull($claim);
            self::assertTrue($claim->importId->equals($availableImportId));
            self::assertSame(
                'processing',
                $this->connection->fetchOne(
                    'SELECT status FROM activity_imports WHERE id = :id',
                    ['id' => $lockedImportId->toString()],
                ),
            );
        } finally {
            $locker->rollBack();
            $locker->close();
        }
    }

    public function testPartialCleanupCanResumeAfterRecoveryWorkerDies(): void
    {
        [$importId, $activityId] = $this->queuedImport();
        self::assertNotNull($this->imports->start($importId));
        $generation = $this->generations->claim(
            $activityId,
            ActivityImportIdempotencyKey::fromString($importId->toString()),
        );
        self::assertTrue($generation->isAcquired());
        $generationId = $generation->generationId;

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO activity_import_observations (
                import_generation_id,
                activity_id,
                observed_at,
                payload
            )
            SELECT
                :generation_id,
                :activity_id,
                clock_timestamp() + make_interval(secs => value),
                '{}'::jsonb
            FROM generate_series(1, 3) AS series(value)
            SQL,
            [
                'generation_id' => $generationId->toString(),
                'activity_id' => $activityId->toString(),
            ],
        );
        $this->makeProcessingStale($importId);

        $first = $this->recoveries->claimNext(900, 300);
        self::assertNotNull($first);
        $firstBatch = $this->recoveries->cleanupBatch($first, 1);
        self::assertSame(1, $firstBatch->deletedRows);
        self::assertFalse($firstBatch->complete);
        self::assertSame(
            2,
            (int) $this->connection->fetchOne(
                'SELECT count(*) FROM activity_import_observations',
            ),
        );

        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET recovery_claimed_at = clock_timestamp() - interval '10 minutes'
            WHERE id = :id
            SQL,
            ['id' => $importId->toString()],
        );

        $second = $this->recoveries->claimNext(900, 300);
        self::assertNotNull($second);
        self::assertFalse($first->claimId->equals($second->claimId));

        try {
            $this->recoveries->cleanupBatch($first, 1);
            self::fail('Expected abandoned recovery claim to be fenced.');
        } catch (ActivityImportRecoveryClaimLost) {
            // The rejected claim is the assertion; recovery continues below.
        }

        $deletedAfterResume = 0;

        do {
            $batch = $this->recoveries->cleanupBatch($second, 1);
            $deletedAfterResume += $batch->deletedRows;
        } while (!$batch->complete);

        self::assertSame(2, $deletedAfterResume);
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT count(*) FROM activity_import_observations',
            ),
        );

        $result = $this->recoveries->finalize(
            claim: $second,
            maxAttempts: 3,
        );
        self::assertSame(
            ActivityImportRecoveryAction::Requeued,
            $result->action,
        );
        self::assertSame(
            'queued',
            $this->connection->fetchOne(
                'SELECT status FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );
    }

    public function testFencesStaleWorkerCleansStagingAndRequeues(): void
    {
        [$importId, $activityId] = $this->queuedImport();
        $processingClaim = $this->imports->start($importId);
        self::assertNotNull($processingClaim);

        $generation = $this->generations->claim(
            $activityId,
            ActivityImportIdempotencyKey::fromString($importId->toString()),
        );
        self::assertTrue($generation->isAcquired());
        $generationId = $generation->generationId;

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
                clock_timestamp(),
                '{}'::jsonb
            )
            SQL,
            [
                'generation_id' => $generationId->toString(),
                'activity_id' => $activityId->toString(),
            ],
        );
        $this->makeProcessingStale($importId);

        $recoveryClaim = $this->recoveries->claimNext(900, 300);
        self::assertNotNull($recoveryClaim);
        self::assertTrue($generationId->equals($recoveryClaim->generationId));
        self::assertSame(
            'recovering',
            $this->connection->fetchOne(
                'SELECT status FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );
        self::assertSame(
            ActivityImportStatus::Recovering,
            $this->imports->find($importId)?->status,
        );
        self::assertSame(
            'failed',
            $this->connection->fetchOne(
                'SELECT status FROM activity_import_generations WHERE id = :id',
                ['id' => $generationId->toString()],
            ),
        );

        $this->expectProcessingClaimLost($processingClaim);

        $first = $this->recoveries->cleanupBatch($recoveryClaim, 1);
        self::assertSame(1, $first->deletedRows);
        self::assertFalse($first->complete);
        $second = $this->recoveries->cleanupBatch($recoveryClaim, 1);
        self::assertSame(0, $second->deletedRows);
        self::assertTrue($second->complete);

        $callbackCalled = false;
        $result = $this->recoveries->finalize(
            claim: $recoveryClaim,
            maxAttempts: 3,
            afterRequeue: static function () use (&$callbackCalled): void {
                $callbackCalled = true;
            },
        );

        self::assertTrue($callbackCalled);
        self::assertSame(ActivityImportRecoveryAction::Requeued, $result->action);
        self::assertSame(
            'queued',
            $this->connection->fetchOne(
                'SELECT status FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT count(*) FROM activity_import_observations',
            ),
        );
    }

    public function testExhaustedStaleAttemptBecomesTerminalFailure(): void
    {
        [$importId] = $this->queuedImport();

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $processingClaim = $this->imports->start($importId);
            self::assertNotNull($processingClaim);

            if ($attempt < 3) {
                $this->imports->release($processingClaim);
            }
        }

        $this->makeProcessingStale($importId);
        $recoveryClaim = $this->recoveries->claimNext(900, 300);
        self::assertNotNull($recoveryClaim);
        self::assertTrue(
            $this->recoveries->cleanupBatch($recoveryClaim, 100)->complete,
        );

        $callbackCalled = false;
        $result = $this->recoveries->finalize(
            claim: $recoveryClaim,
            maxAttempts: 3,
            afterRequeue: static function () use (&$callbackCalled): void {
                $callbackCalled = true;
            },
        );

        self::assertFalse($callbackCalled);
        self::assertSame(ActivityImportRecoveryAction::Failed, $result->action);
        self::assertSame(
            'stale_processing_exhausted',
            $this->connection->fetchOne(
                'SELECT error_code FROM activity_imports WHERE id = :id',
                ['id' => $importId->toString()],
            ),
        );
    }

    public function testExpiredRecoveryLeaseCanBeReclaimed(): void
    {
        [$importId] = $this->queuedImport();
        self::assertNotNull($this->imports->start($importId));
        $this->makeProcessingStale($importId);

        $first = $this->recoveries->claimNext(900, 300);
        self::assertNotNull($first);
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET recovery_claimed_at = clock_timestamp() - interval '10 minutes'
            WHERE id = :id
            SQL,
            ['id' => $importId->toString()],
        );

        $second = $this->recoveries->claimNext(900, 300);
        self::assertNotNull($second);
        self::assertFalse($first->claimId->equals($second->claimId));

        try {
            $this->recoveries->cleanupBatch($first, 100);
            self::fail('Expected first recovery claim to be fenced.');
        } catch (ActivityImportRecoveryClaimLost) {
            // The rejected claim is the assertion; cleanup continues below.
        }

        self::assertTrue(
            $this->recoveries->cleanupBatch($second, 100)->complete,
        );
    }

    /** @return array{ActivityImportId, ActivityId} */
    private function queuedImport(): array
    {
        $importId = ActivityImportId::generate();
        $activityId = ActivityId::fromString($importId->toString());
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO activities (id, started_at, latest_timestamp)
            VALUES (:id, clock_timestamp(), clock_timestamp())
            SQL,
            ['id' => $activityId->toString()],
        );
        $this->imports->queue($importId, $activityId);

        return [$importId, $activityId];
    }

    private function makeProcessingStale(ActivityImportId $importId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET processing_started_at = created_at,
                processing_heartbeat_at = created_at
            WHERE id = :id
            SQL,
            ['id' => $importId->toString()],
        );
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET created_at = created_at - interval '1 hour',
                processing_started_at = processing_started_at - interval '1 hour',
                processing_heartbeat_at = processing_heartbeat_at - interval '1 hour'
            WHERE id = :id
            SQL,
            ['id' => $importId->toString()],
        );
    }

    private function expectProcessingClaimLost(
        \Youmad\Endurance\Activity\Application\Import\ActivityImportProcessingClaim $claim,
    ): void {
        try {
            $this->imports->heartbeat($claim);
            self::fail('Expected processing claim to be fenced.');
        } catch (ActivityImportProcessingClaimLost) {
            // The expected exception confirms that the old claim is fenced.
        }
    }
}
