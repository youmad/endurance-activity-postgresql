<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Read;

use Youmad\Endurance\Activity\Application\Read\ActivityTrackCursor;
use Youmad\Endurance\Activity\Application\Read\ActivityTrackPage;
use Youmad\Endurance\Activity\Application\Read\ActivityTrackPointReadModel;
use Youmad\Endurance\Activity\Application\Read\UnambiguousScalarMeasurements;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class ActivityTrackReadRowMapper
{
    public function __construct(
        private ActivityScalarMeasurementReadRowMapper $scalarMeasurements =
            new ActivityScalarMeasurementReadRowMapper(),
    ) {
    }

    /**
     * @param list<array{
     *     id: int|string,
     *     observed_at: string,
     *     payload: string
     * }> $rows
     */
    public function page(
        ActivityId $activityId,
        array $rows,
        int $limit,
    ): ActivityTrackPage {
        if (1 > $limit) {
            throw new \UnexpectedValueException('Activity track mapper limit must be positive.');
        }

        if (count($rows) > $limit + 1) {
            throw new \UnexpectedValueException('Activity track page contains more rows than requested.');
        }

        $hasMore = count($rows) > $limit;
        $visibleRows = $hasMore
            ? array_slice($rows, 0, $limit)
            : $rows;
        $points = array_map(
            $this->point(...),
            $visibleRows,
        );

        return new ActivityTrackPage(
            activityId: $activityId,
            points: $points,
            nextCursor: $hasMore
                ? $points[array_key_last($points)]->cursor
                : null,
        );
    }

    /**
     * @param array{
     *     id: int|string,
     *     observed_at: string,
     *     payload: string
     * } $row
     */
    private function point(array $row): ActivityTrackPointReadModel
    {
        $timestamp = $this->instant($row['observed_at']);
        $payload = $this->payload($row['payload']);
        $payloadTimestamp = $payload['timestamp'] ?? null;

        if (!is_string($payloadTimestamp)) {
            throw new \UnexpectedValueException('Activity observation payload must contain a timestamp.');
        }

        if (!$timestamp->equals($this->instant($payloadTimestamp))) {
            throw new \UnexpectedValueException('Activity observation payload timestamp does not match its row timestamp.');
        }

        $readings = $payload['readings'] ?? null;

        if (!is_array($readings) || !array_is_list($readings)) {
            throw new \UnexpectedValueException('Activity observation readings must be a JSON array.');
        }

        if ([] === $readings) {
            throw new \UnexpectedValueException('Activity observation must contain at least one reading.');
        }

        $position = null;
        $measurements = $this->scalarMeasurements->readings(
            readings: $readings,
            subject: 'Activity observation',
        );

        $unique = UnambiguousScalarMeasurements::byType($measurements);

        foreach ($readings as $reading) {
            if (!is_array($reading) || array_is_list($reading)) {
                throw new \UnexpectedValueException('Activity observation reading must be a JSON object.');
            }

            $measurement = $reading['measurement'] ?? null;

            if (!is_array($measurement) || array_is_list($measurement)) {
                throw new \UnexpectedValueException('Activity observation reading must contain a measurement.');
            }

            $type = $measurement['type'] ?? null;

            if ('position' === $type) {
                if (null !== $position) {
                    throw new \UnexpectedValueException('Activity observation contains more than one position measurement.');
                }

                $position = $this->position($measurement);

                continue;
            }
        }

        return new ActivityTrackPointReadModel(
            cursor: new ActivityTrackCursor(
                observedAt: $timestamp,
                observationId: $this->positiveInteger($row['id']),
            ),
            timestamp: $timestamp,
            position: $position,
            altitude: $unique['altitude'] ?? null,
            distance: $unique['distance'] ?? null,
            speed: $unique['speed'] ?? null,
            heartRate: $unique['heart_rate'] ?? null,
            cadence: $unique['cadence'] ?? null,
            power: $unique['power'] ?? null,
            temperature: $unique['temperature'] ?? null,
            measurements: $measurements,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    private function payload(string $json): array
    {
        $payload = json_decode(
            $json,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (!is_array($payload) || array_is_list($payload)) {
            throw new \UnexpectedValueException('Activity observation payload must be a JSON object.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $measurement
     */
    private function position(array $measurement): Coordinate
    {
        $coordinate = $measurement['coordinate'] ?? null;

        if (
            'position' !== ($measurement['kind'] ?? null)
            || !is_array($coordinate)
            || array_is_list($coordinate)
        ) {
            throw new \UnexpectedValueException('Activity observation position must be a position measurement.');
        }

        $latitude = $coordinate['latitude'] ?? null;
        $longitude = $coordinate['longitude'] ?? null;

        if (
            !is_int($latitude) && !is_float($latitude)
            || !is_int($longitude) && !is_float($longitude)
        ) {
            throw new \UnexpectedValueException('Activity observation position must contain numeric coordinates.');
        }

        return new Coordinate(
            latitude: (float) $latitude,
            longitude: (float) $longitude,
        );
    }

    private function positiveInteger(int|string $value): int
    {
        if (is_int($value)) {
            if (1 > $value) {
                throw new \UnexpectedValueException('Activity observation ID must be positive.');
            }

            return $value;
        }

        if (1 !== preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new \UnexpectedValueException('Activity observation ID must be a positive integer.');
        }

        $integer = (int) $value;

        if ((string) $integer !== $value) {
            throw new \UnexpectedValueException('Activity observation ID exceeds the supported integer range.');
        }

        return $integer;
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
