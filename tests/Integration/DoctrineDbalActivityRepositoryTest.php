<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\UseCase\ActivityImportGenerationCoordinator;
use Youmad\Endurance\Activity\Detail\ActivityInterval;
use Youmad\Endurance\Activity\Detail\Pool\PoolLength;
use Youmad\Endurance\Activity\Detail\Pool\PoolLengthType;
use Youmad\Endurance\Activity\Entity\Activity;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\Summary\ActivitySummary;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\ActivityPostgresql\Activity\ActivityRowMapper;
use Youmad\Endurance\ActivityPostgresql\Activity\DoctrineDbalActivityRepository;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityImportPersistenceException;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityPersistenceException;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportGenerationRepository;
use Youmad\Endurance\ActivityPostgresql\Tests\Support\PostgreSqlTestDatabase;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class DoctrineDbalActivityRepositoryTest extends TestCase
{
    private Connection $connection;

    private DoctrineDbalActivityTransaction $transaction;

    private DoctrineDbalActivityRepository $activities;

    private DoctrineDbalActivityImportGenerationRepository $generations;

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
            'port' => (int) (
                getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_PORT') ?: 5432
            ),
            'dbname' => getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_NAME')
                ?: 'endurance_activity_test',
            'user' => getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_USER')
                ?: 'endurance',
            'password' => getenv('ENDURANCE_ACTIVITY_POSTGRESQL_TEST_DATABASE_PASSWORD')
                ?: 'endurance',
        ]);
        $this->resetDatabase();

        $this->transaction = new DoctrineDbalActivityTransaction(
            $this->connection,
        );
        $this->activities = $this->repository();
        $this->generations =
            new DoctrineDbalActivityImportGenerationRepository(
                $this->transaction,
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

    public function testPersistsInitialTimerStartAcrossTransactions(): void
    {
        $activity = Activity::start($this->instant('2026-01-15T10:00:00Z'));
        self::assertTrue($this->activities->createIfAbsent($activity));
        $activity->confirmTimerStartedAt($this->instant('2026-01-15T10:00:03Z'));
        $activity->pause($this->instant('2026-01-15T10:00:20Z'));
        $this->transaction->run(fn (): null => $this->saveAndReturnNull($activity));
        $restored = $this->activities->get($activity->id);
        self::assertTrue($restored->timerStartedAt?->equals($activity->timerStartedAt));
        $restored->resume($this->instant('2026-01-15T10:00:25Z'));
        $restored->finish($this->instant('2026-01-15T10:01:00Z'));
        $this->transaction->run(fn (): null => $this->saveAndReturnNull($restored));
        self::assertSame(52_000_000, $this->activities->get($activity->id)->timerDuration()?->toMicroseconds());
    }

    public function testPersistsAndRestoresCompleteAggregateState(): void
    {
        $activity = $this->completeActivity();

        $this->transaction->run(
            fn (): null => $this->saveAndReturnNull($activity),
        );

        $restored = $this->activities->get($activity->id);
        $expected = $activity->snapshot();
        $actual = $restored->snapshot();

        self::assertTrue($activity->id->equals($restored->id));
        self::assertTrue(
            $expected->startedAt->equals($actual->startedAt),
        );
        self::assertTrue(
            $expected->finishedAt?->equals($actual->finishedAt),
        );
        self::assertTrue(
            $expected->lastObservationAt?->equals(
                $actual->lastObservationAt,
            ),
        );
        self::assertTrue(
            $expected->lastLapFinishedAt?->equals(
                $actual->lastLapFinishedAt,
            ),
        );
        self::assertTrue(
            $expected->lastSessionFinishedAt?->equals(
                $actual->lastSessionFinishedAt,
            ),
        );
        self::assertTrue(
            $expected->lastDetailFinishedAt?->equals(
                $actual->lastDetailFinishedAt,
            ),
        );
        self::assertSame(
            $expected->sessionCount,
            $actual->sessionCount,
        );
        self::assertSame(
            $expected->recordedSessionTimerDuration
                ->toMicroseconds(),
            $actual->recordedSessionTimerDuration
                ->toMicroseconds(),
        );
        self::assertTrue(
            $expected->lastSessionTimelineFinishedAt?->equals(
                $actual->lastSessionTimelineFinishedAt,
            ),
        );
        self::assertSame(
            $expected->accumulatedPausedDuration
                ->toMicroseconds(),
            $actual->accumulatedPausedDuration
                ->toMicroseconds(),
        );
        self::assertSame($expected->type, $actual->type);
        self::assertSame(
            $expected->localTimeOffsetSeconds,
            $actual->localTimeOffsetSeconds,
        );
        self::assertTrue(
            $expected->summaryReportedAt?->equals(
                $actual->summaryReportedAt,
            ),
        );
        self::assertTrue(
            $expected->lastSequentialDetailFinishedAt['pool_length']
                ->equals(
                    $actual
                        ->lastSequentialDetailFinishedAt['pool_length'],
                ),
        );
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT version FROM activities WHERE id = :id',
                ['id' => $activity->id->toString()],
            ),
        );
    }

    public function testCreatesActivityOnlyOnce(): void
    {
        $activityId = ActivityId::generate();
        $startedAt = $this->instant('2026-01-15T10:00:00Z');
        $first = Activity::startWithId($activityId, $startedAt);
        $second = Activity::startWithId($activityId, $startedAt);

        $firstCreated = $this->transaction->run(
            fn (): bool => $this->activities->createIfAbsent($first),
        );
        $secondCreated = $this->transaction->run(
            fn (): bool => $this->activities->createIfAbsent($second),
        );

        self::assertTrue($firstCreated);
        self::assertFalse($secondCreated);
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM activities WHERE id = :id',
                ['id' => $activityId->toString()],
            ),
        );
    }

    public function testRejectsStaleAggregateUpdate(): void
    {
        $activity = Activity::start(
            $this->instant('2026-01-15T10:00:00Z'),
        );
        $this->transaction->run(
            fn (): null => $this->saveAndReturnNull($activity),
        );

        $first = $this->activities->get($activity->id);
        $second = $this->activities->get($activity->id);

        $first->pause(
            $this->instant('2026-01-15T10:01:00Z'),
        );
        $this->transaction->run(
            fn (): null => $this->saveAndReturnNull($first),
        );

        $second->pause(
            $this->instant('2026-01-15T10:02:00Z'),
        );

        try {
            $this->transaction->run(
                fn (): null => $this->saveAndReturnNull($second),
            );

            self::fail('Expected stale aggregate update to fail.');
        } catch (ActivityPersistenceException $exception) {
            self::assertStringContainsString(
                'modified concurrently',
                $exception->getMessage(),
            );
        }

        $restored = $this->repository()->get($activity->id);

        self::assertTrue(
            $restored->pausedAt?->equals(
                $this->instant('2026-01-15T10:01:00Z'),
            ),
        );
        self::assertSame(
            2,
            (int) $this->connection->fetchOne(
                'SELECT version FROM activities WHERE id = :id',
                ['id' => $activity->id->toString()],
            ),
        );
    }

    public function testSavesAggregateAndActivatesGenerationAtomically(): void
    {
        $activity = Activity::start(
            $this->instant('2026-01-15T10:00:00Z'),
        );
        $this->transaction->run(
            fn (): null => $this->saveAndReturnNull($activity),
        );
        $claim = $this->generations->claim(
            activityId: $activity->id,
            idempotencyKey: ActivityImportIdempotencyKey::fromString(
                'fit-file-success-test',
            ),
        );
        $loaded = $this->activities->get($activity->id);
        $loaded->pause(
            $this->instant('2026-01-15T10:01:00Z'),
        );
        $coordinator = new ActivityImportGenerationCoordinator(
            generations: $this->generations,
            transaction: $this->transaction,
        );

        $coordinator->activate(
            generationId: $claim->generationId,
            persistAggregate: fn (): null => $this->saveAndReturnNull($loaded),
        );

        $restored = $this->repository()->get($activity->id);

        self::assertTrue(
            $restored->pausedAt?->equals(
                $this->instant('2026-01-15T10:01:00Z'),
            ),
        );
        self::assertSame(
            $claim->generationId->toString(),
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT active_import_generation_id::text
                    FROM activities
                    WHERE id = :id
                    SQL,
                ['id' => $activity->id->toString()],
            ),
        );
        self::assertSame(
            'active',
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT status
                    FROM activity_import_generations
                    WHERE id = :id
                    SQL,
                ['id' => $claim->generationId->toString()],
            ),
        );
        self::assertSame(
            2,
            (int) $this->connection->fetchOne(
                'SELECT version FROM activities WHERE id = :id',
                ['id' => $activity->id->toString()],
            ),
        );
    }

    public function testAggregateSaveRollsBackWhenActivationFails(): void
    {
        $activity = Activity::start(
            $this->instant('2026-01-15T10:00:00Z'),
        );
        $this->transaction->run(
            fn (): null => $this->saveAndReturnNull($activity),
        );
        $claim = $this->generations->claim(
            activityId: $activity->id,
            idempotencyKey: ActivityImportIdempotencyKey::fromString(
                'fit-file-rollback-test',
            ),
        );
        $loaded = $this->activities->get($activity->id);
        $loaded->pause(
            $this->instant('2026-01-15T10:01:00Z'),
        );
        $this->generations->fail($claim->generationId);

        $coordinator = new ActivityImportGenerationCoordinator(
            generations: $this->generations,
            transaction: $this->transaction,
        );

        try {
            $coordinator->activate(
                generationId: $claim->generationId,
                persistAggregate: fn (): null => $this->saveAndReturnNull($loaded),
            );

            self::fail('Expected activation to fail.');
        } catch (ActivityImportPersistenceException $exception) {
            self::assertStringContainsString(
                'staging was required',
                $exception->getMessage(),
            );
        }

        $restored = $this->repository()->get($activity->id);

        self::assertNull($restored->pausedAt);
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT version FROM activities WHERE id = :id',
                ['id' => $activity->id->toString()],
            ),
        );
        self::assertNull(
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT active_import_generation_id::text
                    FROM activities
                    WHERE id = :id
                    SQL,
                ['id' => $activity->id->toString()],
            ),
        );
    }

    private function repository(): DoctrineDbalActivityRepository
    {
        return new DoctrineDbalActivityRepository(
            transaction: $this->transaction,
            rows: new ActivityRowMapper(),
        );
    }

    private function saveAndReturnNull(Activity $activity): null
    {
        $this->activities->save($activity);

        return null;
    }

    private function completeActivity(): Activity
    {
        $activity = Activity::start(
            $this->instant('2026-01-15T10:00:00Z'),
        );
        $activity->pause(
            $this->instant('2026-01-15T10:05:00Z'),
        );
        $activity->resume(
            $this->instant('2026-01-15T10:06:00Z'),
        );
        $activity->recordObservation(
            ActivityObservation::create(
                timestamp: $this->instant(
                    '2026-01-15T10:10:00Z',
                ),
                measurements: new ScalarMeasurement(
                    measurementType: MeasurementType::fromString('heart_rate'),
                    value: 150,
                    unit: MeasurementUnit::fromSymbol('bpm'),
                ),
            ),
        );
        $activity->recordLap(
            Lap::create(
                startedAt: $this->instant(
                    '2026-01-15T10:00:00Z',
                ),
                finishedAt: $this->instant(
                    '2026-01-15T10:20:00Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_140_000_000,
                ),
            ),
        );
        $activity->recordDetail(
            PoolLength::create(
                interval: ActivityInterval::create(
                    startedAt: $this->instant(
                        '2026-01-15T10:20:00Z',
                    ),
                    finishedAt: $this->instant(
                        '2026-01-15T10:25:00Z',
                    ),
                    timerDuration: Duration::fromMicroseconds(
                        300_000_000,
                    ),
                ),
                type: PoolLengthType::Active,
                stroke: 'freestyle',
            ),
        );
        $activity->recordSession(
            ActivitySession::create(
                startedAt: $this->instant(
                    '2026-01-15T10:00:00Z',
                ),
                finishedAt: $this->instant(
                    '2026-01-15T10:30:00Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_740_000_000,
                ),
            ),
        );
        $activity->applySummary(
            ActivitySummary::create(
                reportedAt: $this->instant(
                    '2026-01-15T10:30:02Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_740_000_000,
                ),
                sessionCount: 1,
                type: 'pool_swimming',
                localTimeOffsetSeconds: 7_200,
            ),
        );

        return $activity;
    }

    private function resetDatabase(): void
    {
        PostgreSqlTestDatabase::truncate($this->connection);
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
