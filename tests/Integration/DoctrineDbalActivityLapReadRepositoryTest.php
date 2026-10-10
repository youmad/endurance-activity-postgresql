<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\MeasurementSource;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityLapReadRowMapper;
use Youmad\Endurance\ActivityPostgresql\Read\DoctrineDbalActivityLapReadRepository;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;
use Youmad\Endurance\ActivityPostgresql\Tests\Support\PostgreSqlTestDatabase;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class DoctrineDbalActivityLapReadRepositoryTest extends TestCase
{
    private Connection $connection;

    private DoctrineDbalActivityLapReadRepository $laps;

    private ActivityImportPayloadEncoder $payloads;

    public function testReadsOnlyLapsOfActiveGenerationInStableOrder(): void
    {
        $activityId = ActivityId::generate();
        $activeGeneration = ActivityImportGenerationId::generate();
        $stagingGeneration = ActivityImportGenerationId::generate();
        $this->insertActivity($activityId);
        $this->insertGeneration(
            activityId: $activityId,
            generationId: $activeGeneration,
            idempotencyKey: 'active-read-laps',
            status: 'active',
        );
        $this->insertGeneration(
            activityId: $activityId,
            generationId: $stagingGeneration,
            idempotencyKey: 'staging-read-laps',
            status: 'staging',
        );
        $this->activate($activityId, $activeGeneration);

        $this->insertLap(
            activityId: $activityId,
            generationId: $activeGeneration,
            lap: $this->lap(
                startedAt: '2026-08-05T10:30:00Z',
                finishedAt: '2026-08-05T11:00:00Z',
                timerMicroseconds: 1_700_000_000,
                distance: 14_754.05,
                adjacencyPolicy: SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds,
            ),
        );
        $this->insertLap(
            activityId: $activityId,
            generationId: $activeGeneration,
            lap: $this->lap(
                startedAt: '2026-08-05T10:00:00Z',
                finishedAt: '2026-08-05T10:30:00Z',
                timerMicroseconds: 1_710_000_000,
                distance: 14_000.25,
                adjacencyPolicy: SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds,
            ),
        );
        $this->insertLap(
            activityId: $activityId,
            generationId: $stagingGeneration,
            lap: $this->lap(
                startedAt: '2026-08-05T10:00:00Z',
                finishedAt: '2026-08-05T11:00:00Z',
                timerMicroseconds: 3_420_000_000,
                distance: 99_999.0,
                adjacencyPolicy: SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds,
            ),
        );

        $laps = $this->laps->findForActivity($activityId);

        self::assertNotNull($laps);
        self::assertSame(SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds, $laps->laps()[0]->adjacencyPolicy);
        self::assertSame(SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds, $laps->laps()[1]->adjacencyPolicy);
        self::assertSame(2, $laps->count());
        self::assertSame(0, $laps->laps()[0]->index);
        self::assertTrue(
            $this->instant('2026-08-05T10:00:00Z')->equals(
                $laps->laps()[0]->startedAt,
            ),
        );
        self::assertSame(
            14_000.25,
            $laps->laps()[0]->distance?->value,
        );
        self::assertSame(
            14_754.05,
            $laps->laps()[1]->distance?->value,
        );
        self::assertSame(
            'average_heart_rate',
            $laps->laps()[0]->measurements()[1]->type,
        );
        self::assertSame(149, $laps->laps()[0]->measurements()[1]->value);
    }

    public function testReadsLapsWithSubResolutionOverlap(): void
    {
        $activityId = ActivityId::generate();
        $generationId = ActivityImportGenerationId::generate();
        $this->insertActivity($activityId);
        $this->insertGeneration(
            activityId: $activityId,
            generationId: $generationId,
            idempotencyKey: 'sub-resolution-overlap',
            status: 'active',
        );
        $this->activate($activityId, $generationId);

        $this->insertLap(
            activityId: $activityId,
            generationId: $generationId,
            lap: $this->lap(
                startedAt: '2026-08-05T10:00:00Z',
                finishedAt: '2026-08-05T10:30:00.551000Z',
                timerMicroseconds: 1_800_000_000,
                distance: 14_000.0,
                timelineResolution: TemporalResolution::Second,
            ),
        );
        $this->insertLap(
            activityId: $activityId,
            generationId: $generationId,
            lap: $this->lap(
                startedAt: '2026-08-05T10:30:00Z',
                finishedAt: '2026-08-05T11:00:00Z',
                timerMicroseconds: 1_800_000_000,
                distance: 14_500.0,
                timelineResolution: TemporalResolution::Second,
            ),
        );

        $laps = $this->laps->findForActivity($activityId);

        self::assertNotNull($laps);
        self::assertSame(2, $laps->count());
        self::assertSame(
            TemporalResolution::Second,
            $laps->laps()[1]->timelineResolution,
        );
    }

    public function testRoundTripsIndependentLapDurations(): void
    {
        $activityId = ActivityId::generate();
        $generationId = ActivityImportGenerationId::generate();
        $this->insertActivity($activityId);
        $this->insertGeneration(
            activityId: $activityId,
            generationId: $generationId,
            idempotencyKey: 'independent-lap-durations',
            status: 'active',
        );
        $this->activate($activityId, $generationId);
        $this->insertLap(
            activityId: $activityId,
            generationId: $generationId,
            lap: $this->lap(
                startedAt: '2026-08-05T10:00:00Z',
                finishedAt: '2026-08-05T10:01:59Z',
                timerMicroseconds: 120_000_000,
                distance: 400.0,
                timelineResolution: TemporalResolution::Second,
            ),
        );

        $laps = $this->laps->findForActivity($activityId);
        self::assertNotNull($laps);
        self::assertSame(119_000_000, $laps->laps()[0]->elapsedDuration()->toMicroseconds());
        self::assertSame(120_000_000, $laps->laps()[0]->timerDuration->toMicroseconds());
    }

    private function insertActivity(ActivityId $activityId): void
    {
        $startedAt = $this->instant('2026-08-05T10:00:00Z');
        $finishedAt = $this->instant('2026-08-05T11:00:00Z');

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO activities (
                    id,
                    started_at,
                    finished_at,
                    latest_timestamp
                ) VALUES (
                    :id,
                    :started_at,
                    :finished_at,
                    :finished_at
                )
                SQL,
            [
                'id' => $activityId->toString(),
                'started_at' => $this->payloads->instant($startedAt),
                'finished_at' => $this->payloads->instant($finishedAt),
            ],
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }

    private function insertGeneration(
        ActivityId $activityId,
        ActivityImportGenerationId $generationId,
        string $idempotencyKey,
        string $status,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO activity_import_generations (
                id,
                activity_id,
                idempotency_key,
                status,
                activated_at
            ) VALUES (
                :id,
                :activity_id,
                :idempotency_key,
                :status,
                CASE
                    WHEN :is_active THEN clock_timestamp()
                    ELSE NULL
                END
            )
            SQL,
            [
                'id' => $generationId->toString(),
                'activity_id' => $activityId->toString(),
                'idempotency_key' => $idempotencyKey,
                'status' => $status,
                'is_active' => 'active' === $status,
            ],
            [
                'is_active' => ParameterType::BOOLEAN,
            ],
        );
    }

    private function activate(
        ActivityId $activityId,
        ActivityImportGenerationId $generationId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE activities
                SET active_import_generation_id = :generation_id
                WHERE id = :activity_id
                SQL,
            [
                'generation_id' => $generationId->toString(),
                'activity_id' => $activityId->toString(),
            ],
        );
    }

    private function insertLap(
        ActivityId $activityId,
        ActivityImportGenerationId $generationId,
        Lap $lap,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO activity_import_laps (
                    import_generation_id,
                    activity_id,
                    started_at,
                    finished_at,
                    timer_duration_microseconds,
                    payload
                ) VALUES (
                    :generation_id,
                    :activity_id,
                    :started_at,
                    :finished_at,
                    :timer_duration,
                    CAST(:payload AS jsonb)
                )
                SQL,
            [
                'generation_id' => $generationId->toString(),
                'activity_id' => $activityId->toString(),
                'started_at' => $this->payloads->instant($lap->startedAt),
                'finished_at' => $this->payloads->instant($lap->finishedAt),
                'timer_duration' => $lap
                    ->timerDuration
                    ->toMicroseconds(),
                'payload' => $this->payloads->lap($lap),
            ],
        );
    }

    private function lap(
        string $startedAt,
        string $finishedAt,
        int $timerMicroseconds,
        float $distance,
        TemporalResolution $timelineResolution =
            TemporalResolution::Microsecond,
        ?SummaryAdjacencyPolicy $adjacencyPolicy = null,
    ): Lap {
        return Lap::create(
            adjacencyPolicy: $adjacencyPolicy,
            startedAt: $this->instant($startedAt),
            finishedAt: $this->instant($finishedAt),
            timerDuration: Duration::fromMicroseconds(
                $timerMicroseconds,
            ),
            timelineResolution: $timelineResolution,
            readings: [
                MeasurementReading::reported(
                    new ScalarMeasurement(
                        MeasurementType::fromString(
                            'total_distance',
                        ),
                        $distance,
                        MeasurementUnit::fromSymbol('m'),
                    ),
                    MeasurementSource::unknown(),
                ),
                MeasurementReading::reported(
                    new ScalarMeasurement(
                        MeasurementType::fromString(
                            'average_heart_rate',
                        ),
                        149,
                        MeasurementUnit::fromSymbol('bpm'),
                    ),
                    MeasurementSource::unknown(),
                ),
            ],
        );
    }

    public function testDistinguishesMissingActivityFromActivityWithoutLaps(): void
    {
        self::assertNull(
            $this->laps->findForActivity(ActivityId::generate()),
        );

        $activityId = ActivityId::generate();
        $generationId = ActivityImportGenerationId::generate();
        $this->insertActivity($activityId);
        $this->insertGeneration(
            activityId: $activityId,
            generationId: $generationId,
            idempotencyKey: 'empty-read-laps',
            status: 'active',
        );
        $this->activate($activityId, $generationId);

        $laps = $this->laps->findForActivity($activityId);

        self::assertNotNull($laps);
        self::assertSame(0, $laps->count());
        self::assertSame([], $laps->laps());
    }

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
        $this->payloads = new ActivityImportPayloadEncoder();
        $this->laps = new DoctrineDbalActivityLapReadRepository(
            connection: $this->connection,
            rows: new ActivityLapReadRowMapper(),
        );
    }

    private function resetDatabase(): void
    {
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
}
