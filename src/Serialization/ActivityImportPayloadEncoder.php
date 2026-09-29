<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Serialization;

use Youmad\Endurance\Activity\Detail\ActivityDetail;
use Youmad\Endurance\Activity\Detail\ActivityInterval;
use Youmad\Endurance\Activity\Detail\Pool\PoolLength;
use Youmad\Endurance\Activity\Detail\Segment\SegmentEffort;
use Youmad\Endurance\Activity\Detail\SequentialActivityDetail;
use Youmad\Endurance\Activity\Device\ActivityDevice;
use Youmad\Endurance\Activity\Device\DeviceStatusObservation;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\ArrayMeasurement;
use Youmad\Endurance\Activity\Telemetry\Measurement;
use Youmad\Endurance\Activity\Telemetry\MeasurementMetadata;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\PositionMeasurement;
use Youmad\Endurance\Activity\Telemetry\RangeMeasurement;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\TextMeasurement;
use Youmad\Endurance\Activity\Telemetry\Vector3Measurement;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityImportPersistenceException;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class ActivityImportPayloadEncoder
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    public function observation(
        ActivityObservation $observation,
    ): string {
        return $this->json([
            'timestamp' => $this->instant($observation->timestamp),
            'readings' => array_map(
                $this->reading(...),
                $observation->readings(),
            ),
        ]);
    }

    public function deviceStatus(
        DeviceStatusObservation $observation,
    ): string {
        return $this->json([
            'device_id' => $observation->deviceId->toString(),
            'observed_at' => null === $observation->observedAt
                ? null
                : $this->instant($observation->observedAt),
            'measurements' => array_map(
                $this->measurement(...),
                $observation->measurements(),
            ),
        ]);
    }

    public function lap(Lap $lap): string
    {
        return $this->json([
            'started_at' => $this->instant($lap->startedAt),
            'finished_at' => $this->instant($lap->finishedAt),
            'timer_duration_microseconds' => $lap->timerDuration->toMicroseconds(),
            'elapsed_duration_microseconds' => $lap->elapsedDuration()->toMicroseconds(),
            'timeline_resolution_microseconds' => $lap->timelineResolution->value,
            'adjacency_policy' => $lap->adjacencyPolicy->value,
            'readings' => array_map(
                $this->reading(...),
                $lap->readings(),
            ),
        ]);
    }

    public function session(ActivitySession $session): string
    {
        return $this->json([
            'started_at' => $this->instant($session->startedAt),
            'finished_at' => $this->instant($session->finishedAt),
            'timer_duration_microseconds' => $session->timerDuration->toMicroseconds(),
            'elapsed_duration_microseconds' => $session->elapsedDuration()->toMicroseconds(),
            'timeline_resolution_microseconds' => $session->timelineResolution->value,
            'adjacency_policy' => $session->adjacencyPolicy->value,
            'sport' => $session->sport,
            'sub_sport' => $session->subSport,
            'start_position' => $this->coordinate(
                $session->startPosition,
            ),
            'end_position' => $this->coordinate(
                $session->endPosition,
            ),
            'readings' => array_map(
                $this->reading(...),
                $session->readings(),
            ),
        ]);
    }

    public function detail(ActivityDetail $detail): string
    {
        $interval = $detail->interval();

        return $this->json(
            match (true) {
                $detail instanceof PoolLength => [
                    'detail_type' => $this->detailType($detail),
                    'sequence_name' => $detail->sequenceName(),
                    'timeline_resolution_microseconds' => $detail->timelineResolution()->value,
                    'interval' => $this->interval($interval),
                    'length_type' => $detail->type->value,
                    'stroke' => $detail->stroke,
                    'readings' => array_map(
                        $this->reading(...),
                        $detail->readings(),
                    ),
                ],
                $detail instanceof SegmentEffort => [
                    'detail_type' => $this->detailType($detail),
                    'sequence_name' => null,
                    'interval' => $this->interval($interval),
                    'segment_id' => $detail->segmentId,
                    'name' => $detail->name,
                    'status' => $detail->status,
                    'sport' => $detail->sport,
                    'sub_sport' => $detail->subSport,
                    'manufacturer' => $detail->manufacturer,
                    'start_position' => $this->coordinate(
                        $detail->startPosition,
                    ),
                    'end_position' => $this->coordinate(
                        $detail->endPosition,
                    ),
                    'readings' => array_map(
                        $this->reading(...),
                        $detail->readings(),
                    ),
                ],
                default => throw ActivityImportPersistenceException::unsupportedPayload($detail),
            },
        );
    }

    public function device(ActivityDevice $device): string
    {
        $descriptor = $device->descriptor;

        return $this->json([
            'device_id' => $device->id->toString(),
            'descriptor' => null === $descriptor
                ? null
                : [
                    'manufacturer' => $descriptor->manufacturer,
                    'product' => $descriptor->product,
                    'serial_number' => $descriptor->serialNumber,
                    'product_name' => $descriptor->productName,
                    'description' => $descriptor->description,
                ],
        ]);
    }

    public function instant(Instant $instant): string
    {
        return $instant
            ->toDateTimeImmutable()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @return non-empty-string
     */
    public function detailType(ActivityDetail $detail): string
    {
        return match (true) {
            $detail instanceof PoolLength => 'pool_length',
            $detail instanceof SegmentEffort => 'segment_effort',
            default => throw ActivityImportPersistenceException::unsupportedPayload($detail),
        };
    }

    public function sequenceName(
        ActivityDetail $detail,
    ): ?string {
        if (!$detail instanceof SequentialActivityDetail) {
            return null;
        }

        return $detail->sequenceName();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                self::JSON_FLAGS,
            );
        } catch (\JsonException $exception) {
            throw ActivityImportPersistenceException::operationFailed(operation: 'encode activity import payload', previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function interval(ActivityInterval $interval): array
    {
        return [
            'started_at' => $this->instant($interval->startedAt),
            'finished_at' => $this->instant($interval->finishedAt),
            'timer_duration_microseconds' => $interval->timerDuration->toMicroseconds(),
            'elapsed_duration_microseconds' => $interval->elapsedDuration()->toMicroseconds(),
        ];
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function coordinate(?Coordinate $coordinate): ?array
    {
        if (null === $coordinate) {
            return null;
        }

        return [
            'latitude' => $coordinate->latitude,
            'longitude' => $coordinate->longitude,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reading(MeasurementReading $reading): array
    {
        return [
            'measurement' => $this->measurement(
                $reading->measurement,
            ),
            'origin' => $reading->origin->value,
            'source' => [
                'device_id' => null === $reading->source->deviceId
                    ? null
                    : $reading->source->deviceId->toString(),
                'attribution' => $reading->source->attribution->value,
            ],
            'metadata' => $this->metadata($reading->metadata),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function measurement(Measurement $measurement): array
    {
        $type = $measurement->type()->toString();

        return match (true) {
            $measurement instanceof ScalarMeasurement => [
                'kind' => 'scalar',
                'type' => $type,
                'value' => $measurement->value,
                'unit' => $measurement->unit->toString(),
            ],
            $measurement instanceof ArrayMeasurement => [
                'kind' => 'array',
                'type' => $type,
                'values' => $measurement->values(),
                'unit' => $measurement->unit->toString(),
            ],
            $measurement instanceof RangeMeasurement => [
                'kind' => 'range',
                'type' => $type,
                'minimum' => $measurement->minimum,
                'maximum' => $measurement->maximum,
                'unit' => $measurement->unit->toString(),
            ],
            $measurement instanceof PositionMeasurement => [
                'kind' => 'position',
                'type' => $type,
                'coordinate' => $this->coordinate(
                    $measurement->coordinate,
                ),
            ],
            $measurement instanceof TextMeasurement => [
                'kind' => 'text',
                'type' => $type,
                'value' => $measurement->value,
            ],
            $measurement instanceof Vector3Measurement => [
                'kind' => 'vector3',
                'type' => $type,
                'x' => $measurement->x,
                'y' => $measurement->y,
                'z' => $measurement->z,
                'unit' => $measurement->unit->toString(),
            ],
            default => throw ActivityImportPersistenceException::unsupportedPayload($measurement),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function metadata(
        ?MeasurementMetadata $metadata,
    ): ?array {
        if (null === $metadata) {
            return null;
        }

        $type = $metadata->metadataType();
        $version = $metadata->metadataVersion();
        $data = $metadata->metadataData();

        // @phpstan-ignore greater.alwaysFalse (Reject metadata that violates the positive version contract at runtime.)
        if ('' === trim($type) || 1 > $version) {
            throw ActivityImportPersistenceException::inconsistentState('Metadata requires a non-empty type and a positive version.');
        }

        foreach ($data as $key => $value) {
            // @phpstan-ignore function.alreadyNarrowedType (Reject numeric keys that violate the metadata PHPDoc.)
            if (!is_string($key)) {
                throw ActivityImportPersistenceException::inconsistentState('Metadata data must use named fields.');
            }

            $this->assertMetadataValue($value);
        }

        return [
            'type' => $type,
            'version' => $version,
            'data' => $data,
        ];
    }

    private function assertMetadataValue(mixed $value, int $depth = 0): void
    {
        if (64 < $depth) {
            throw ActivityImportPersistenceException::inconsistentState('Metadata nesting exceeds 64 levels.');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertMetadataValue($item, $depth + 1);
            }

            return;
        }

        if (
            null === $value
            || is_bool($value)
            || is_int($value)
            || is_string($value)
            || (is_float($value) && is_finite($value))
        ) {
            return;
        }

        throw ActivityImportPersistenceException::inconsistentState(sprintf('Unsupported metadata value of type %s.', get_debug_type($value)));
    }
}
