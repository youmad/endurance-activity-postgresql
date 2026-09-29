<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\MeasurementSource;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityReadRowMapper;
use Youmad\Endurance\ActivityPostgresql\Read\DoctrineDbalActivityReadRepository;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;
use Youmad\Endurance\ActivityPostgresql\Tests\Support\PostgreSqlTestDatabase;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class DoctrineDbalActivityReadRepositoryTest extends TestCase
{
    private Connection $connection;

    private DoctrineDbalActivityReadRepository $activities;

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
        $this->resetDatabase();
        $this->activities = new DoctrineDbalActivityReadRepository(
            connection: $this->connection,
            rows: new ActivityReadRowMapper(),
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

    public function testReadsOnlyActiveGenerationWithoutRestoringAggregate(): void
    {
        $activityId = ActivityId::generate();
        $generationId = ActivityImportGenerationId::generate();
        $startedAt = $this->instant('2026-08-05T10:00:00Z');
        $finishedAt = $this->instant('2026-08-05T11:00:00Z');
        $timerDuration = Duration::fromMicroseconds(
            3_420_000_000,
        );
        $session = ActivitySession::create(
            adjacencyPolicy: SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            timerDuration: $timerDuration,
            sport: 'cycling',
            subSport: 'road',
            startPosition: new Coordinate(59.4, 24.7),
            endPosition: new Coordinate(59.5, 24.8),
            readings: [
                MeasurementReading::reported(
                    new ScalarMeasurement(
                        MeasurementType::fromString('total_distance'),
                        28_754.3,
                        MeasurementUnit::fromSymbol('m'),
                    ),
                    MeasurementSource::unknown(),
                ),
                MeasurementReading::reported(
                    new ScalarMeasurement(
                        MeasurementType::fromString('average_heart_rate'),
                        151,
                        MeasurementUnit::fromSymbol('bpm'),
                    ),
                    MeasurementSource::unknown(),
                ),
            ],
        );
        $payloads = new ActivityImportPayloadEncoder();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO activities (
                    id,
                    started_at,
                    finished_at,
                    last_session_finished_at,
                    last_session_timeline_finished_at,
                    session_count,
                    recorded_session_timer_duration_microseconds,
                    activity_type,
                    local_time_offset_seconds,
                    accumulated_paused_duration_microseconds,
                    latest_timestamp
                ) VALUES (
                    :id,
                    :started_at,
                    :finished_at,
                    :finished_at,
                    :finished_at,
                    1,
                    :timer_duration,
                    'cycling',
                    10_800,
                    180000000,
                    :finished_at
                )
                SQL,
            [
                'id' => $activityId->toString(),
                'started_at' => $payloads->instant($startedAt),
                'finished_at' => $payloads->instant($finishedAt),
                'timer_duration' => $timerDuration->toMicroseconds(),
            ],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO activity_import_generations (
                    id,
                    activity_id,
                    idempotency_key,
                    status
                ) VALUES (
                    :id,
                    :activity_id,
                    'read-api-test',
                    'staging'
                )
                SQL,
            [
                'id' => $generationId->toString(),
                'activity_id' => $activityId->toString(),
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
                    :started_at,
                    :finished_at,
                    :timer_duration,
                    CAST(:payload AS jsonb)
                )
                SQL,
            [
                'generation_id' => $generationId->toString(),
                'activity_id' => $activityId->toString(),
                'started_at' => $payloads->instant($startedAt),
                'finished_at' => $payloads->instant($finishedAt),
                'timer_duration' => $timerDuration->toMicroseconds(),
                'payload' => $payloads->session($session),
            ],
        );

        self::assertNull($this->activities->find($activityId));

        $this->connection->transactional(
            function () use ($activityId, $generationId): void {
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE activity_import_generations
                        SET
                            status = 'active',
                            activated_at = clock_timestamp(),
                            updated_at = clock_timestamp()
                        WHERE id = :id
                        SQL,
                    [
                        'id' => $generationId->toString(),
                    ],
                );
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
            },
        );

        $activity = (new DoctrineDbalActivityReadRepository(
            connection: $this->connection,
            rows: new ActivityReadRowMapper(),
        ))->find($activityId);

        self::assertNotNull($activity);
        self::assertTrue($activityId->equals($activity->id));
        self::assertSame('cycling', $activity->type);
        self::assertSame(10_800, $activity->localTimeOffsetSeconds);
        self::assertSame(1, $activity->sessionCount());
        self::assertSame(
            3_600_000_000,
            $activity->elapsedDuration()?->toMicroseconds(),
        );
        self::assertSame(
            3_420_000_000,
            $activity->timerDuration->toMicroseconds(),
        );
        self::assertSame(28_754.3, $activity->distance?->value);
        self::assertSame('road', $activity->sessions()[0]->subSport);
        self::assertSame(SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds, $activity->sessions()[0]->adjacencyPolicy);
        self::assertSame(
            'average_heart_rate',
            $activity->sessions()[0]->measurements()[1]->type,
        );
        self::assertSame(
            151,
            $activity->sessions()[0]->measurements()[1]->value,
        );
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
