<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Read;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementOrigin;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\MeasurementSource;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\SourceAttribution;
use Youmad\Endurance\Activity\ValueObject\ActivityDeviceId;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityScalarMeasurementReadRowMapper;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityTrackReadRowMapper;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class ActivityScalarMeasurementReadRowMapperTest extends TestCase
{
    public function testObservationPayloadRoundTripPreservesReadingsAndProvenance(): void
    {
        $first = ActivityDeviceId::generate();
        $second = ActivityDeviceId::generate();
        $at = Instant::fromDateTimeImmutable(new \DateTimeImmutable('2026-08-05T10:00:00Z'));
        $observation = ActivityObservation::fromReadings(
            $at,
            MeasurementReading::reported($this->scalar('heart_rate', 140, 'bpm'), MeasurementSource::explicit($first)),
            MeasurementReading::derived($this->scalar('heart_rate', 145, 'bpm'), MeasurementSource::inferred($second)),
            MeasurementReading::reported($this->scalar('speed', 8, 'm/s'), MeasurementSource::unknown()),
            MeasurementReading::reported($this->scalar('altitude', 100, 'm'), MeasurementSource::unknown()),
            MeasurementReading::reported($this->scalar('altitude', 300, 'ft'), MeasurementSource::unknown()),
        );
        $payloads = new ActivityImportPayloadEncoder();
        $page = (new ActivityTrackReadRowMapper())->page(ActivityId::generate(), [[
            'id' => 1,
            'observed_at' => $payloads->instant($at),
            'payload' => $payloads->observation($observation),
        ]], 10);
        $point = $page->points()[0];
        $readings = $point->measurements();

        self::assertCount(5, $readings);
        self::assertSame([140, 145, 8, 100, 300], array_map(static fn ($reading) => $reading->value, $readings));
        self::assertNull($point->heartRate);
        self::assertNull($point->altitude);
        self::assertSame($readings[2], $point->speed);
        self::assertSame(MeasurementOrigin::Reported, $readings[0]->origin);
        self::assertSame(MeasurementOrigin::Derived, $readings[1]->origin);
        self::assertSame($first->toString(), $readings[0]->source->deviceId?->toString());
        self::assertSame($second->toString(), $readings[1]->source->deviceId?->toString());
        self::assertSame(SourceAttribution::Explicit, $readings[0]->source->attribution);
        self::assertSame(SourceAttribution::Inferred, $readings[1]->source->attribution);
        self::assertSame(SourceAttribution::Unknown, $readings[2]->source->attribution);
        self::assertSame(['m', 'ft'], [$readings[3]->unit, $readings[4]->unit]);
    }

    public function testLegacyReadingsDoNotAcquireInventedProvenance(): void
    {
        $readings = (new ActivityScalarMeasurementReadRowMapper())->readings([$this->reading()], 'Track');
        self::assertCount(1, $readings);
        self::assertNull($readings[0]->origin);
        self::assertNull($readings[0]->source->deviceId);
        self::assertSame(SourceAttribution::Unknown, $readings[0]->source->attribution);
    }

    public function testMetadataEnvelopesDoNotChangeScalarReading(): void
    {
        $mapper = new ActivityScalarMeasurementReadRowMapper();
        $expected = $mapper->readings([$this->reading()], 'Track');
        foreach ([
            null,
            ['class' => 'Legacy\\Metadata', 'properties' => ['fieldName' => 'pulse']],
            ['type' => 'fit.developer_field', 'version' => 1, 'data' => ['field_name' => 'pulse']],
            ['type' => 'future.metadata', 'version' => 99, 'data' => []],
        ] as $metadata) {
            self::assertEquals(
                $expected,
                $mapper->readings([$this->reading() + ['metadata' => $metadata]], 'Track'),
            );
        }
    }

    public function testSummaryMappingStillRejectsRepeatedTypes(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('more than once');
        (new ActivityScalarMeasurementReadRowMapper())->map([$this->reading(), $this->reading()], 'Session');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidProvenance(): iterable
    {
        yield 'invalid origin' => [['origin' => 'invented']];
        yield 'null origin' => [['origin' => null]];
        yield 'scalar source' => [['source' => 'device']];
        yield 'missing source fields' => [['source' => ['attribution' => 'unknown']]];
        yield 'unsupported attribution' => [['source' => ['device_id' => null, 'attribution' => 'invented']]];
        yield 'missing explicit device' => [['source' => ['device_id' => null, 'attribution' => 'explicit']]];
        yield 'invalid inferred device' => [['source' => ['device_id' => 7, 'attribution' => 'inferred']]];
        yield 'unknown with device' => [['source' => ['device_id' => '019fd357-a466-7c65-b21d-936d55effd1a', 'attribution' => 'unknown']]];
    }

    /** @param array<string, mixed> $provenance */
    #[DataProvider('invalidProvenance')]
    public function testRejectsMalformedPresentProvenance(array $provenance): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ActivityScalarMeasurementReadRowMapper())->readings([$this->reading() + $provenance], 'Track');
    }

    /** @return array<string, mixed> */
    private function reading(): array
    {
        return ['measurement' => ['kind' => 'scalar', 'type' => 'heart_rate', 'value' => 140, 'unit' => 'bpm']];
    }

    private function scalar(string $type, int $value, string $unit): ScalarMeasurement
    {
        return new ScalarMeasurement(MeasurementType::fromString($type), $value, MeasurementUnit::fromSymbol($unit));
    }
}
