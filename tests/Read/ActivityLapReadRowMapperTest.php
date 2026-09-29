<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Read;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityLapReadRowMapper;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class ActivityLapReadRowMapperTest extends TestCase
{
    /** @throws \JsonException */
    public function testMapsOrderedLapsAndOptionalDistance(): void
    {
        $id = ActivityId::fromString(
            '019fd357-a466-7c65-b21d-936d55effd1a',
        );
        $laps = (new ActivityLapReadRowMapper())->laps(
            activityId: $id,
            rows: [
                $this->row(
                    startedAt: '2026-08-05 10:00:00+00',
                    finishedAt: '2026-08-05 10:30:00+00',
                    timerMicroseconds: 1_710_000_000,
                    distance: 14_000.25,
                    timelineResolution: TemporalResolution::Second,
                ),
                $this->row(
                    startedAt: '2026-08-05 10:30:00+00',
                    finishedAt: '2026-08-05 11:00:00+00',
                    timerMicroseconds: 1_700_000_000,
                    distance: null,
                ),
            ],
        );

        self::assertTrue($id->equals($laps->activityId));
        self::assertSame(2, $laps->count());
        self::assertSame(
            1_800_000_000,
            $laps->laps()[0]->elapsedDuration()->toMicroseconds(),
        );
        self::assertSame(
            14_000.25,
            $laps->laps()[0]->distance?->value,
        );
        self::assertSame('m', $laps->laps()[0]->distance->unit);
        self::assertSame(
            TemporalResolution::Second,
            $laps->laps()[0]->timelineResolution,
        );
        self::assertNull($laps->laps()[1]->distance);
    }

    public function testPreservesTimerLongerThanLapElapsedOnRead(): void
    {
        $laps = (new ActivityLapReadRowMapper())->laps(
            activityId: ActivityId::generate(),
            rows: [$this->row(
                startedAt: '2026-08-05 10:00:00+00',
                finishedAt: '2026-08-05 10:01:59+00',
                timerMicroseconds: 120_000_000,
                distance: null,
                timelineResolution: TemporalResolution::Second,
            )],
        );

        self::assertSame(119_000_000, $laps->laps()[0]->elapsedDuration()->toMicroseconds());
        self::assertSame(120_000_000, $laps->laps()[0]->timerDuration->toMicroseconds());
    }

    /**
     * @return array{
     *     started_at: string,
     *     finished_at: string,
     *     timer_duration_microseconds: int,
     *     payload: string
     * }
     *
     * @throws \JsonException
     */
    private function row(
        string $startedAt,
        string $finishedAt,
        int $timerMicroseconds,
        ?float $distance,
        TemporalResolution $timelineResolution =
            TemporalResolution::Microsecond,
    ): array {
        $readings = [];

        if (null !== $distance) {
            $readings[] = [
                'measurement' => [
                    'kind' => 'scalar',
                    'type' => 'total_distance',
                    'value' => $distance,
                    'unit' => 'm',
                ],
            ];
        }

        return [
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'timer_duration_microseconds' => $timerMicroseconds,
            'payload' => json_encode(
                [
                    'timeline_resolution_microseconds' => $timelineResolution->value,
                    'readings' => $readings,
                ],
                JSON_THROW_ON_ERROR,
            ),
        ];
    }
}
