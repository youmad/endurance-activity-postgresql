<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Read;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityReadRowMapper;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class ActivityReadRowMapperTest extends TestCase
{
    /** @throws \JsonException */
    public function testMapsActivityAndAggregatesSessionDistance(): void
    {
        $mapper = new ActivityReadRowMapper();
        $activity = $mapper->activity(
            activityRow: [
                'id' => '019fd357-a466-7c65-b21d-936d55effd1a',
                'started_at' => '2026-08-05 10:00:00+00',
                'finished_at' => '2026-08-05 11:00:00+00',
                'activity_type' => 'cycling',
                'local_time_offset_seconds' => 10_800,
                'session_count' => 2,
                'recorded_session_timer_duration_microseconds' => 3_420_000_000,
                'accumulated_paused_duration_microseconds' => 180_000_000,
            ],
            sessionRows: [
                $this->sessionRow(
                    startedAt: '2026-08-05 10:00:00+00',
                    finishedAt: '2026-08-05 10:30:00+00',
                    timerMicroseconds: 1_710_000_000,
                    distance: 14_000.25,
                    startPosition: [59.4, 24.7],
                    endPosition: [59.45, 24.75],
                ),
                $this->sessionRow(
                    startedAt: '2026-08-05 10:30:00+00',
                    finishedAt: '2026-08-05 11:00:00+00',
                    timerMicroseconds: 1_710_000_000,
                    distance: 14_754.05,
                    startPosition: [59.45, 24.75],
                    endPosition: [59.5, 24.8],
                ),
            ],
        );

        self::assertSame('cycling', $activity->type);
        self::assertSame(10_800, $activity->localTimeOffsetSeconds);
        self::assertSame(2, $activity->sessionCount());
        self::assertSame(
            28_754.3,
            $activity->distance?->value,
        );
        self::assertSame('m', $activity->distance->unit);
        self::assertSame(
            59.4,
            $activity->sessions()[0]->startPosition?->latitude,
        );
        self::assertSame(
            24.8,
            $activity->sessions()[1]->endPosition?->longitude,
        );
        self::assertSame(
            TemporalResolution::Microsecond,
            $activity->sessions()[0]->timelineResolution,
        );
    }

    /** @throws \JsonException */
    public function testPreservesSessionTimelineResolutionForReadSideOverlap(): void
    {
        $mapper = new ActivityReadRowMapper();
        $activity = $mapper->activity(
            activityRow: [
                'id' => '019fd357-a466-7c65-b21d-936d55effd1a',
                'started_at' => '2026-08-05 10:00:00+00',
                'finished_at' => '2026-08-05 11:00:00+00',
                'activity_type' => 'running',
                'local_time_offset_seconds' => null,
                'session_count' => 2,
                'recorded_session_timer_duration_microseconds' => 3_400_000_000,
                'accumulated_paused_duration_microseconds' => 200_000_000,
            ],
            sessionRows: [
                $this->sessionRow(
                    startedAt: '2026-08-05 10:00:00+00',
                    finishedAt: '2026-08-05 10:30:00.595000+00',
                    timerMicroseconds: 1_700_000_000,
                    distance: 5_000.0,
                    startPosition: [59.4, 24.7],
                    endPosition: [59.45, 24.75],
                    timelineResolution: TemporalResolution::Second,
                ),
                $this->sessionRow(
                    startedAt: '2026-08-05 10:30:00+00',
                    finishedAt: '2026-08-05 11:00:00+00',
                    timerMicroseconds: 1_700_000_000,
                    distance: 5_000.0,
                    startPosition: [59.45, 24.75],
                    endPosition: [59.5, 24.8],
                    timelineResolution: TemporalResolution::Second,
                ),
            ],
        );

        self::assertSame(
            TemporalResolution::Second,
            $activity->sessions()[1]->timelineResolution,
        );
    }

    /**
     * @param array{0: float, 1: float} $startPosition
     * @param array{0: float, 1: float} $endPosition
     *
     * @return array{
     *     started_at: string,
     *     finished_at: string,
     *     timer_duration_microseconds: int,
     *     payload: string
     * }
     *
     * @throws \JsonException
     */
    private function sessionRow(
        string $startedAt,
        string $finishedAt,
        int $timerMicroseconds,
        float $distance,
        array $startPosition,
        array $endPosition,
        ?TemporalResolution $timelineResolution = null,
    ): array {
        $payload = [
            'sport' => 'cycling',
            'sub_sport' => 'road',
            'start_position' => [
                'latitude' => $startPosition[0],
                'longitude' => $startPosition[1],
            ],
            'end_position' => [
                'latitude' => $endPosition[0],
                'longitude' => $endPosition[1],
            ],
            'readings' => [
                [
                    'measurement' => [
                        'kind' => 'scalar',
                        'type' => 'total_distance',
                        'value' => $distance,
                        'unit' => 'm',
                    ],
                ],
            ],
        ];

        if (null !== $timelineResolution) {
            $payload['timeline_resolution_microseconds'] =
                $timelineResolution->value;
        }

        return [
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'timer_duration_microseconds' => $timerMicroseconds,
            'payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR,
            ),
        ];
    }
}
