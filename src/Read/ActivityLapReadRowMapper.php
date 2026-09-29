<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Read;

use Youmad\Endurance\Activity\Application\Read\ActivityDistance;
use Youmad\Endurance\Activity\Application\Read\ActivityLapReadModel;
use Youmad\Endurance\Activity\Application\Read\ActivityLapsReadModel;
use Youmad\Endurance\Activity\Application\Read\ActivityScalarMeasurement;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class ActivityLapReadRowMapper
{
    public function __construct(
        private ActivityScalarMeasurementReadRowMapper $scalarMeasurements =
            new ActivityScalarMeasurementReadRowMapper(),
    ) {
    }

    /**
     * @param list<array{
     *     started_at: string,
     *     finished_at: string,
     *     timer_duration_microseconds: int|string,
     *     payload: string
     * }> $rows
     */
    public function laps(
        ActivityId $activityId,
        array $rows,
    ): ActivityLapsReadModel {
        $laps = [];

        foreach ($rows as $index => $row) {
            $laps[] = $this->lap($index, $row);
        }

        return new ActivityLapsReadModel(
            activityId: $activityId,
            laps: $laps,
        );
    }

    /**
     * @param array{
     *     started_at: string,
     *     finished_at: string,
     *     timer_duration_microseconds: int|string,
     *     payload: string
     * } $row
     */
    private function lap(
        int $index,
        array $row,
    ): ActivityLapReadModel {
        $payload = $this->payload($row['payload']);
        $measurements = $this->scalarMeasurements->map(
            readings: $payload['readings'] ?? null,
            subject: 'Activity lap',
        );

        $resolution = $this->timelineResolution($payload);

        return new ActivityLapReadModel(
            index: $index,
            startedAt: $this->instant($row['started_at']),
            finishedAt: $this->instant($row['finished_at']),
            timerDuration: Duration::fromMicroseconds(
                (int) $row['timer_duration_microseconds'],
            ),
            distance: $this->distance($measurements),
            timelineResolution: $resolution,
            adjacencyPolicy: SummaryAdjacencyPolicyReadMapper::fromPayload(
                $payload,
                $resolution,
            ),
            measurements: array_values($measurements),
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

        if (
            !is_array($payload)
            || array_is_list($payload)
        ) {
            throw new \UnexpectedValueException('Activity lap payload must be a JSON object.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function timelineResolution(array $payload): TemporalResolution
    {
        $value = $payload['timeline_resolution_microseconds']
            ?? TemporalResolution::Microsecond->value;

        if (!is_int($value)) {
            throw new \UnexpectedValueException('Activity lap timeline resolution must be an integer.');
        }

        return TemporalResolution::tryFrom($value)
            ?? throw new \UnexpectedValueException(sprintf('Activity lap timeline resolution %d is unsupported.', $value));
    }

    /**
     * @param array<string, ActivityScalarMeasurement> $measurements
     */
    private function distance(array $measurements): ?ActivityDistance
    {
        $distance = $measurements['total_distance'] ?? null;

        if (null === $distance) {
            return null;
        }

        return new ActivityDistance(
            value: (float) $distance->value,
            unit: $distance->unit,
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
