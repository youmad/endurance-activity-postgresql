<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Activity;

use Youmad\Endurance\Activity\Entity\Activity;
use Youmad\Endurance\Activity\Entity\ActivitySnapshot;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class ActivityRowMapper
{
    /**
     * @param array{
     *     id: string,
     *     started_at: string,
     *     finished_at: ?string,
     *     last_observation_at: ?string,
     *     last_lap_finished_at: ?string,
     *     last_session_finished_at: ?string,
     *     last_session_timeline_finished_at: ?string,
     *     last_detail_finished_at: ?string,
     *     last_sequential_detail_finished_at: string,
     *     session_count: int|string,
     *     recorded_session_timer_duration_microseconds: int|string,
     *     summary_reported_at: ?string,
     *     activity_type: ?string,
     *     local_time_offset_seconds: int|string|null,
     *     paused_at: ?string,
     *     accumulated_paused_duration_microseconds: int|string,
     *     latest_timestamp: string,
     *     version: int|string
     * } $row
     */
    public function activity(array $row): Activity
    {
        return Activity::restore(
            ActivitySnapshot::create(
                id: ActivityId::fromString($row['id']),
                startedAt: $this->requiredInstant(
                    $row['started_at'],
                ),
                finishedAt: $this->optionalInstant(
                    $row['finished_at'],
                ),
                lastObservationAt: $this->optionalInstant(
                    $row['last_observation_at'],
                ),
                lastLapFinishedAt: $this->optionalInstant(
                    $row['last_lap_finished_at'],
                ),
                lastSessionFinishedAt: $this->optionalInstant(
                    $row['last_session_finished_at'],
                ),
                lastSessionTimelineFinishedAt: $this->optionalInstant(
                    $row['last_session_timeline_finished_at'],
                ),
                lastDetailFinishedAt: $this->optionalInstant(
                    $row['last_detail_finished_at'],
                ),
                lastSequentialDetailFinishedAt: $this->sequentialDetailTimestamps(
                    $row[
                        'last_sequential_detail_finished_at'
                    ],
                ),
                sessionCount: (int) $row['session_count'],
                recordedSessionTimerDuration: Duration::fromMicroseconds(
                    (int) $row[
                        'recorded_session_timer_duration_microseconds'
                    ],
                ),
                summaryReportedAt: $this->optionalInstant(
                    $row['summary_reported_at'],
                ),
                type: $row['activity_type'],
                localTimeOffsetSeconds: null === (
                    $row['local_time_offset_seconds'] ?? null
                )
                    ? null
                    : (int) $row['local_time_offset_seconds'],
                pausedAt: $this->optionalInstant(
                    $row['paused_at'],
                ),
                accumulatedPausedDuration: Duration::fromMicroseconds(
                    (int) $row[
                        'accumulated_paused_duration_microseconds'
                    ],
                ),
                latestTimestamp: $this->requiredInstant(
                    $row['latest_timestamp'],
                ),
            ),
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function parameters(Activity $activity): array
    {
        $snapshot = $activity->snapshot();

        return [
            'id' => $snapshot->id->toString(),
            'started_at' => $this->instant($snapshot->startedAt),
            'finished_at' => $this->nullableInstant(
                $snapshot->finishedAt,
            ),
            'last_observation_at' => $this->nullableInstant(
                $snapshot->lastObservationAt,
            ),
            'last_lap_finished_at' => $this->nullableInstant(
                $snapshot->lastLapFinishedAt,
            ),
            'last_session_finished_at' => $this->nullableInstant(
                $snapshot->lastSessionFinishedAt,
            ),
            'last_session_timeline_finished_at' => $this->nullableInstant(
                $snapshot->lastSessionTimelineFinishedAt,
            ),
            'last_detail_finished_at' => $this->nullableInstant(
                $snapshot->lastDetailFinishedAt,
            ),
            'last_sequential_detail_finished_at' => $this->sequentialDetailJson(
                $snapshot->lastSequentialDetailFinishedAt,
            ),
            'session_count' => $snapshot->sessionCount,
            'recorded_session_timer_duration_microseconds' => $snapshot
                    ->recordedSessionTimerDuration
                    ->toMicroseconds(),
            'summary_reported_at' => $this->nullableInstant(
                $snapshot->summaryReportedAt,
            ),
            'activity_type' => $snapshot->type,
            'local_time_offset_seconds' => $snapshot->localTimeOffsetSeconds,
            'paused_at' => $this->nullableInstant(
                $snapshot->pausedAt,
            ),
            'accumulated_paused_duration_microseconds' => $snapshot
                    ->accumulatedPausedDuration
                    ->toMicroseconds(),
            'latest_timestamp' => $this->instant(
                $snapshot->latestTimestamp,
            ),
        ];
    }

    /**
     * @param array<string, Instant> $timestamps
     *
     * @throws \JsonException
     */
    private function sequentialDetailJson(array $timestamps): string
    {
        $encoded = [];

        foreach ($timestamps as $sequenceName => $timestamp) {
            $encoded[$sequenceName] = $this->instant($timestamp);
        }

        ksort($encoded);

        return json_encode(
            (object) $encoded,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @return array<string, Instant>
     *
     * @throws \JsonException
     */
    private function sequentialDetailTimestamps(string $json): array
    {
        $decoded = json_decode(
            $json,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (!is_array($decoded)) {
            throw new \JsonException('Activity sequential detail state must be a JSON object.');
        }

        $timestamps = [];

        foreach ($decoded as $sequenceName => $timestamp) {
            if (!is_string($sequenceName) || !is_string($timestamp)) {
                throw new \JsonException('Activity sequential detail state must map names to timestamps.');
            }

            $timestamps[$sequenceName] = $this->requiredInstant(
                $timestamp,
            );
        }

        return $timestamps;
    }

    private function requiredInstant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }

    private function optionalInstant(?string $value): ?Instant
    {
        return null === $value
            ? null
            : $this->requiredInstant($value);
    }

    private function instant(Instant $value): string
    {
        return $value
            ->toDateTimeImmutable()
            ->format('Y-m-d\\TH:i:s.uP');
    }

    private function nullableInstant(?Instant $value): ?string
    {
        return null === $value
            ? null
            : $this->instant($value);
    }
}
