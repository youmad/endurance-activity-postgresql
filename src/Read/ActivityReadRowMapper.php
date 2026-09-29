<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Read;

use Youmad\Endurance\Activity\Application\Read\ActivityDistance;
use Youmad\Endurance\Activity\Application\Read\ActivityReadModel;
use Youmad\Endurance\Activity\Application\Read\ActivityScalarMeasurement;
use Youmad\Endurance\Activity\Application\Read\ActivitySessionReadModel;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class ActivityReadRowMapper
{
    public function __construct(
        private ActivityScalarMeasurementReadRowMapper $scalarMeasurements =
            new ActivityScalarMeasurementReadRowMapper(),
    ) {
    }

    /**
     * @param array{
     *     id: string,
     *     started_at: string,
     *     finished_at: ?string,
     *     activity_type: ?string,
     *     local_time_offset_seconds: int|string|null,
     *     session_count: int|string,
     *     recorded_session_timer_duration_microseconds: int|string,
     *     accumulated_paused_duration_microseconds: int|string
     * } $activityRow
     * @param list<array{
     *     started_at: string,
     *     finished_at: string,
     *     timer_duration_microseconds: int|string,
     *     payload: string
     * }> $sessionRows
     */
    public function activity(
        array $activityRow,
        array $sessionRows,
    ): ActivityReadModel {
        $declaredSessionCount = (int) $activityRow['session_count'];

        if ($declaredSessionCount !== count($sessionRows)) {
            throw new \UnexpectedValueException(sprintf('Activity declares %d sessions, but %d active sessions were found.', $declaredSessionCount, count($sessionRows)));
        }

        $sessions = [];

        foreach ($sessionRows as $index => $sessionRow) {
            $sessions[] = $this->session($index, $sessionRow);
        }

        return new ActivityReadModel(
            id: ActivityId::fromString($activityRow['id']),
            startedAt: $this->instant($activityRow['started_at']),
            finishedAt: $this->optionalInstant(
                $activityRow['finished_at'],
            ),
            type: $activityRow['activity_type'],
            localTimeOffsetSeconds: null === (
                $activityRow['local_time_offset_seconds'] ?? null
            )
                ? null
                : (int) $activityRow['local_time_offset_seconds'],
            timerDuration: Duration::fromMicroseconds(
                (int) $activityRow[
                    'recorded_session_timer_duration_microseconds'
                ],
            ),
            pausedDuration: Duration::fromMicroseconds(
                (int) $activityRow[
                    'accumulated_paused_duration_microseconds'
                ],
            ),
            distance: $this->totalDistance($sessions),
            sessions: $sessions,
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
    private function session(
        int $index,
        array $row,
    ): ActivitySessionReadModel {
        $payload = $this->payload($row['payload']);
        $measurements = $this->scalarMeasurements->map(
            readings: $payload['readings'] ?? null,
            subject: 'Activity session',
        );

        $resolution = $this->timelineResolution($payload);

        return new ActivitySessionReadModel(
            index: $index,
            startedAt: $this->instant($row['started_at']),
            finishedAt: $this->instant($row['finished_at']),
            timerDuration: Duration::fromMicroseconds(
                (int) $row['timer_duration_microseconds'],
            ),
            sport: $this->optionalString($payload, 'sport'),
            subSport: $this->optionalString($payload, 'sub_sport'),
            startPosition: $this->coordinate(
                $payload,
                'start_position',
            ),
            endPosition: $this->coordinate(
                $payload,
                'end_position',
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
     * @param list<ActivitySessionReadModel> $sessions
     */
    private function totalDistance(array $sessions): ?ActivityDistance
    {
        if ([] === $sessions) {
            return null;
        }

        $firstDistance = $sessions[0]->distance;

        if (null === $firstDistance) {
            return null;
        }

        $value = 0.0;
        $unit = $firstDistance->unit;

        foreach ($sessions as $session) {
            $distance = $session->distance;

            if (
                null === $distance
                || $unit !== $distance->unit
            ) {
                return null;
            }

            $value += $distance->value;
        }

        return new ActivityDistance(
            value: $value,
            unit: $unit,
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
            throw new \UnexpectedValueException('Activity session payload must be a JSON object.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(
        array $payload,
        string $field,
    ): ?string {
        $value = $payload[$field] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Activity session field %s must be a string or null.', $field));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function coordinate(
        array $payload,
        string $field,
    ): ?Coordinate {
        $value = $payload[$field] ?? null;

        if (null === $value) {
            return null;
        }

        if (
            !is_array($value)
            || !array_key_exists('latitude', $value)
            || !array_key_exists('longitude', $value)
            || !is_int($value['latitude'])
                && !is_float($value['latitude'])
            || !is_int($value['longitude'])
                && !is_float($value['longitude'])
        ) {
            throw new \UnexpectedValueException(sprintf('Activity session field %s must contain a coordinate.', $field));
        }

        return new Coordinate(
            latitude: (float) $value['latitude'],
            longitude: (float) $value['longitude'],
        );
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

    /**
     * @param array<string, mixed> $payload
     */
    private function timelineResolution(
        array $payload,
    ): TemporalResolution {
        $value = $payload['timeline_resolution_microseconds']
            ?? TemporalResolution::Microsecond->value;

        if (!is_int($value)) {
            throw new \UnexpectedValueException('Activity session timeline resolution must be an integer.');
        }

        $resolution = TemporalResolution::tryFrom($value);

        if (null === $resolution) {
            throw new \UnexpectedValueException(sprintf('Unsupported activity session timeline resolution %d microseconds.', $value));
        }

        return $resolution;
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }

    private function optionalInstant(?string $value): ?Instant
    {
        return null === $value
            ? null
            : $this->instant($value);
    }
}
