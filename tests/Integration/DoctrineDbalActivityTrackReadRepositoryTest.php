<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementOrigin;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\MeasurementSource;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\PositionMeasurement;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\SourceAttribution;
use Youmad\Endurance\Activity\ValueObject\ActivityDeviceId;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportGenerationRepository;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalStagedActivityObservationBatchWriter;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalStagedWriteExecutor;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityTrackReadRowMapper;
use Youmad\Endurance\ActivityPostgresql\Read\DoctrineDbalActivityTrackReadRepository;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;
use Youmad\Endurance\ActivityPostgresql\Tests\Support\PostgreSqlTestDatabase;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class DoctrineDbalActivityTrackReadRepositoryTest extends TestCase
{
    private Connection $connection;

    private DoctrineDbalActivityTrackReadRepository $track;

    private ActivityImportPayloadEncoder $payloads;

    public function testReadsOnlyActiveGenerationUsingStableCursorPagination(): void
    {
        $activityId = ActivityId::generate();
        $activeGeneration = ActivityImportGenerationId::generate();
        $stagingGeneration = ActivityImportGenerationId::generate();
        $this->insertActivity($activityId);
        $this->insertGeneration(
            activityId: $activityId,
            generationId: $activeGeneration,
            idempotencyKey: 'active-read-track',
            status: 'active',
        );
        $this->insertGeneration(
            activityId: $activityId,
            generationId: $stagingGeneration,
            idempotencyKey: 'staging-read-track',
            status: 'staging',
        );
        $this->activate($activityId, $activeGeneration);

        $this->insertObservation(
            activityId: $activityId,
            generationId: $activeGeneration,
            observation: $this->observation(
                timestamp: '2026-08-05T10:00:01Z',
                speed: 9.5,
                heartRate: 145,
            ),
        );
        $this->insertObservation(
            activityId: $activityId,
            generationId: $activeGeneration,
            observation: $this->observation(
                timestamp: '2026-08-05T10:00:00Z',
                speed: 8.0,
                heartRate: 130,
            ),
        );
        $this->insertObservation(
            activityId: $activityId,
            generationId: $activeGeneration,
            observation: $this->observation(
                timestamp: '2026-08-05T10:00:00Z',
                speed: 8.5,
                heartRate: 135,
            ),
        );
        $this->insertObservation(
            activityId: $activityId,
            generationId: $stagingGeneration,
            observation: $this->observation(
                timestamp: '2026-08-05T09:59:59Z',
                speed: 99.0,
                heartRate: 255,
            ),
        );

        $first = $this->track->findPage(
            activityId: $activityId,
            limit: 2,
            after: null,
        );

        self::assertNotNull($first);
        self::assertSame(2, $first->count());
        self::assertNotNull($first->nextCursor);
        self::assertSame(8.0, $first->points()[0]->speed?->value);
        self::assertSame(8.5, $first->points()[1]->speed?->value);
        self::assertSame(59.4369, $first->points()[0]->position?->latitude);

        $second = $this->track->findPage(
            activityId: $activityId,
            limit: 2,
            after: $first->nextCursor,
        );

        self::assertNotNull($second);
        self::assertSame(1, $second->count());
        self::assertNull($second->nextCursor);
        self::assertSame(9.5, $second->points()[0]->speed?->value);
        self::assertSame(145, $second->points()[0]->heartRate?->value);
        self::assertSame(
            'respiration_rate',
            $second->points()[0]->measurements()[2]->type,
        );
        self::assertSame(31.5, $second->points()[0]->measurements()[2]->value);

        self::assertTrue(
            $first->points()[1]
                ->cursor
                ->isBefore($second->points()[0]->cursor),
        );
    }

    public function testStagedWriteAndReadPreserveTwoSourcesOfTheSameMeasurement(): void
    {
        $activityId = ActivityId::generate();
        $firstDevice = ActivityDeviceId::generate();
        $secondDevice = ActivityDeviceId::generate();
        $this->insertActivity($activityId);
        $transaction = new DoctrineDbalActivityTransaction($this->connection);
        $generations = new DoctrineDbalActivityImportGenerationRepository($transaction);
        $claim = $generations->claim($activityId, ActivityImportIdempotencyKey::fromString('multiple-sources'));
        $writer = new DoctrineDbalStagedActivityObservationBatchWriter(new DoctrineDbalStagedWriteExecutor($transaction));
        $writer->appendBatch($claim->generationId, $activityId, [ActivityObservation::fromReadings(
            $this->instant('2026-08-05T10:00:00Z'),
            MeasurementReading::reported(
                new ScalarMeasurement(MeasurementType::fromString('heart_rate'), 140, MeasurementUnit::fromSymbol('bpm')),
                MeasurementSource::explicit($firstDevice),
            ),
            MeasurementReading::derived(
                new ScalarMeasurement(MeasurementType::fromString('heart_rate'), 145, MeasurementUnit::fromSymbol('bpm')),
                MeasurementSource::inferred($secondDevice),
            ),
        )]);
        $generations->activate($claim->generationId);

        $page = $this->track->findPage($activityId, 10, null);
        self::assertNotNull($page);
        self::assertSame(1, $page->count());
        $point = $page->points()[0];
        self::assertNull($point->heartRate);
        $readings = $point->measurements();
        self::assertCount(2, $readings);
        self::assertSame([140, 145], array_map(static fn ($reading) => $reading->value, $readings));
        self::assertSame(MeasurementOrigin::Reported, $readings[0]->origin);
        self::assertSame(MeasurementOrigin::Derived, $readings[1]->origin);
        self::assertSame($firstDevice->toString(), $readings[0]->source->deviceId?->toString());
        self::assertSame($secondDevice->toString(), $readings[1]->source->deviceId?->toString());
        self::assertSame(SourceAttribution::Explicit, $readings[0]->source->attribution);
        self::assertSame(SourceAttribution::Inferred, $readings[1]->source->attribution);
    }

    public function testDistinguishesMissingActivityFromActivityWithoutTrack(): void
    {
        self::assertNull(
            $this->track->findPage(
                activityId: ActivityId::generate(),
                limit: 100,
                after: null,
            ),
        );

        $activityId = ActivityId::generate();
        $generationId = ActivityImportGenerationId::generate();
        $this->insertActivity($activityId);
        $this->insertGeneration(
            activityId: $activityId,
            generationId: $generationId,
            idempotencyKey: 'empty-read-track',
            status: 'active',
        );
        $this->activate($activityId, $generationId);

        $page = $this->track->findPage(
            activityId: $activityId,
            limit: 100,
            after: null,
        );

        self::assertNotNull($page);
        self::assertSame(0, $page->count());
        self::assertSame([], $page->points());
        self::assertNull($page->nextCursor);
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

    private function insertObservation(
        ActivityId $activityId,
        ActivityImportGenerationId $generationId,
        ActivityObservation $observation,
    ): void {
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
                    :observed_at,
                    CAST(:payload AS jsonb)
                )
                SQL,
            [
                'generation_id' => $generationId->toString(),
                'activity_id' => $activityId->toString(),
                'observed_at' => $this->payloads->instant(
                    $observation->timestamp,
                ),
                'payload' => $this->payloads->observation($observation),
            ],
        );
    }

    private function observation(
        string $timestamp,
        float $speed,
        int $heartRate,
    ): ActivityObservation {
        return ActivityObservation::fromReadings(
            $this->instant($timestamp),
            MeasurementReading::reported(
                new PositionMeasurement(
                    new Coordinate(
                        latitude: 59.4369,
                        longitude: 24.7535,
                    ),
                ),
                MeasurementSource::unknown(),
            ),
            MeasurementReading::reported(
                new ScalarMeasurement(
                    MeasurementType::fromString('speed'),
                    $speed,
                    MeasurementUnit::fromSymbol('m/s'),
                ),
                MeasurementSource::unknown(),
            ),
            MeasurementReading::reported(
                new ScalarMeasurement(
                    MeasurementType::fromString('heart_rate'),
                    $heartRate,
                    MeasurementUnit::fromSymbol('bpm'),
                ),
                MeasurementSource::unknown(),
            ),
            MeasurementReading::reported(
                new ScalarMeasurement(
                    MeasurementType::fromString('respiration_rate'),
                    31.5,
                    MeasurementUnit::fromSymbol('breaths/min'),
                ),
                MeasurementSource::unknown(),
            ),
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
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
        $this->track = new DoctrineDbalActivityTrackReadRepository(
            connection: $this->connection,
            rows: new ActivityTrackReadRowMapper(),
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
