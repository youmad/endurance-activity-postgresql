<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Activity;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Entity\Activity;
use Youmad\Endurance\Activity\Exception\InvalidActivitySnapshot;
use Youmad\Endurance\ActivityPostgresql\Activity\ActivityRowMapper;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class ActivityRowMapperTest extends TestCase
{
    public function testMapsStartedActivityInBothDirections(): void
    {
        $activity = Activity::start(
            $this->instant('2026-01-15T10:00:00.123456Z'),
        );
        $activity->pause(
            $this->instant('2026-01-15T10:01:00.654321Z'),
        );
        $mapper = new ActivityRowMapper();
        $parameters = $mapper->parameters($activity);

        $restored = $mapper->activity(
            $parameters + ['version' => 1],
        );

        self::assertTrue($activity->id->equals($restored->id));
        self::assertTrue(
            $activity->startedAt->equals($restored->startedAt),
        );
        self::assertTrue(
            $activity->pausedAt?->equals($restored->pausedAt),
        );
        self::assertSame(
            '{}',
            $parameters['last_sequential_detail_finished_at'],
        );
    }

    public function testPreservesDelayedTimerStartAndPauseAcrossRows(): void
    {
        $activity = Activity::start($this->instant('2026-01-15T10:00:00Z'));
        $activity->confirmTimerStartedAt($this->instant('2026-01-15T10:00:03Z'));
        $activity->pause($this->instant('2026-01-15T10:00:20Z'));
        $mapper = new ActivityRowMapper();
        $parameters = $mapper->parameters($activity);
        $restored = $mapper->activity($parameters + ['version' => 1]);
        $restored->resume($this->instant('2026-01-15T10:00:25Z'));
        $restored->finish($this->instant('2026-01-15T10:01:00Z'));

        self::assertSame('2026-01-15T10:00:03.000000+00:00', $parameters['timer_started_at']);
        self::assertTrue($restored->timerStartedAt?->equals($activity->timerStartedAt));
        self::assertSame(52_000_000, $restored->timerDuration()?->toMicroseconds());
        self::assertSame(5_000_000, $restored->accumulatedPausedDuration->toMicroseconds());
    }

    public function testOldRowsDoNotInventTimerStarts(): void
    {
        $activity = Activity::start($this->instant('2026-01-15T10:00:00Z'));
        $mapper = new ActivityRowMapper();
        $parameters = $mapper->parameters($activity);
        unset($parameters['timer_started_at']);
        self::assertNull($mapper->activity($parameters + ['version' => 1])->timerStartedAt);
    }

    public function testCannotRestoreTimerBeforeActivity(): void
    {
        $activity = Activity::start($this->instant('2026-01-15T10:00:00Z'));
        $mapper = new ActivityRowMapper();
        $parameters = $mapper->parameters($activity);
        $parameters['timer_started_at'] = '2026-01-15T09:59:59Z';
        $this->expectException(InvalidActivitySnapshot::class);
        $mapper->activity($parameters + ['version' => 1]);
    }

    public function testCannotRestoreTimerAfterLatestEvent(): void
    {
        $activity = Activity::start($this->instant('2026-01-15T10:00:00Z'));
        $mapper = new ActivityRowMapper();
        $parameters = $mapper->parameters($activity);
        $parameters['timer_started_at'] = '2026-01-15T10:00:01Z';
        $this->expectException(InvalidActivitySnapshot::class);
        $mapper->activity($parameters + ['version' => 1]);
    }

    public function testCannotRestorePausedDurationLongerThanTimerInterval(): void
    {
        $activity = Activity::start($this->instant('2026-01-15T10:00:00Z'));
        $activity->finish($this->instant('2026-01-15T10:00:10Z'));
        $mapper = new ActivityRowMapper();
        $parameters = $mapper->parameters($activity);
        $parameters['timer_started_at'] = '2026-01-15T10:00:05Z';
        $parameters['accumulated_paused_duration_microseconds'] = 6_000_000;
        $this->expectException(InvalidActivitySnapshot::class);
        $mapper->activity($parameters + ['version' => 1]);
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
