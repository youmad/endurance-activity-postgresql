<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Serialization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Detail\ActivityInterval;
use Youmad\Endurance\Activity\Detail\Pool\PoolLength;
use Youmad\Endurance\Activity\Detail\Pool\PoolLengthType;
use Youmad\Endurance\Activity\Detail\Segment\SegmentEffort;
use Youmad\Endurance\Activity\Device\ActivityDevice;
use Youmad\Endurance\Activity\Device\DeviceDescriptor;
use Youmad\Endurance\Activity\Device\DeviceStatusObservation;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\ArrayMeasurement;
use Youmad\Endurance\Activity\Telemetry\MeasurementMetadata;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\MeasurementSource;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\PositionMeasurement;
use Youmad\Endurance\Activity\Telemetry\RangeMeasurement;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\TextMeasurement;
use Youmad\Endurance\Activity\Telemetry\Vector3Measurement;
use Youmad\Endurance\Activity\ValueObject\ActivityDeviceId;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityImportPersistenceException;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class ActivityImportPayloadEncoderTest extends TestCase
{
    private ActivityImportPayloadEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new ActivityImportPayloadEncoder();
    }

    public function testEncodesEveryMeasurementShapeAndMetadata(): void
    {
        $deviceId = ActivityDeviceId::generate();
        $timestamp = $this->instant('2026-01-15T10:30:01.123456+00:00');
        $readings = [
            MeasurementReading::reported(
                new ScalarMeasurement(
                    MeasurementType::fromString('heart_rate'),
                    151,
                    MeasurementUnit::fromSymbol('bpm'),
                ),
                MeasurementSource::explicit($deviceId),
                new TestMetadata(
                    label: 'developer',
                    mode: TestMetadataMode::Detailed,
                    nested: new TestNestedMetadata(7),
                ),
            ),
            MeasurementReading::derived(
                new ArrayMeasurement(
                    MeasurementType::fromString('power_phase'),
                    [1.5, null, 3],
                    MeasurementUnit::fromSymbol('deg'),
                ),
                MeasurementSource::inferred($deviceId),
            ),
            MeasurementReading::reported(
                new RangeMeasurement(
                    MeasurementType::fromString('temperature_range'),
                    -2.5,
                    8.0,
                    MeasurementUnit::fromSymbol('°C'),
                ),
                MeasurementSource::unknown(),
            ),
            MeasurementReading::reported(
                new PositionMeasurement(
                    new Coordinate(59.437, 24.7536),
                ),
                MeasurementSource::unknown(),
            ),
            MeasurementReading::reported(
                new TextMeasurement(
                    MeasurementType::fromString('note'),
                    'steady',
                ),
                MeasurementSource::unknown(),
            ),
            MeasurementReading::reported(
                new Vector3Measurement(
                    MeasurementType::fromString('acceleration'),
                    1.0,
                    2.0,
                    3.0,
                    MeasurementUnit::fromSymbol('m/s²'),
                ),
                MeasurementSource::unknown(),
            ),
        ];

        $payload = $this->decode(
            $this->encoder->observation(
                ActivityObservation::fromReadings(
                    $timestamp,
                    ...$readings,
                ),
            ),
        );

        self::assertSame(
            '2026-01-15T10:30:01.123456Z',
            $payload['timestamp'],
        );
        self::assertSame(
            ['scalar', 'array', 'range', 'position', 'text', 'vector3'],
            array_map(
                static fn (array $reading): string => $reading['measurement']['kind'],
                $payload['readings'],
            ),
        );
        self::assertSame([
            'type' => 'test.developer',
            'version' => 1,
            'data' => [
                'label' => 'developer',
                'mode' => 'detailed',
                'nested' => ['number' => 7],
            ],
        ], $payload['readings'][0]['metadata']);
        self::assertNull($payload['readings'][1]['metadata']);
    }

    /** @return iterable<string, array{string, int, array<mixed>}> */
    public static function invalidMetadata(): iterable
    {
        yield 'empty type' => ['', 1, []];
        yield 'blank type' => ['  ', 1, []];
        yield 'zero version' => ['test.invalid', 0, []];
        yield 'negative version' => ['test.invalid', -1, []];
        yield 'unnamed fields' => ['test.invalid', 1, [42]];
        yield 'nested object' => ['test.invalid', 1, ['nested' => [new TestNestedMetadata(1)]]];
        yield 'unconverted enum' => ['test.invalid', 1, ['mode' => TestMetadataMode::Detailed]];
        yield 'infinite number' => ['test.invalid', 1, ['value' => INF]];
        yield 'not a number' => ['test.invalid', 1, ['value' => NAN]];
        $deep = null;
        for ($index = 0; $index < 66; ++$index) {
            $deep = [$deep];
        }
        yield 'excessive nesting' => ['test.invalid', 1, ['nested' => $deep]];
    }

    /** @param array<mixed> $data */
    #[DataProvider('invalidMetadata')]
    public function testRejectsInvalidMetadataContract(string $type, int $version, array $data): void
    {
        $metadata = $this->createStub(MeasurementMetadata::class);
        $metadata->method('metadataType')->willReturn($type);
        $metadata->method('metadataVersion')->willReturn($version);
        $metadata->method('metadataData')->willReturn($data);

        $this->expectException(ActivityImportPersistenceException::class);
        $this->encoder->observation(ActivityObservation::fromReadings(
            $this->instant('2026-01-15T10:30:00Z'),
            MeasurementReading::reported(
                new ScalarMeasurement(MeasurementType::fromString('external'), 1, MeasurementUnit::none()),
                MeasurementSource::unknown(),
                $metadata,
            ),
        ));
    }

    public function testEncodesAllLowVolumeImportPayloads(): void
    {
        $startedAt = $this->instant('2026-01-15T10:30:00Z');
        $finishedAt = $this->instant('2026-01-15T10:35:00Z');
        $timer = Duration::fromMicroseconds(280_000_000);
        $interval = ActivityInterval::create(
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            timerDuration: $timer,
        );
        $reading = MeasurementReading::reported(
            new ScalarMeasurement(
                MeasurementType::fromString('average_heart_rate'),
                145,
                MeasurementUnit::fromSymbol('bpm'),
            ),
            MeasurementSource::unknown(),
        );

        $lap = Lap::create(
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            timerDuration: $timer,
            readings: [$reading],
            timelineResolution: TemporalResolution::Second,
        );
        $session = ActivitySession::create(
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            timerDuration: $timer,
            sport: 'cycling',
            startPosition: new Coordinate(59.4, 24.7),
            endPosition: new Coordinate(59.5, 24.8),
            readings: [$reading],
            timelineResolution: TemporalResolution::Second,
        );
        $poolLength = PoolLength::create(
            interval: $interval,
            type: PoolLengthType::Active,
            stroke: 'freestyle',
            readings: [$reading],
            timelineResolution: TemporalResolution::Second,
        );
        $segment = SegmentEffort::create(
            interval: $interval,
            segmentId: 'segment-42',
            name: 'Climb',
            status: 'completed',
            sport: 'cycling',
            manufacturer: 'garmin',
            readings: [$reading],
        );
        $device = ActivityDevice::described(
            ActivityDeviceId::generate(),
            DeviceDescriptor::create(
                manufacturer: 'Garmin',
                product: 'Edge',
                serialNumber: '123',
            ),
        );
        $status = DeviceStatusObservation::at(
            $device->id,
            $startedAt,
            new ScalarMeasurement(
                MeasurementType::fromString('battery_level'),
                88,
                MeasurementUnit::fromSymbol('%'),
            ),
        );

        $lapPayload = $this->decode($this->encoder->lap($lap));

        self::assertSame(
            280_000_000,
            $lapPayload['timer_duration_microseconds'],
        );
        self::assertSame(
            1_000_000,
            $lapPayload['timeline_resolution_microseconds'],
        );
        $sessionPayload = $this->decode(
            $this->encoder->session($session),
        );
        self::assertSame('cycling', $sessionPayload['sport']);
        self::assertSame(
            1_000_000,
            $sessionPayload['timeline_resolution_microseconds'],
        );
        $poolLengthPayload = $this->decode(
            $this->encoder->detail($poolLength),
        );
        self::assertSame(
            'pool_length',
            $poolLengthPayload['detail_type'],
        );
        self::assertSame(
            1_000_000,
            $poolLengthPayload['timeline_resolution_microseconds'],
        );
        self::assertSame(
            'segment_effort',
            $this->decode($this->encoder->detail($segment))[
                'detail_type'
            ],
        );
        self::assertSame(
            'Garmin',
            $this->decode($this->encoder->device($device))[
                'descriptor'
            ]['manufacturer'],
        );
        self::assertSame(
            88,
            $this->decode($this->encoder->deviceStatus($status))[
                'measurements'
            ][0]['value'],
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        return json_decode(
            $json,
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}

final readonly class TestMetadata implements MeasurementMetadata
{
    public function __construct(
        private string $label,
        private TestMetadataMode $mode,
        private TestNestedMetadata $nested,
    ) {
    }

    public function metadataType(): string
    {
        return 'test.developer';
    }

    public function metadataVersion(): int
    {
        return 1;
    }

    public function metadataData(): array
    {
        return [
            'label' => $this->label,
            'mode' => $this->mode->value,
            'nested' => ['number' => $this->nested->number],
        ];
    }
}

enum TestMetadataMode: string
{
    case Detailed = 'detailed';
}

final readonly class TestNestedMetadata
{
    public function __construct(
        public int $number,
    ) {
    }
}
