<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Detail\ActivityInterval;
use Youmad\Endurance\Activity\Detail\Pool\PoolLength;
use Youmad\Endurance\Activity\Detail\Pool\PoolLengthType;
use Youmad\Endurance\Activity\Device\ActivityDevice;
use Youmad\Endurance\Activity\Device\DeviceDescriptor;
use Youmad\Endurance\Activity\Device\DeviceStatusObservation;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\ValueObject\ActivityDeviceId;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityImportPersistenceException;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalActivityImportGenerationRepository;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalFailedActivityImportCleaner;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalStagedActivityDetailWriter;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalStagedActivityDeviceWriter;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalStagedActivityObservationBatchWriter;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalStagedActivitySessionWriter;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalStagedDeviceStatusObservationBatchWriter;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalStagedLapWriter;
use Youmad\Endurance\ActivityPostgresql\Import\DoctrineDbalStagedWriteExecutor;
use Youmad\Endurance\ActivityPostgresql\Tests\Support\PostgreSqlTestDatabase;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class DoctrineDbalActivityImportStorageTest extends TestCase
{
    private Connection $connection;

    private DoctrineDbalActivityImportGenerationRepository $generations;

    private DoctrineDbalFailedActivityImportCleaner $failedImports;

    private DoctrineDbalStagedActivityObservationBatchWriter $observations;

    private DoctrineDbalStagedDeviceStatusObservationBatchWriter $deviceStatuses;

    private DoctrineDbalStagedLapWriter $laps;

    private DoctrineDbalStagedActivityDetailWriter $details;

    private DoctrineDbalStagedActivitySessionWriter $sessions;

    private DoctrineDbalStagedActivityDeviceWriter $devices;

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

        $transaction = new DoctrineDbalActivityTransaction(
            $this->connection,
        );
        $writes = new DoctrineDbalStagedWriteExecutor($transaction);
        $this->generations =
            new DoctrineDbalActivityImportGenerationRepository(
                $transaction,
            );
        $this->failedImports =
            new DoctrineDbalFailedActivityImportCleaner(
                $transaction,
            );
        $this->observations =
            new DoctrineDbalStagedActivityObservationBatchWriter(
                $writes,
            );
        $this->deviceStatuses =
            new DoctrineDbalStagedDeviceStatusObservationBatchWriter(
                $writes,
            );
        $this->laps = new DoctrineDbalStagedLapWriter($writes);
        $this->details =
            new DoctrineDbalStagedActivityDetailWriter($writes);
        $this->sessions =
            new DoctrineDbalStagedActivitySessionWriter($writes);
        $this->devices =
            new DoctrineDbalStagedActivityDeviceWriter($writes);
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

    public function testStagesEveryRelationAndActivatesAtomically(): void
    {
        $activityId = $this->createActivity();
        $key = ActivityImportIdempotencyKey::fromString(
            'object-storage:activities/42.fit:version-1',
        );
        $claim = $this->generations->claim($activityId, $key);

        self::assertTrue($claim->isAcquired());

        $startedAt = $this->instant('2026-01-15T10:30:00Z');
        $finishedAt = $this->instant('2026-01-15T10:35:00Z');
        $measurement = new ScalarMeasurement(
            MeasurementType::fromString('heart_rate'),
            150,
            MeasurementUnit::fromSymbol('bpm'),
        );
        $timer = Duration::fromMicroseconds(280_000_000);
        $device = ActivityDevice::described(
            ActivityDeviceId::generate(),
            DeviceDescriptor::create(
                manufacturer: 'Garmin',
                product: 'Edge',
            ),
        );

        $this->observations->appendBatch(
            $claim->generationId,
            $activityId,
            [ActivityObservation::create($startedAt, $measurement)],
        );
        $this->deviceStatuses->appendBatch(
            $claim->generationId,
            $activityId,
            [
                DeviceStatusObservation::at(
                    $device->id,
                    $startedAt,
                    new ScalarMeasurement(
                        MeasurementType::fromString('battery_level'),
                        90,
                        MeasurementUnit::fromSymbol('%'),
                    ),
                ),
            ],
        );
        $this->laps->append(
            $claim->generationId,
            $activityId,
            Lap::create(
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                timerDuration: $timer,
            ),
        );
        $this->details->append(
            $claim->generationId,
            $activityId,
            PoolLength::create(
                interval: ActivityInterval::create(
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                    timerDuration: $timer,
                ),
                type: PoolLengthType::Idle,
            ),
        );
        $this->sessions->append(
            $claim->generationId,
            $activityId,
            ActivitySession::create(
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                timerDuration: $timer,
                sport: 'cycling',
            ),
        );
        $this->devices->save(
            $claim->generationId,
            $activityId,
            $device,
        );

        foreach ($this->activeViews() as $view) {
            self::assertSame(0, $this->countRows($view));
        }

        $this->generations->activate($claim->generationId);

        foreach ($this->activeViews() as $view) {
            self::assertSame(1, $this->countRows($view), $view);
        }

        $duplicate = $this->generations->claim($activityId, $key);

        self::assertFalse($duplicate->isAcquired());
        self::assertTrue(
            $duplicate->generationId->equals($claim->generationId),
        );

        $this->expectException(
            ActivityImportPersistenceException::class,
        );
        $this->generations->claim(
            $activityId,
            ActivityImportIdempotencyKey::fromString(
                'object-storage:activities/42.fit:version-2',
            ),
        );
    }

    public function testFailedGenerationIsInvisibleAndReclaimedCleanly(): void
    {
        $activityId = $this->createActivity();
        $key = ActivityImportIdempotencyKey::fromString('retryable-fit');
        $claim = $this->generations->claim($activityId, $key);
        $observation = ActivityObservation::create(
            $this->instant('2026-01-15T10:30:00Z'),
            new ScalarMeasurement(
                MeasurementType::fromString('heart_rate'),
                150,
                MeasurementUnit::fromSymbol('bpm'),
            ),
        );

        $this->observations->appendBatch(
            $claim->generationId,
            $activityId,
            [$observation],
        );
        $this->generations->fail($claim->generationId);

        self::assertSame(
            1,
            $this->countRows('activity_import_observations'),
        );
        self::assertSame(
            0,
            $this->countRows('active_activity_import_observations'),
        );

        $reclaimed = $this->generations->claim($activityId, $key);

        self::assertTrue($reclaimed->isAcquired());
        self::assertFalse(
            $reclaimed->generationId->equals($claim->generationId),
        );
        self::assertSame(
            0,
            $this->countRows('activity_import_observations'),
        );

        try {
            $this->observations->appendBatch(
                $claim->generationId,
                $activityId,
                [$observation],
            );
            self::fail('Expected the previous generation id to be fenced.');
        } catch (ActivityImportPersistenceException) {
        }

        try {
            $this->generations->activate($claim->generationId);
            self::fail('Expected fenced generation activation to fail.');
        } catch (ActivityImportPersistenceException) {
        }

        try {
            $this->generations->fail($claim->generationId);
            self::fail('Expected fenced generation finalization to fail.');
        } catch (ActivityImportPersistenceException) {
        }

        self::assertSame(
            2,
            (int) $this->connection->fetchOne(
                'SELECT attempt_count FROM activity_import_generations',
            ),
        );
    }

    public function testDiscardsActivityOwnedOnlyByFailedImport(): void
    {
        $activityId = $this->createActivity();
        $key = ActivityImportIdempotencyKey::fromString(
            '019facd0-c260-7f33-a6d5-97d13ac69b79',
        );
        $claim = $this->generations->claim($activityId, $key);
        $this->observations->appendBatch(
            $claim->generationId,
            $activityId,
            [
                ActivityObservation::create(
                    $this->instant('2026-01-15T10:30:00Z'),
                    new ScalarMeasurement(
                        MeasurementType::fromString('heart_rate'),
                        150,
                        MeasurementUnit::fromSymbol('bpm'),
                    ),
                ),
            ],
        );
        $this->generations->fail($claim->generationId);

        self::assertTrue(
            $this->failedImports->discard($activityId, $key),
        );
        self::assertSame(0, $this->countRows('activities'));
        self::assertSame(
            0,
            $this->countRows('activity_import_generations'),
        );
        self::assertSame(
            0,
            $this->countRows('activity_import_observations'),
        );
    }

    public function testDoesNotDiscardActivityWithActiveImport(): void
    {
        $activityId = $this->createActivity();
        $key = ActivityImportIdempotencyKey::fromString(
            '019facd0-c260-7f33-a6d5-97d13ac69b79',
        );
        $claim = $this->generations->claim($activityId, $key);
        $this->generations->activate($claim->generationId);

        self::assertFalse(
            $this->failedImports->discard($activityId, $key),
        );
        self::assertSame(1, $this->countRows('activities'));
        self::assertSame(
            1,
            $this->countRows('activity_import_generations'),
        );
    }

    public function testRejectsWritesAfterGenerationFailed(): void
    {
        $activityId = $this->createActivity();
        $claim = $this->generations->claim(
            $activityId,
            ActivityImportIdempotencyKey::fromString('failed-write'),
        );
        $this->generations->fail($claim->generationId);

        $this->expectException(
            ActivityImportPersistenceException::class,
        );

        $this->observations->appendBatch(
            $claim->generationId,
            $activityId,
            [
                ActivityObservation::create(
                    $this->instant('2026-01-15T10:30:00Z'),
                    new ScalarMeasurement(
                        MeasurementType::fromString('heart_rate'),
                        150,
                        MeasurementUnit::fromSymbol('bpm'),
                    ),
                ),
            ],
        );
    }

    private function resetDatabase(): void
    {
        PostgreSqlTestDatabase::truncate($this->connection);
    }

    private function createActivity(): ActivityId
    {
        $activityId = ActivityId::generate();
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO activities (
                    id,
                    started_at,
                    latest_timestamp
                ) VALUES (
                    :id,
                    :started_at,
                    :started_at
                )
                SQL,
            [
                'id' => $activityId->toString(),
                'started_at' => '2026-01-15T10:30:00.000000+00:00',
            ],
        );

        return $activityId;
    }

    private function countRows(string $relation): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT count(*) FROM %s', $relation),
        );
    }

    /** @return list<string> */
    private function activeViews(): array
    {
        return [
            'active_activity_import_observations',
            'active_activity_import_device_status_observations',
            'active_activity_import_laps',
            'active_activity_import_details',
            'active_activity_import_sessions',
            'active_activity_import_devices',
        ];
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
